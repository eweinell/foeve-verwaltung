<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Zentrale Validierung für PLZ (länderspezifisch) und IBAN (Prüfziffer MOD-97
 * für alle SEPA-Länder) — F1/F2. Grenzgänger mit BE-/NL-Konten werden akzeptiert.
 */
final class Validierung
{
    /**
     * Prüft die PLZ passend zum Land: DE 5 Ziffern, BE 4 Ziffern, NL „1234 AB";
     * andere Länder: nur nicht-leer.
     */
    public function plzGueltig(string $land, string $plz): bool
    {
        $land = strtoupper(trim($land));
        $plz = trim($plz);

        return match ($land) {
            'DE' => preg_match('/^\d{5}$/', $plz) === 1,
            'BE' => preg_match('/^\d{4}$/', $plz) === 1,
            'NL' => preg_match('/^\d{4}\s?[A-Za-z]{2}$/', $plz) === 1,
            default => $plz !== '',
        };
    }

    /**
     * Normalisiert eine NL-PLZ auf die Form „1234 AB" (Großbuchstaben, ein Leerzeichen).
     * Andere Länder werden nur getrimmt.
     */
    public function plzNormalisieren(string $land, string $plz): string
    {
        $plz = trim($plz);
        if (strtoupper(trim($land)) !== 'NL') {
            return $plz;
        }
        if (preg_match('/^(\d{4})\s?([A-Za-z]{2})$/', $plz, $m) === 1) {
            return $m[1] . ' ' . strtoupper($m[2]);
        }

        return $plz;
    }

    /**
     * IBAN-Prüfung: Länge je Land (falls bekannt) + Prüfziffer nach ISO 7064 (MOD-97).
     */
    public function ibanGueltig(string $iban): bool
    {
        $normal = $this->ibanNormalisieren($iban);

        if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]+$/', $normal) !== 1) {
            return false;
        }

        $land = substr($normal, 0, 2);
        $erwartet = Laender::IBAN_LAENGE[$land] ?? null;
        if ($erwartet !== null && strlen($normal) !== $erwartet) {
            return false;
        }
        // Grober Rahmen auch für unbekannte Länder.
        if (strlen($normal) < 15 || strlen($normal) > 34) {
            return false;
        }

        return $this->mod97($normal) === 1;
    }

    public function ibanNormalisieren(string $iban): string
    {
        return strtoupper(preg_replace('/\s+/', '', $iban) ?? '');
    }

    /**
     * IBAN für die Eingabe-/Anzeige gruppieren (Vierergruppen). Nur für UI —
     * gespeichert wird verschlüsselt, angezeigt maskiert.
     */
    public function ibanGruppieren(string $iban): string
    {
        $normal = $this->ibanNormalisieren($iban);

        return trim(chunk_split($normal, 4, ' '));
    }

    private function mod97(string $iban): int
    {
        // Erste vier Zeichen ans Ende, Buchstaben → Zahlen (A=10 … Z=35).
        $umgestellt = substr($iban, 4) . substr($iban, 0, 4);

        $zahl = '';
        foreach (str_split($umgestellt) as $zeichen) {
            $zahl .= ctype_alpha($zeichen) ? (string) (ord($zeichen) - 55) : $zeichen;
        }

        // Stückweiser Modulo, da die Zahl sehr lang werden kann.
        $rest = 0;
        foreach (str_split($zahl) as $ziffer) {
            $rest = ($rest * 10 + (int) $ziffer) % 97;
        }

        return $rest;
    }
}
