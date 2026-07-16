<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Status einer Forderung / eines offenen Postens (KONZEPT §3.4). Forderungen sind
 * unveränderlich: Korrektur nur per Storno (kein DELETE) — CLAUDE.md Regel 2.
 */
final class Forderungsstatus
{
    public const OFFEN            = 'offen';
    public const IM_EINZUG        = 'im_einzug';
    public const BEZAHLT          = 'bezahlt';
    public const RUECKLASTSCHRIFT = 'ruecklastschrift';
    public const STORNIERT        = 'storniert';

    public const TYP_BEITRAG = 'beitrag';
    public const TYP_GEBUEHR = 'gebuehr';

    /** @var array<string,string> */
    private const LABELS = [
        self::OFFEN            => 'offen',
        self::IM_EINZUG        => 'im Einzug',
        self::BEZAHLT          => 'bezahlt',
        self::RUECKLASTSCHRIFT => 'Rücklastschrift',
        self::STORNIERT        => 'storniert',
    ];

    /** @var array<string,string> */
    private const BADGES = [
        self::OFFEN            => 'badge-info',
        self::IM_EINZUG        => 'badge-warn',
        self::BEZAHLT          => 'badge-erfolg',
        self::RUECKLASTSCHRIFT => 'badge-fehler',
        self::STORNIERT        => 'badge-neutral',
    ];

    /** Status, in denen eine Forderung nicht mehr verändert werden darf (§3.5). */
    public static function istAbgeschlossen(string $status): bool
    {
        return in_array($status, [self::IM_EINZUG, self::BEZAHLT], true);
    }

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }

    public static function badge(string $status): string
    {
        return self::BADGES[$status] ?? 'badge-neutral';
    }

    /**
     * @return array<string,string>
     */
    public static function alle(): array
    {
        return self::LABELS;
    }
}
