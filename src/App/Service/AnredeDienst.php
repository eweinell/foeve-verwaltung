<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Anrede- und Adress-Logik (F1). Erzeugt Briefanrede, Adresszeile und die
 * mehrzeilige Postanschrift aus den Stammdaten — mit manueller Überschreibung.
 * Grundlage für Platzhalter in Mails/Serienbriefen (AP4/AP5).
 *
 * Erwartet ein Mitglied als assoziatives Array mit den Feldern aus §5
 * (anrede, vorname, nachname, briefanrede_manuell, adresszeile_manuell,
 *  strasse, plz, ort, land).
 */
final class AnredeDienst
{
    /**
     * @param array<string,mixed> $mitglied
     */
    public function briefanrede(array $mitglied): string
    {
        $manuell = trim((string) ($mitglied['briefanrede_manuell'] ?? ''));
        if ($manuell !== '') {
            return $manuell;
        }

        $nachname = trim((string) ($mitglied['nachname'] ?? ''));

        return match ((string) ($mitglied['anrede'] ?? '')) {
            'herr'    => "Sehr geehrter Herr {$nachname}",
            'frau'    => "Sehr geehrte Frau {$nachname}",
            'familie' => "Sehr geehrte Familie {$nachname}",
            default   => 'Sehr geehrte Damen und Herren',
        };
    }

    /**
     * @param array<string,mixed> $mitglied
     */
    public function adresszeile(array $mitglied): string
    {
        $manuell = trim((string) ($mitglied['adresszeile_manuell'] ?? ''));
        if ($manuell !== '') {
            return $manuell;
        }

        $nachname = trim((string) ($mitglied['nachname'] ?? ''));
        if ((string) ($mitglied['anrede'] ?? '') === 'familie') {
            return "Familie {$nachname}";
        }

        $vorname = trim((string) ($mitglied['vorname'] ?? ''));

        return $vorname !== '' ? "{$vorname} {$nachname}" : $nachname;
    }

    /**
     * Mehrzeilige Postanschrift. Bei Land != DE wird die Länderzeile (deutscher
     * Name) ergänzt.
     *
     * @param array<string,mixed> $mitglied
     * @return array<int,string>
     */
    public function postanschriftZeilen(array $mitglied): array
    {
        $zeilen = [$this->adresszeile($mitglied)];

        $strasse = trim((string) ($mitglied['strasse'] ?? ''));
        if ($strasse !== '') {
            $zeilen[] = $strasse;
        }

        $plzOrt = trim(trim((string) ($mitglied['plz'] ?? '')) . ' ' . trim((string) ($mitglied['ort'] ?? '')));
        if ($plzOrt !== '') {
            $zeilen[] = $plzOrt;
        }

        $land = strtoupper(trim((string) ($mitglied['land'] ?? 'DE')));
        if ($land !== '' && $land !== 'DE') {
            $zeilen[] = Laender::name($land);
        }

        return array_values(array_filter($zeilen, static fn (string $z): bool => $z !== ''));
    }

    /**
     * @param array<string,mixed> $mitglied
     */
    public function postanschrift(array $mitglied): string
    {
        return implode("\n", $this->postanschriftZeilen($mitglied));
    }
}
