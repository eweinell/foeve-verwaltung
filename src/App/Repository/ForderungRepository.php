<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Forderungsstatus;
use App\Support\Db;

/**
 * Zugriff auf die Tabelle forderung (offene Posten). Forderungen sind
 * unveränderlich — es gibt hier bewusst KEIN DELETE; Korrektur nur per Storno.
 * Idempotenz der Beitrags-Sollstellung über beitrag_jahr (UNIQUE mit mitglied_id).
 */
final class ForderungRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findePerId(int $id): ?array
    {
        return $this->db->eineZeile('SELECT * FROM forderung WHERE id = :id', ['id' => $id]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function findePerMitglied(int $mitgliedId): array
    {
        return $this->db->alleZeilen(
            'SELECT * FROM forderung WHERE mitglied_id = :id ORDER BY jahr DESC, id DESC',
            ['id' => $mitgliedId],
        );
    }

    /**
     * Vorhandene Beitragsforderung eines Mitglieds für ein Jahr (nicht storniert).
     *
     * @return array<string,mixed>|null
     */
    public function beitragFuerJahr(int $mitgliedId, int $jahr): ?array
    {
        return $this->db->eineZeile(
            "SELECT * FROM forderung
              WHERE mitglied_id = :id AND jahr = :jahr AND typ = 'beitrag' AND status <> 'storniert'",
            ['id' => $mitgliedId, 'jahr' => $jahr],
        );
    }

    /**
     * Legt eine Forderung an. Für Beitragsforderungen wird beitrag_jahr gesetzt
     * (Idempotenz-Sicherung); für Gebühren bleibt es NULL.
     *
     * @param array<string,mixed> $daten
     */
    public function anlegen(array $daten): int
    {
        $jetzt = $this->jetzt();
        $typ = (string) ($daten['typ'] ?? Forderungsstatus::TYP_BEITRAG);
        $jahr = (int) $daten['jahr'];

        $this->db->ausfuehren(
            'INSERT INTO forderung
                (mitglied_id, jahr, betrag, typ, status, beitrag_jahr, created_at, updated_at)
             VALUES (:mid, :jahr, :betrag, :typ, :status, :bjahr, :created, :updated)',
            [
                'mid'     => (int) $daten['mitglied_id'],
                'jahr'    => $jahr,
                'betrag'  => $daten['betrag'],
                'typ'     => $typ,
                'status'  => (string) ($daten['status'] ?? Forderungsstatus::OFFEN),
                'bjahr'   => $typ === Forderungsstatus::TYP_BEITRAG ? $jahr : null,
                'created' => $jetzt,
                'updated' => $jetzt,
            ],
        );

        return (int) $this->db->letzteId();
    }

    /**
     * Offene-Posten-Übersicht mit Filtern (Jahr/Status/Zahlweise) und Summe.
     *
     * @param array<string,mixed> $filter
     * @return array{zeilen:array<int,array<string,mixed>>,summe:string,anzahl:int}
     */
    public function offenePosten(array $filter): array
    {
        $wo = [];
        $params = [];
        if (($filter['jahr'] ?? '') !== '' && ctype_digit((string) $filter['jahr'])) {
            $wo[] = 'f.jahr = :jahr';
            $params['jahr'] = (int) $filter['jahr'];
        }
        if (($filter['status'] ?? '') !== '') {
            $wo[] = 'f.status = :status';
            $params['status'] = $filter['status'];
        }
        if (($filter['zahlweise'] ?? '') !== '') {
            $wo[] = 'm.zahlweise = :zw';
            $params['zw'] = $filter['zahlweise'];
        }
        $where = $wo !== [] ? 'WHERE ' . implode(' AND ', $wo) : '';

        $zeilen = $this->db->alleZeilen(
            "SELECT f.*, m.nachname, m.vorname, m.anrede, m.mitgliedsnummer, m.zahlweise
               FROM forderung f
               JOIN mitglied m ON m.id = f.mitglied_id
               {$where}
              ORDER BY f.jahr DESC, m.nachname ASC, f.id DESC",
            $params,
        );

        $summe = $this->db->einWert(
            "SELECT COALESCE(SUM(f.betrag), 0) FROM forderung f JOIN mitglied m ON m.id = f.mitglied_id {$where}",
            $params,
        );

        return ['zeilen' => $zeilen, 'summe' => number_format((float) $summe, 2, '.', ''), 'anzahl' => count($zeilen)];
    }

    /**
     * @return array<int,int>
     */
    public function jahre(): array
    {
        $zeilen = $this->db->alleZeilen('SELECT DISTINCT jahr FROM forderung ORDER BY jahr DESC');

        return array_map(static fn (array $z): int => (int) $z['jahr'], $zeilen);
    }

    private function jetzt(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d H:i:s');
    }
}
