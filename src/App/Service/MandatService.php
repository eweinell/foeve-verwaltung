<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Mandatsreferenz;
use App\Domain\Mandatsstatus;
use App\Repository\AntragRepository;
use App\Repository\MandatRepository;
use App\Repository\MitgliedRepository;
use App\Support\Db;

/**
 * SEPA-Mandate (F3). Anlage automatisch bei Aktivierung (aus dem Antrag) oder
 * manuell; Bankwechsel erzeugt ein neues Mandat und deaktiviert das alte
 * (nie zwei aktive). Alle Statusänderungen laufen über den Versionierungs-Service.
 * IBAN wird verschlüsselt gespeichert; Klartext nur intern für die XML-Erzeugung (AP3).
 */
final class MandatService
{
    public function __construct(
        private readonly Db $db,
        private readonly Versionierung $versionierung,
        private readonly MandatRepository $mandate,
        private readonly MitgliedRepository $mitglieder,
        private readonly AntragRepository $antraege,
        private readonly Krypto $krypto,
        private readonly Audit $audit,
    ) {
    }

    /**
     * Legt bei Aktivierung automatisch ein Mandat aus dem Antrag an (Hook aus AP1).
     * Nutzt die im Antrag bereits verschlüsselt hinterlegte IBAN. Ohne Antrag
     * (z. B. Papierantrag) passiert nichts — dann manuell anlegen.
     *
     * @return int|null neue Mandats-ID oder null
     */
    public function ausAntragErstellen(int $mitgliedId, int $mitgliedsnummer, ?int $benutzerId): ?int
    {
        if ($this->mandate->aktivesMandat($mitgliedId) !== null) {
            return null; // schon vorhanden
        }

        $antrag = $this->antraege->neuesterFuerMitglied($mitgliedId);
        if ($antrag === null) {
            return null;
        }
        $payload = json_decode((string) $antrag['payload'], true);
        if (!is_array($payload) || empty($payload['iban_verschluesselt'])) {
            return null;
        }

        $lfd = $this->mandate->naechsteLfdNr($mitgliedId);
        $erteiltAm = $antrag['bestaetigt_am'] !== null
            ? substr((string) $antrag['bestaetigt_am'], 0, 10)
            : $this->heute();

        $id = $this->mandate->anlegen([
            'mitglied_id'         => $mitgliedId,
            'lfd_nr'              => $lfd,
            'mandatsreferenz'     => Mandatsreferenz::bilde($mitgliedsnummer, $lfd),
            'iban_verschluesselt' => (string) $payload['iban_verschluesselt'],
            'kontoinhaber'        => (string) ($payload['kontoinhaber'] ?? ''),
            'erteilt_am'          => $erteiltAm,
            'status'              => Mandatsstatus::AKTIV,
            'sequenz_genutzt'     => 0,
        ]);

        $this->audit->protokolliere($benutzerId, 'mandat_angelegt', 'mandat', $id, ['quelle' => 'antrag']);

        return $id;
    }

    /**
     * Neues Mandat (manuell / Bankwechsel). Ein evtl. aktives Mandat wird zuvor
     * deaktiviert, sodass nie zwei aktive existieren. IBAN im Klartext übergeben.
     */
    public function neuesMandat(int $mitgliedId, string $ibanKlartext, string $kontoinhaber, ?string $bic, ?int $benutzerId, ?string $erteiltAm = null): int
    {
        $mitglied = $this->mitglieder->findePerId($mitgliedId);
        if ($mitglied === null || $mitglied['mitgliedsnummer'] === null) {
            throw new \RuntimeException('Mandat kann nur für ein aktiviertes Mitglied mit Nummer angelegt werden.');
        }

        // Bankwechsel: bestehendes aktives Mandat zuerst deaktivieren.
        $bestehend = $this->mandate->aktivesMandat($mitgliedId);
        if ($bestehend !== null) {
            $this->statusUpdate((int) $bestehend['id'], $mitgliedId, Mandatsstatus::INAKTIV, $benutzerId);
        }

        $lfd = $this->mandate->naechsteLfdNr($mitgliedId);
        $id = $this->mandate->anlegen([
            'mitglied_id'         => $mitgliedId,
            'lfd_nr'              => $lfd,
            'mandatsreferenz'     => Mandatsreferenz::bilde((int) $mitglied['mitgliedsnummer'], $lfd),
            'iban_verschluesselt' => $this->krypto->verschluesseln($this->normalisiereIban($ibanKlartext)),
            'bic'                 => $bic ?: null,
            'kontoinhaber'        => $kontoinhaber,
            'erteilt_am'          => $erteiltAm ?: $this->heute(),
            'status'              => Mandatsstatus::AKTIV,
            'sequenz_genutzt'     => 0,
        ]);

        $this->audit->protokolliere($benutzerId, 'mandat_angelegt', 'mandat', $id, [
            'quelle'  => $bestehend !== null ? 'bankwechsel' : 'manuell',
        ]);

        return $id;
    }

    /**
     * Widerruf eines Mandats. Das Mitglied wird auf Zahlweise „selbstzahler"
     * gestellt (die Bestätigung erfolgt in der UI).
     */
    public function widerrufen(int $mandatId, ?int $benutzerId): void
    {
        $mandat = $this->mandate->findePerId($mandatId);
        if ($mandat === null) {
            throw new \RuntimeException('Mandat nicht gefunden.');
        }
        $mitgliedId = (int) $mandat['mitglied_id'];

        $this->statusUpdate($mandatId, $mitgliedId, Mandatsstatus::WIDERRUFEN, $benutzerId);
        $this->setzeZahlweiseSelbstzahler($mitgliedId, $benutzerId);
        $this->audit->protokolliere($benutzerId, 'mandat_widerrufen', 'mandat', $mandatId);
    }

    /**
     * Stellt ein Mitglied auf Selbstzahler um und deaktiviert das aktive Mandat
     * (Ein-Klick nach Rücklastschrift, F5).
     */
    public function umstellenAufSelbstzahler(int $mitgliedId, ?int $benutzerId): void
    {
        $aktiv = $this->mandate->aktivesMandat($mitgliedId);
        if ($aktiv !== null) {
            $this->statusUpdate((int) $aktiv['id'], $mitgliedId, Mandatsstatus::INAKTIV, $benutzerId);
            $this->audit->protokolliere($benutzerId, 'mandat_deaktiviert', 'mandat', (int) $aktiv['id'], ['grund' => 'ruecklastschrift']);
        }
        $this->setzeZahlweiseSelbstzahler($mitgliedId, $benutzerId);
    }

    /**
     * Deaktiviert ein Mandat (z. B. bei Umstellung auf Selbstzahler).
     */
    public function deaktivieren(int $mandatId, ?int $benutzerId): void
    {
        $mandat = $this->mandate->findePerId($mandatId);
        if ($mandat === null) {
            throw new \RuntimeException('Mandat nicht gefunden.');
        }
        $this->statusUpdate($mandatId, (int) $mandat['mitglied_id'], Mandatsstatus::INAKTIV, $benutzerId);
        $this->audit->protokolliere($benutzerId, 'mandat_deaktiviert', 'mandat', $mandatId);
    }

    /**
     * Prüft, ob ein aktives Mandat seit über 36 Monaten nicht genutzt wurde
     * (SEPA-Verfall). Grundlage: zuletzt_genutzt_am bzw. erteilt_am.
     *
     * @param array<string,mixed> $mandat
     */
    public function istVerfallen(array $mandat): bool
    {
        if (($mandat['status'] ?? '') !== Mandatsstatus::AKTIV) {
            return false;
        }
        $basis = $mandat['zuletzt_genutzt_am'] ?: $mandat['erteilt_am'];
        if (!$basis) {
            return false;
        }
        $datum = \DateTimeImmutable::createFromFormat('Y-m-d', substr((string) $basis, 0, 10));
        if (!$datum instanceof \DateTimeImmutable) {
            return false;
        }
        $grenze = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))
            ->modify('-' . Mandatsstatus::VERFALL_MONATE . ' months');

        return $datum < $grenze;
    }

    /**
     * IBAN eines Mandats entschlüsselt (nur intern, z. B. für die XML-Erzeugung in AP3).
     *
     * @param array<string,mixed> $mandat
     */
    public function ibanKlartext(array $mandat): string
    {
        return $this->krypto->entschluesseln((string) $mandat['iban_verschluesselt']);
    }

    // ---- intern ----------------------------------------------------------

    private function statusUpdate(int $mandatId, int $mitgliedId, string $status, ?int $benutzerId): void
    {
        $this->versionierung->mitSnapshot('mandat', $mandatId, $benutzerId, function (Db $db) use ($mandatId, $mitgliedId, $status): void {
            $db->ausfuehren(
                'UPDATE mandat SET status = :s, aktiv_mitglied = :aktiv, updated_at = :now WHERE id = :id',
                [
                    's'     => $status,
                    'aktiv' => $status === Mandatsstatus::AKTIV ? $mitgliedId : null,
                    'now'   => $this->jetzt(),
                    'id'    => $mandatId,
                ],
            );
        });
    }

    private function setzeZahlweiseSelbstzahler(int $mitgliedId, ?int $benutzerId): void
    {
        $this->versionierung->mitSnapshot('mitglied', $mitgliedId, $benutzerId, function (Db $db) use ($mitgliedId): void {
            $db->ausfuehren(
                "UPDATE mitglied SET zahlweise = 'selbstzahler', updated_at = :now WHERE id = :id",
                ['now' => $this->jetzt(), 'id' => $mitgliedId],
            );
        });
    }

    private function normalisiereIban(string $iban): string
    {
        return strtoupper(preg_replace('/\s+/', '', $iban) ?? '');
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
