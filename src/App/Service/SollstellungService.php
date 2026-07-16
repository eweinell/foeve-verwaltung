<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Forderungsstatus;
use App\Domain\Mitgliedsstatus;
use App\Repository\ForderungRepository;
use App\Support\Db;

/**
 * Sollstellung & offene Posten (F4). Erzeugt je aktivem Mitglied und Jahr eine
 * Beitragsforderung (idempotent). Forderungen sind unveränderlich: Korrekturen
 * erfolgen per Storno, nie per Löschung (CLAUDE.md Regel 2).
 */
final class SollstellungService
{
    public function __construct(
        private readonly Db $db,
        private readonly ForderungRepository $forderungen,
        private readonly Audit $audit,
    ) {
    }

    /**
     * Mitglieder, die für das Jahr eine Beitragsforderung erhalten würden
     * (aktiv, oder gekündigt/ausgeschieden mit Wirksamkeit ≥ Jahr) und noch keine
     * haben. Grundlage für Vorschau und Ausführung.
     *
     * @return array{zeilen:array<int,array<string,mixed>>,summe:string,anzahl:int}
     */
    public function vorschau(int $jahr): array
    {
        $jahrStart = sprintf('%04d-01-01', $jahr);

        $zeilen = $this->db->alleZeilen(
            "SELECT m.* FROM mitglied m
              WHERE (
                    m.status = :aktiv
                 OR (m.status IN (:gek, :aus) AND COALESCE(m.wirksam_zum, m.austrittsdatum) >= :jahrstart)
              )
                AND NOT EXISTS (
                    SELECT 1 FROM forderung f
                     WHERE f.mitglied_id = m.id AND f.jahr = :jahr AND f.typ = 'beitrag' AND f.status <> 'storniert'
                )
              ORDER BY m.nachname ASC",
            [
                'aktiv'     => Mitgliedsstatus::AKTIV,
                'gek'       => Mitgliedsstatus::GEKUENDIGT,
                'aus'       => Mitgliedsstatus::AUSGESCHIEDEN,
                'jahrstart' => $jahrStart,
                'jahr'      => $jahr,
            ],
        );

        $summe = 0.0;
        foreach ($zeilen as $z) {
            $summe += (float) $z['jahresbeitrag'];
        }

        return ['zeilen' => $zeilen, 'summe' => number_format($summe, 2, '.', ''), 'anzahl' => count($zeilen)];
    }

    /**
     * Führt die Sollstellung aus: erzeugt fehlende Beitragsforderungen.
     *
     * @return int Anzahl neu erzeugter Forderungen
     */
    public function ausfuehren(int $jahr, ?int $benutzerId): int
    {
        $vorschau = $this->vorschau($jahr);
        $anzahl = 0;
        foreach ($vorschau['zeilen'] as $mitglied) {
            if ($this->erzeugeBeitrag((int) $mitglied['id'], $jahr, (string) $mitglied['jahresbeitrag'])) {
                $anzahl++;
            }
        }
        $this->audit->protokolliere($benutzerId, 'sollstellung_ausgefuehrt', 'forderung', null, ['jahr' => $jahr, 'anzahl' => $anzahl]);

        return $anzahl;
    }

    /**
     * Einzelsollstellung bei Aktivierung: volle Beitragsforderung fürs laufende Jahr.
     */
    public function einzelsollstellung(int $mitgliedId, string $jahresbeitrag, ?int $benutzerId): void
    {
        $jahr = (int) (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y');
        if ($this->erzeugeBeitrag($mitgliedId, $jahr, $jahresbeitrag)) {
            $this->audit->protokolliere($benutzerId, 'einzelsollstellung', 'forderung', $mitgliedId, ['jahr' => $jahr]);
        }
    }

    /**
     * Passt eine noch offene Beitragsforderung des Jahres an (§3.5). Forderungen in
     * „im Einzug"/„bezahlt" werden nie verändert.
     *
     * @return bool true, wenn angepasst
     */
    public function beitragForderungAnpassen(int $mitgliedId, int $jahr, string $neuerBetrag, ?int $benutzerId): bool
    {
        $forderung = $this->forderungen->beitragFuerJahr($mitgliedId, $jahr);
        if ($forderung === null || $forderung['status'] !== Forderungsstatus::OFFEN) {
            return false;
        }
        $betrag = number_format((float) str_replace(',', '.', $neuerBetrag), 2, '.', '');
        $this->db->ausfuehren(
            'UPDATE forderung SET betrag = :b, updated_at = :now WHERE id = :id',
            ['b' => $betrag, 'now' => $this->jetzt(), 'id' => (int) $forderung['id']],
        );
        $this->audit->protokolliere($benutzerId, 'forderung_angepasst', 'forderung', (int) $forderung['id'], ['betrag' => $betrag]);

        return true;
    }

    public function offeneBeitragsforderungLaufendesJahr(int $mitgliedId): ?array
    {
        $jahr = (int) (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y');
        $forderung = $this->forderungen->beitragFuerJahr($mitgliedId, $jahr);

        return ($forderung !== null && $forderung['status'] === Forderungsstatus::OFFEN) ? $forderung : null;
    }

    public function stornieren(int $forderungId, ?int $benutzerId): void
    {
        $forderung = $this->forderungen->findePerId($forderungId);
        if ($forderung === null) {
            throw new \RuntimeException('Forderung nicht gefunden.');
        }
        if (Forderungsstatus::istAbgeschlossen((string) $forderung['status'])) {
            throw new \DomainException('Eine Forderung im Einzug oder bezahlte Forderung kann nicht storniert werden.');
        }
        $this->db->ausfuehren(
            "UPDATE forderung SET status = 'storniert', updated_at = :now WHERE id = :id",
            ['now' => $this->jetzt(), 'id' => $forderungId],
        );
        $this->audit->protokolliere($benutzerId, 'forderung_storniert', 'forderung', $forderungId);
    }

    /**
     * Selbstzahler-Forderung manuell als bezahlt markieren.
     */
    public function alsBezahltMarkieren(int $forderungId, string $zahlungsart, ?string $datum, ?int $benutzerId): void
    {
        $forderung = $this->forderungen->findePerId($forderungId);
        if ($forderung === null) {
            throw new \RuntimeException('Forderung nicht gefunden.');
        }
        if ((string) $forderung['status'] === Forderungsstatus::STORNIERT) {
            throw new \DomainException('Eine stornierte Forderung kann nicht als bezahlt markiert werden.');
        }
        $art = in_array($zahlungsart, ['bar', 'ueberweisung', 'lastschrift'], true) ? $zahlungsart : 'ueberweisung';
        $this->db->ausfuehren(
            "UPDATE forderung SET status = 'bezahlt', bezahlt_am = :am, zahlungsart = :art, updated_at = :now WHERE id = :id",
            ['am' => $datum ?: $this->heute(), 'art' => $art, 'now' => $this->jetzt(), 'id' => $forderungId],
        );
        $this->audit->protokolliere($benutzerId, 'forderung_bezahlt', 'forderung', $forderungId, ['art' => $art]);
    }

    public function gebuehrAnlegen(int $mitgliedId, string $betrag, int $jahr, ?int $benutzerId): int
    {
        $normal = number_format((float) str_replace(',', '.', $betrag), 2, '.', '');
        $id = $this->forderungen->anlegen([
            'mitglied_id' => $mitgliedId,
            'jahr'        => $jahr,
            'betrag'      => $normal,
            'typ'         => Forderungsstatus::TYP_GEBUEHR,
            'status'      => Forderungsstatus::OFFEN,
        ]);
        $this->audit->protokolliere($benutzerId, 'gebuehr_angelegt', 'forderung', $id, ['betrag' => $normal]);

        return $id;
    }

    // ---- intern ----------------------------------------------------------

    private function erzeugeBeitrag(int $mitgliedId, int $jahr, string $betrag): bool
    {
        if ($this->forderungen->beitragFuerJahr($mitgliedId, $jahr) !== null) {
            return false;
        }
        try {
            $this->forderungen->anlegen([
                'mitglied_id' => $mitgliedId,
                'jahr'        => $jahr,
                'betrag'      => number_format((float) $betrag, 2, '.', ''),
                'typ'         => Forderungsstatus::TYP_BEITRAG,
                'status'      => Forderungsstatus::OFFEN,
            ]);

            return true;
        } catch (\PDOException) {
            // Parallele Ausführung: UNIQUE(mitglied_id, beitrag_jahr) hat gegriffen.
            return false;
        }
    }

    private function heute(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d');
    }

    private function jetzt(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d H:i:s');
    }
}
