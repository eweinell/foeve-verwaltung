<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Status eines SEPA-Mandats (KONZEPT §3.3): erteilt → aktiv → inaktiv/widerrufen.
 */
final class Mandatsstatus
{
    public const ERTEILT    = 'erteilt';
    public const AKTIV      = 'aktiv';
    public const INAKTIV    = 'inaktiv';
    public const WIDERRUFEN = 'widerrufen';

    /** @var array<string,string> */
    private const LABELS = [
        self::ERTEILT    => 'erteilt',
        self::AKTIV      => 'aktiv',
        self::INAKTIV    => 'inaktiv',
        self::WIDERRUFEN => 'widerrufen',
    ];

    /** @var array<string,string> */
    private const BADGES = [
        self::ERTEILT    => 'badge-info',
        self::AKTIV      => 'badge-erfolg',
        self::INAKTIV    => 'badge-neutral',
        self::WIDERRUFEN => 'badge-fehler',
    ];

    /** Ein Mandat verfällt nach 36 Monaten ohne Nutzung (SEPA-Regel). */
    public const VERFALL_MONATE = 36;

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }

    public static function badge(string $status): string
    {
        return self::BADGES[$status] ?? 'badge-neutral';
    }
}
