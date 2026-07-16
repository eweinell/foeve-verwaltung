<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Mandatsstatus;
use App\Support\Db;

/**
 * Zugriff auf die Tabelle mandat. Statusänderungen laufen über den
 * Versionierungs-Service (CLAUDE.md Regel 2); dieses Repository liest und legt
 * neue Mandate an. Die Spalte aktiv_mitglied sichert DB-seitig, dass es je
 * Mitglied höchstens ein aktives Mandat gibt.
 */
final class MandatRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findePerId(int $id): ?array
    {
        return $this->db->eineZeile('SELECT * FROM mandat WHERE id = :id', ['id' => $id]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function findePerMitglied(int $mitgliedId): array
    {
        return $this->db->alleZeilen(
            'SELECT * FROM mandat WHERE mitglied_id = :id ORDER BY lfd_nr DESC',
            ['id' => $mitgliedId],
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public function aktivesMandat(int $mitgliedId): ?array
    {
        return $this->db->eineZeile(
            "SELECT * FROM mandat WHERE mitglied_id = :id AND status = 'aktiv'",
            ['id' => $mitgliedId],
        );
    }

    public function naechsteLfdNr(int $mitgliedId): int
    {
        return (int) $this->db->einWert(
            'SELECT COALESCE(MAX(lfd_nr), 0) + 1 FROM mandat WHERE mitglied_id = :id',
            ['id' => $mitgliedId],
        );
    }

    /**
     * @param array<string,mixed> $daten
     */
    public function anlegen(array $daten): int
    {
        $jetzt = $this->jetzt();
        $status = (string) ($daten['status'] ?? Mandatsstatus::AKTIV);
        $mitgliedId = (int) $daten['mitglied_id'];

        $this->db->ausfuehren(
            'INSERT INTO mandat
                (mitglied_id, lfd_nr, mandatsreferenz, iban_verschluesselt, bic, kontoinhaber,
                 erteilt_am, status, sequenz_genutzt, aktiv_mitglied, created_at, updated_at)
             VALUES
                (:mid, :lfd, :ref, :iban, :bic, :inhaber, :erteilt, :status, :seq, :aktiv, :now, :now)',
            [
                'mid'     => $mitgliedId,
                'lfd'     => (int) $daten['lfd_nr'],
                'ref'     => $daten['mandatsreferenz'],
                'iban'    => $daten['iban_verschluesselt'],
                'bic'     => $daten['bic'] ?? null,
                'inhaber' => $daten['kontoinhaber'],
                'erteilt' => $daten['erteilt_am'] ?? null,
                'status'  => $status,
                'seq'     => (int) ($daten['sequenz_genutzt'] ?? 0),
                'aktiv'   => $status === Mandatsstatus::AKTIV ? $mitgliedId : null,
                'now'     => $jetzt,
            ],
        );

        return (int) $this->db->letzteId();
    }

    private function jetzt(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d H:i:s');
    }
}
