<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Länderbezug für Adressen und IBAN-Validierung. Herzogenrath liegt im
 * Dreiländereck — DE/BE/NL sind prominent, übrige SEPA-Länder wählbar (F1).
 * Deutsche Ländernamen für die Postanschrift, IBAN-Längen je Land für die
 * Prüfziffernvalidierung.
 */
final class Laender
{
    /**
     * Prominente Länder zuerst (für Auswahlfelder).
     *
     * @var array<string,string> ISO-3166-alpha-2 => deutscher Name
     */
    public const NAMEN = [
        'DE' => 'Deutschland',
        'BE' => 'Belgien',
        'NL' => 'Niederlande',
        'AT' => 'Österreich',
        'FR' => 'Frankreich',
        'LU' => 'Luxemburg',
        'CH' => 'Schweiz',
        'IT' => 'Italien',
        'ES' => 'Spanien',
        'PT' => 'Portugal',
        'PL' => 'Polen',
        'DK' => 'Dänemark',
        'SE' => 'Schweden',
        'FI' => 'Finnland',
        'IE' => 'Irland',
        'CZ' => 'Tschechien',
        'SK' => 'Slowakei',
        'SI' => 'Slowenien',
        'HU' => 'Ungarn',
        'HR' => 'Kroatien',
        'EE' => 'Estland',
        'LV' => 'Lettland',
        'LT' => 'Litauen',
        'GR' => 'Griechenland',
        'BG' => 'Bulgarien',
        'RO' => 'Rumänien',
        'NO' => 'Norwegen',
        'LI' => 'Liechtenstein',
        'MT' => 'Malta',
        'CY' => 'Zypern',
        'IS' => 'Island',
        'MC' => 'Monaco',
        'SM' => 'San Marino',
    ];

    /**
     * IBAN-Gesamtlänge je Land (SEPA-Raum, für Längenprüfung).
     *
     * @var array<string,int>
     */
    public const IBAN_LAENGE = [
        'AD' => 24, 'AT' => 20, 'BE' => 16, 'BG' => 22, 'CH' => 21, 'CY' => 28,
        'CZ' => 24, 'DE' => 22, 'DK' => 18, 'EE' => 20, 'ES' => 24, 'FI' => 18,
        'FR' => 27, 'GB' => 22, 'GR' => 27, 'HR' => 21, 'HU' => 28, 'IE' => 22,
        'IS' => 26, 'IT' => 27, 'LI' => 21, 'LT' => 20, 'LU' => 20, 'LV' => 21,
        'MC' => 27, 'MT' => 31, 'NL' => 18, 'NO' => 15, 'PL' => 28, 'PT' => 25,
        'RO' => 24, 'SE' => 24, 'SI' => 19, 'SK' => 24, 'SM' => 27,
    ];

    public static function name(string $isoCode): string
    {
        $code = strtoupper($isoCode);

        return self::NAMEN[$code] ?? $code;
    }

    public static function bekannt(string $isoCode): bool
    {
        return isset(self::NAMEN[strtoupper($isoCode)]);
    }
}
