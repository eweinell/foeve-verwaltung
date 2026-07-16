<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Statusmaschine eines Einzugslaufs (KONZEPT F5, AP3): vorwärts-only.
 * entwurf → angekuendigt → exportiert → abgeschlossen. Abbruch nur durch Löschen
 * aus entwurf/angekuendigt (gibt die Forderungen wieder frei).
 */
final class Einzugslaufstatus
{
    public const ENTWURF      = 'entwurf';
    public const ANGEKUENDIGT = 'angekuendigt';
    public const EXPORTIERT   = 'exportiert';
    public const ABGESCHLOSSEN = 'abgeschlossen';

    /** @var array<string,array<int,string>> */
    private const UEBERGAENGE = [
        self::ENTWURF       => [self::ANGEKUENDIGT],
        self::ANGEKUENDIGT  => [self::EXPORTIERT],
        self::EXPORTIERT    => [self::ABGESCHLOSSEN],
        self::ABGESCHLOSSEN => [],
    ];

    /** @var array<string,string> */
    private const LABELS = [
        self::ENTWURF       => 'Entwurf',
        self::ANGEKUENDIGT  => 'angekündigt',
        self::EXPORTIERT    => 'exportiert',
        self::ABGESCHLOSSEN => 'abgeschlossen',
    ];

    /** @var array<string,string> */
    private const BADGES = [
        self::ENTWURF       => 'badge-neutral',
        self::ANGEKUENDIGT  => 'badge-info',
        self::EXPORTIERT    => 'badge-warn',
        self::ABGESCHLOSSEN => 'badge-erfolg',
    ];

    public static function darfWechseln(string $von, string $nach): bool
    {
        return in_array($nach, self::UEBERGAENGE[$von] ?? [], true);
    }

    public static function pruefeWechsel(string $von, string $nach): void
    {
        if (!self::darfWechseln($von, $nach)) {
            throw new \DomainException(sprintf('Statuswechsel des Einzugslaufs von %s nach %s ist nicht erlaubt.', $von, $nach));
        }
    }

    public static function darfGeloeschtWerden(string $status): bool
    {
        return in_array($status, [self::ENTWURF, self::ANGEKUENDIGT], true);
    }

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }

    public static function badge(string $status): string
    {
        return self::BADGES[$status] ?? 'badge-neutral';
    }
}
