<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\Db;

/**
 * Audit-Log (F9): protokolliert sicherheitsrelevante Aktionen — Logins,
 * Sperren, Benutzeränderungen, später Exporte/Versand/Statuswechsel.
 * Datenänderungen an Personendaten laufen über die Versionierung (F10), nicht hier.
 */
final class Audit
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * @param array<string,mixed> $details  Keine Secrets/IBAN im Klartext (CLAUDE.md Regel 3/9).
     */
    public function protokolliere(
        ?int $benutzerId,
        string $aktion,
        ?string $entitaet = null,
        int|string|null $entitaetId = null,
        array $details = [],
    ): void {
        $jetzt = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d H:i:s');

        $this->db->ausfuehren(
            'INSERT INTO audit_log (benutzer_id, zeitpunkt, aktion, entitaet, entitaet_id, details)
             VALUES (:bid, :zeit, :aktion, :entitaet, :eid, :details)',
            [
                'bid'      => $benutzerId,
                'zeit'     => $jetzt,
                'aktion'   => $aktion,
                'entitaet' => $entitaet,
                'eid'      => $entitaetId !== null ? (string) $entitaetId : null,
                'details'  => $details !== [] ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ],
        );
    }

    /**
     * Listenansicht, neueste zuerst, optional gefiltert.
     *
     * @return array<int,array<string,mixed>>
     */
    public function liste(?int $benutzerId = null, ?string $aktion = null, int $limit = 200): array
    {
        $wo = [];
        $params = [];
        if ($benutzerId !== null) {
            $wo[] = 'a.benutzer_id = :bid';
            $params['bid'] = $benutzerId;
        }
        if ($aktion !== null && $aktion !== '') {
            $wo[] = 'a.aktion = :aktion';
            $params['aktion'] = $aktion;
        }
        $where = $wo !== [] ? 'WHERE ' . implode(' AND ', $wo) : '';
        $limit = max(1, min(1000, $limit));

        return $this->db->alleZeilen(
            "SELECT a.*, b.name AS benutzer_name
               FROM audit_log a
               LEFT JOIN benutzer b ON b.id = a.benutzer_id
               {$where}
              ORDER BY a.id DESC
              LIMIT {$limit}",
            $params,
        );
    }

    /**
     * Distinct-Aktionen für die Filter-Auswahl.
     *
     * @return array<int,string>
     */
    public function aktionen(): array
    {
        $zeilen = $this->db->alleZeilen('SELECT DISTINCT aktion FROM audit_log ORDER BY aktion');

        return array_map(static fn (array $z): string => (string) $z['aktion'], $zeilen);
    }
}
