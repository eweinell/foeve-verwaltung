<?php

declare(strict_types=1);

namespace App\Repository;

use App\Support\Db;

/**
 * Zugriff auf einzugslauf und die verknüpften Positionen. Eine Forderung ist über
 * forderung.einzugslauf_id genau einem Lauf zugeordnet (verhindert Doppel-Einzug).
 */
final class EinzugslaufRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findePerId(int $id): ?array
    {
        return $this->db->eineZeile('SELECT * FROM einzugslauf WHERE id = :id', ['id' => $id]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function alle(): array
    {
        return $this->db->alleZeilen('SELECT * FROM einzugslauf ORDER BY id DESC');
    }

    public function anlegen(string $bezeichnung, string $faelligkeit, ?int $benutzerId): int
    {
        $jetzt = $this->jetzt();
        $this->db->ausfuehren(
            "INSERT INTO einzugslauf (bezeichnung, faelligkeitsdatum, status, erstellt_von, created_at, updated_at)
             VALUES (:b, :f, 'entwurf', :von, :now, :now)",
            ['b' => $bezeichnung, 'f' => $faelligkeit, 'von' => $benutzerId, 'now' => $jetzt],
        );

        return (int) $this->db->letzteId();
    }

    /**
     * Forderungen, die in einen neuen Lauf aufgenommen werden können:
     * status offen, Zahlweise lastschrift, aktives Mandat, noch keinem Lauf zugeordnet.
     *
     * @return array<int,array<string,mixed>>
     */
    public function aufnehmbareForderungen(): array
    {
        return $this->db->alleZeilen(
            "SELECT f.id FROM forderung f
               JOIN mitglied m ON m.id = f.mitglied_id
               JOIN mandat ma ON ma.mitglied_id = f.mitglied_id AND ma.status = 'aktiv'
              WHERE f.status = 'offen' AND f.einzugslauf_id IS NULL AND m.zahlweise = 'lastschrift'",
        );
    }

    /**
     * @param array<int,int> $forderungIds
     */
    public function bindeForderungen(int $laufId, array $forderungIds): void
    {
        foreach ($forderungIds as $fid) {
            $this->db->ausfuehren(
                'UPDATE forderung SET einzugslauf_id = :lauf, updated_at = :now WHERE id = :id AND einzugslauf_id IS NULL',
                ['lauf' => $laufId, 'now' => $this->jetzt(), 'id' => (int) $fid],
            );
        }
    }

    /**
     * Positionen eines Laufs inkl. Mandats- und Mitgliedsdaten (für Vorschau/XML/Brief).
     *
     * @return array<int,array<string,mixed>>
     */
    public function positionen(int $laufId): array
    {
        return $this->db->alleZeilen(
            "SELECT f.id AS forderung_id, f.betrag, f.jahr, f.typ, f.status AS forderung_status,
                    m.id AS mitglied_id, m.mitgliedsnummer, m.nachname, m.vorname, m.anrede,
                    m.email, m.kein_email_kontakt,
                    ma.id AS mandat_id, ma.mandatsreferenz, ma.iban_verschluesselt, ma.bic,
                    ma.kontoinhaber, ma.erteilt_am, ma.sequenz_genutzt
               FROM forderung f
               JOIN mitglied m ON m.id = f.mitglied_id
               LEFT JOIN mandat ma ON ma.mitglied_id = f.mitglied_id AND ma.status = 'aktiv'
              WHERE f.einzugslauf_id = :lauf
              ORDER BY m.nachname ASC, f.id ASC",
            ['lauf' => $laufId],
        );
    }

    private function jetzt(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d H:i:s');
    }
}
