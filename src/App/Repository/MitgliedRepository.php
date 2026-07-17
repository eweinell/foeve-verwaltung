<?php

declare(strict_types=1);

namespace App\Repository;

use App\Support\Db;

/**
 * Zugriff auf die Tabelle mitglied. Schreibzugriffe auf Stammdaten laufen NICHT
 * hier, sondern über den Versionierungs-Service (CLAUDE.md Regel 2); dieses
 * Repository liest, filtert und legt neue (Antrags-)Datensätze an.
 *
 * Die Filterlogik ist so gebaut, dass AP5 (Exporte) sie wiederverwenden kann.
 */
final class MitgliedRepository
{
    private const SORT_SPALTEN = ['nachname', 'mitgliedsnummer', 'ort', 'jahresbeitrag', 'status', 'created_at'];

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findePerId(int $id): ?array
    {
        return $this->db->eineZeile('SELECT * FROM mitglied WHERE id = :id', ['id' => $id]);
    }

    /**
     * Baut WHERE-Klausel + Parameter aus den Filtern. Öffentlich, damit AP5 dieselbe
     * Filterung für Exporte nutzen kann.
     *
     * @param array<string,mixed> $filter
     * @return array{0:string,1:array<string,mixed>}
     */
    public function filterKlausel(array $filter): array
    {
        $wo = [];
        $params = [];

        $q = trim((string) ($filter['q'] ?? ''));
        if ($q !== '') {
            $teil = '(nachname LIKE :q OR vorname LIKE :q OR ort LIKE :q OR email LIKE :q';
            $params['q'] = '%' . $q . '%';
            if (ctype_digit($q)) {
                $teil .= ' OR mitgliedsnummer = :qnum';
                $params['qnum'] = (int) $q;
            }
            $teil .= ')';
            $wo[] = $teil;
        }

        foreach (['status' => 'status', 'zahlweise' => 'zahlweise', 'land' => 'land'] as $key => $spalte) {
            $wert = trim((string) ($filter[$key] ?? ''));
            if ($wert !== '') {
                $wo[] = "{$spalte} = :{$key}";
                $params[$key] = $wert;
            }
        }

        $email = trim((string) ($filter['email'] ?? ''));
        if ($email === 'ja') {
            $wo[] = "(email IS NOT NULL AND email <> '')";
        } elseif ($email === 'nein') {
            $wo[] = "(email IS NULL OR email = '')";
        }

        $beitrag = trim((string) ($filter['beitrag'] ?? ''));
        if ($beitrag !== '' && is_numeric($beitrag)) {
            $wo[] = 'jahresbeitrag = :beitrag';
            $params['beitrag'] = number_format((float) $beitrag, 2, '.', '');
        }

        $where = $wo !== [] ? 'WHERE ' . implode(' AND ', $wo) : '';

        return [$where, $params];
    }

    /**
     * @param array<string,mixed> $filter
     * @return array{zeilen:array<int,array<string,mixed>>,gesamt:int,seite:int,seiten:int}
     */
    public function suchen(array $filter, int $seite = 1, int $proSeite = 25, string $sort = 'nachname', string $richtung = 'asc'): array
    {
        [$where, $params] = $this->filterKlausel($filter);

        $sort = in_array($sort, self::SORT_SPALTEN, true) ? $sort : 'nachname';
        $richtung = strtolower($richtung) === 'desc' ? 'DESC' : 'ASC';

        $gesamt = (int) $this->db->einWert("SELECT COUNT(*) FROM mitglied {$where}", $params);

        $proSeite = max(1, min(200, $proSeite));
        $seiten = max(1, (int) ceil($gesamt / $proSeite));
        $seite = max(1, min($seiten, $seite));
        $offset = ($seite - 1) * $proSeite;

        $zeilen = $this->db->alleZeilen(
            "SELECT * FROM mitglied {$where} ORDER BY {$sort} {$richtung}, id ASC LIMIT {$proSeite} OFFSET {$offset}",
            $params,
        );

        return ['zeilen' => $zeilen, 'gesamt' => $gesamt, 'seite' => $seite, 'seiten' => $seiten];
    }

    /**
     * Legt einen neuen (Antrags-)Datensatz an. Rückgabe: neue ID.
     *
     * @param array<string,mixed> $daten
     */
    public function anlegen(array $daten): int
    {
        $jetzt = $this->jetzt();
        $spalten = [
            'status', 'anrede', 'vorname', 'nachname', 'briefanrede_manuell', 'adresszeile_manuell',
            'strasse', 'plz', 'ort', 'land', 'email', 'kein_email_kontakt', 'telefon',
            'jahresbeitrag', 'zahlweise', 'notizen',
        ];
        $felder = [];
        $platzhalter = [];
        $params = [];
        foreach ($spalten as $s) {
            if (array_key_exists($s, $daten)) {
                $felder[] = $s;
                $platzhalter[] = ':' . $s;
                $params[$s] = $daten[$s];
            }
        }
        $felder[] = 'created_at';
        $felder[] = 'updated_at';
        $platzhalter[] = ':created_at';
        $platzhalter[] = ':updated_at';
        $params['created_at'] = $jetzt;
        $params['updated_at'] = $jetzt;

        $this->db->ausfuehren(
            'INSERT INTO mitglied (' . implode(', ', $felder) . ') VALUES (' . implode(', ', $platzhalter) . ')',
            $params,
        );

        return (int) $this->db->letzteId();
    }

    public function anzahlNachStatus(string $status): int
    {
        return (int) $this->db->einWert('SELECT COUNT(*) FROM mitglied WHERE status = :s', ['s' => $status]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function nachStatus(string $status, int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));

        return $this->db->alleZeilen(
            "SELECT * FROM mitglied WHERE status = :s ORDER BY created_at DESC LIMIT {$limit}",
            ['s' => $status],
        );
    }

    private function jetzt(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d H:i:s');
    }
}
