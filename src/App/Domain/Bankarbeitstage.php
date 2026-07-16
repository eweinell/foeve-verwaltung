<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * TARGET2-Bankarbeitstage für die Fälligkeitsprüfung von SEPA-Lastschriften.
 * TARGET2 ist an Wochenenden sowie an Neujahr, Karfreitag, Ostermontag,
 * Tag der Arbeit (1. Mai), 1. und 2. Weihnachtstag geschlossen.
 */
final class Bankarbeitstage
{
    public static function istBankarbeitstag(\DateTimeImmutable $tag): bool
    {
        $wochentag = (int) $tag->format('N'); // 1 (Mo) .. 7 (So)
        if ($wochentag >= 6) {
            return false;
        }

        return !self::istFeiertag($tag);
    }

    /**
     * Anzahl der TARGET2-Bankarbeitstage zwischen zwei Daten (exklusiv Starttag).
     */
    public static function anzahlZwischen(\DateTimeImmutable $von, \DateTimeImmutable $bis): int
    {
        $von = $von->setTime(0, 0);
        $bis = $bis->setTime(0, 0);
        if ($bis <= $von) {
            return 0;
        }

        $tage = 0;
        $cursor = $von;
        while ($cursor < $bis) {
            $cursor = $cursor->modify('+1 day');
            if (self::istBankarbeitstag($cursor)) {
                $tage++;
            }
        }

        return $tage;
    }

    /**
     * Frühestmögliches Fälligkeitsdatum: $n Bankarbeitstage nach heute.
     */
    public static function fruehesteFaelligkeit(\DateTimeImmutable $heute, int $n): \DateTimeImmutable
    {
        $cursor = $heute->setTime(0, 0);
        $gezaehlt = 0;
        while ($gezaehlt < $n) {
            $cursor = $cursor->modify('+1 day');
            if (self::istBankarbeitstag($cursor)) {
                $gezaehlt++;
            }
        }

        return $cursor;
    }

    private static function istFeiertag(\DateTimeImmutable $tag): bool
    {
        $jahr = (int) $tag->format('Y');
        $md = $tag->format('m-d');

        // Feste Feiertage.
        if (in_array($md, ['01-01', '05-01', '12-25', '12-26'], true)) {
            return true;
        }

        // Bewegliche Feiertage relativ zu Ostern.
        $ostern = self::ostersonntag($jahr);
        $karfreitag = $ostern->modify('-2 days')->format('Y-m-d');
        $ostermontag = $ostern->modify('+1 day')->format('Y-m-d');

        return in_array($tag->format('Y-m-d'), [$karfreitag, $ostermontag], true);
    }

    /**
     * Ostersonntag (Gauß/Anonymous Gregorian Algorithm) — ohne ext-calendar.
     */
    private static function ostersonntag(int $jahr): \DateTimeImmutable
    {
        $a = $jahr % 19;
        $b = intdiv($jahr, 100);
        $c = $jahr % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $monat = intdiv($h + $l - 7 * $m + 114, 31);
        $tag = (($h + $l - 7 * $m + 114) % 31) + 1;

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $jahr, $monat, $tag));
    }
}
