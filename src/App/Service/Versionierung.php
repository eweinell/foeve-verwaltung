<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\Db;

/**
 * Versionierungs-Service (F10, KONZEPT §F10). Jede Änderung an einem versionierten
 * Datensatz (mitglied, mandat) läuft über diesen Service: vor dem Speichern wird der
 * komplette bisherige Datensatz als Snapshot (JSON) in {tabelle}_version geschrieben,
 * inkl. Liste der geänderten Felder und Benutzer/Zeitpunkt — transaktional.
 *
 * Wiederherstellung erfolgt als NEUE Version (Vorwärts-Revert), die Historie bleibt
 * lückenlos. Sensible Felder (IBAN) liegen im Datensatz bereits verschlüsselt vor und
 * bleiben es damit auch im Snapshot.
 *
 * Voraussetzung an die Versionstabelle {tabelle}_version:
 *   ({tabelle}_id, version_nr, snapshot, geaenderte_felder,
 *    geaendert_von, geaendert_am, ist_revert_von NULLABLE)
 */
final class Versionierung
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * Führt ein $update transaktional aus und schreibt zuvor/danach eine Version.
     *
     * Ablauf: Vorher-Snapshot lesen → $update ausführen → Nachher lesen →
     * Diff bestimmen → Versionszeile schreiben. Alles in einer Transaktion.
     *
     * @param callable(Db):void $update  Callback, das das eigentliche UPDATE ausführt.
     * @return array{version_id:string,geaenderte_felder:array<int,string>}
     */
    public function mitSnapshot(
        string $tabelle,
        int|string $id,
        ?int $benutzerId,
        callable $update,
        ?int $revertVon = null,
    ): array {
        $this->pruefeBezeichner($tabelle);

        return $this->db->inTransaktion(function (Db $db) use ($tabelle, $id, $benutzerId, $update, $revertVon): array {
            $vorher = $db->eineZeile("SELECT * FROM {$tabelle} WHERE id = :id", ['id' => $id]);
            if ($vorher === null) {
                throw new \RuntimeException("Datensatz {$tabelle}#{$id} existiert nicht.");
            }

            $update($db);

            $nachher = $db->eineZeile("SELECT * FROM {$tabelle} WHERE id = :id", ['id' => $id]);
            if ($nachher === null) {
                throw new \RuntimeException("Datensatz {$tabelle}#{$id} nach Update nicht mehr vorhanden.");
            }

            $geaendert = $this->diff($vorher, $nachher);
            $versionId = $this->schreibeVersion($db, $tabelle, $id, $benutzerId, $vorher, $geaendert, $revertVon);

            return ['version_id' => $versionId, 'geaenderte_felder' => $geaendert];
        });
    }

    /**
     * Stellt den Stand einer früheren Version wieder her — als NEUE Version
     * (Vorwärts-Revert). Setzt ist_revert_von auf die Quell-Version.
     *
     * @param array<int,string>|null $nurFelder  Nur diese Felder übernehmen (Default: alle aus dem Snapshot).
     */
    public function revert(
        string $tabelle,
        int|string $id,
        int $versionId,
        ?int $benutzerId,
        ?array $nurFelder = null,
    ): array {
        $this->pruefeBezeichner($tabelle);

        $version = $this->db->eineZeile(
            "SELECT * FROM {$tabelle}_version WHERE id = :vid AND {$tabelle}_id = :id",
            ['vid' => $versionId, 'id' => $id],
        );
        if ($version === null) {
            throw new \RuntimeException("Version #{$versionId} zu {$tabelle}#{$id} nicht gefunden.");
        }

        $snapshot = json_decode((string) $version['snapshot'], true);
        if (!is_array($snapshot)) {
            throw new \RuntimeException('Snapshot der Zielversion ist unlesbar.');
        }

        // Nicht wiederherstellbare/technische Felder ausklammern.
        unset($snapshot['id'], $snapshot['created_at'], $snapshot['updated_at']);
        if ($nurFelder !== null) {
            $snapshot = array_intersect_key($snapshot, array_flip($nurFelder));
        }
        if ($snapshot === []) {
            throw new \RuntimeException('Keine wiederherstellbaren Felder in der Zielversion.');
        }

        return $this->mitSnapshot(
            $tabelle,
            $id,
            $benutzerId,
            function (Db $db) use ($tabelle, $id, $snapshot): void {
                $sets = [];
                $params = ['id' => $id];
                foreach (array_keys($snapshot) as $feld) {
                    $sets[] = "{$feld} = :{$feld}";
                    $params[$feld] = $snapshot[$feld];
                }
                $db->ausfuehren(
                    "UPDATE {$tabelle} SET " . implode(', ', $sets) . ' WHERE id = :id',
                    $params,
                );
            },
            $versionId,
        );
    }

    /**
     * @param array<int,array<string,mixed>> $vorher  wird nicht genutzt; nur Signatur-Doku
     */
    private function schreibeVersion(
        Db $db,
        string $tabelle,
        int|string $id,
        ?int $benutzerId,
        array $vorher,
        array $geaendert,
        ?int $revertVon,
    ): string {
        $naechste = (int) $db->einWert(
            "SELECT COALESCE(MAX(version_nr), 0) + 1 FROM {$tabelle}_version WHERE {$tabelle}_id = :id",
            ['id' => $id],
        );

        $jetzt = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d H:i:s');

        $db->ausfuehren(
            "INSERT INTO {$tabelle}_version
                ({$tabelle}_id, version_nr, snapshot, geaenderte_felder, geaendert_von, geaendert_am, ist_revert_von)
             VALUES (:id, :nr, :snapshot, :felder, :von, :am, :revert)",
            [
                'id'       => $id,
                'nr'       => $naechste,
                'snapshot' => json_encode($vorher, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'felder'   => json_encode(array_values($geaendert), JSON_UNESCAPED_UNICODE),
                'von'      => $benutzerId,
                'am'       => $jetzt,
                'revert'   => $revertVon,
            ],
        );

        return $db->letzteId();
    }

    /**
     * Namen der Felder, deren Werte sich geändert haben (Vergleich als String,
     * da PDO Skalare als String liefert).
     *
     * @param array<string,mixed> $vorher
     * @param array<string,mixed> $nachher
     * @return array<int,string>
     */
    private function diff(array $vorher, array $nachher): array
    {
        $geaendert = [];
        foreach ($nachher as $feld => $wert) {
            $alt = $vorher[$feld] ?? null;
            if ((string) $alt !== (string) $wert || ($alt === null) !== ($wert === null)) {
                $geaendert[] = $feld;
            }
        }

        return $geaendert;
    }

    private function pruefeBezeichner(string $tabelle): void
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $tabelle) !== 1) {
            throw new \InvalidArgumentException("Ungültiger Tabellenname: {$tabelle}");
        }
    }
}
