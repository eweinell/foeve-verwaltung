<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Statische Prüfung: kein SQL darf denselben benannten Platzhalter zweimal
 * verwenden.
 *
 * Hintergrund: Db verbindet mit ATTR_EMULATE_PREPARES=false, also mit nativen
 * Prepared Statements. MariaDB weist einen doppelt gebundenen Parameter dann mit
 * „SQLSTATE[HY093]: Invalid parameter number" ab — SQLite verzeiht es. Da die
 * übrigen Tests auf einer In-Memory-SQLite laufen, blieb genau dieser Fehler in
 * AP1–AP3 unentdeckt (u. a. war die DOI-Bestätigung produktiv nie ausführbar).
 *
 * Dieser Test braucht keine Datenbank und läuft deshalb überall mit.
 */
final class SqlPlatzhalterTest extends TestCase
{
    public function testKeinSqlVerwendetEinenPlatzhalterMehrfach(): void
    {
        $funde = [];

        foreach ($this->sqlLiterale() as [$datei, $zeile, $sql]) {
            preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/', $sql, $treffer);
            $mehrfach = array_keys(array_filter(array_count_values($treffer[1]), static fn (int $n): bool => $n > 1));

            if ($mehrfach !== []) {
                $funde[] = sprintf(
                    "%s:%d — Platzhalter %s mehrfach verwendet:\n    %s",
                    $datei,
                    $zeile,
                    implode(', ', array_map(static fn (string $p): string => ':' . $p, $mehrfach)),
                    trim((string) preg_replace('/\s+/', ' ', $sql)),
                );
            }
        }

        self::assertSame(
            [],
            $funde,
            "Doppelte SQL-Platzhalter gefunden — auf MariaDB führt das zu HY093.\n"
            . "Jeden Wert einzeln binden (z. B. :created und :updated statt zweimal :now).\n\n"
            . implode("\n", $funde),
        );
    }

    /**
     * Sammelt SQL-Stringliterale aus dem Produktivcode ein.
     *
     * @return array<int,array{0:string,1:int,2:string}> [relativer Pfad, Zeile, SQL]
     */
    private function sqlLiterale(): array
    {
        $wurzel = dirname(__DIR__);
        $treffer = [];

        foreach (['/src', '/bin'] as $verzeichnis) {
            $pfad = $wurzel . $verzeichnis;
            if (!is_dir($pfad)) {
                continue;
            }

            /** @var \SplFileInfo $datei */
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pfad)) as $datei) {
                if ($datei->getExtension() !== 'php') {
                    continue;
                }

                $inhalt = (string) file_get_contents($datei->getPathname());
                // (?:\\.|(?!\1).)*? statt .*?, damit escapte Anführungszeichen
                // (z. B. \'keine\' in einem einfach gequoteten String) den
                // String nicht vorzeitig beenden.
                $gefunden = preg_match_all(
                    '/([\'"])((?:INSERT|UPDATE|DELETE|SELECT|REPLACE)\s(?:\\\\.|(?!\1).)*?)\1/is',
                    $inhalt,
                    $m,
                    PREG_OFFSET_CAPTURE,
                );
                if ($gefunden === 0 || $gefunden === false) {
                    continue;
                }

                foreach ($m[2] as $stelle) {
                    $treffer[] = [
                        str_replace($wurzel . DIRECTORY_SEPARATOR, '', $datei->getPathname()),
                        substr_count(substr($inhalt, 0, (int) $stelle[1]), "\n") + 1,
                        (string) $stelle[0],
                    ];
                }
            }
        }

        // Schutz gegen einen stillschweigend leeren Lauf (z. B. falscher Pfad).
        self::assertNotEmpty($treffer, 'Es wurden keine SQL-Literale gefunden — die Prüfung greift nicht.');

        return $treffer;
    }
}
