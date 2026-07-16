<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Status-Lebenszyklus eines Mitglieds (KONZEPT §3.1). Definiert die erlaubten
 * Übergänge (Statusmaschine) sowie Beschriftungen und Badge-Klassen für die UI.
 */
final class Mitgliedsstatus
{
    public const UNBESTAETIGT  = 'unbestaetigt';
    public const BEANTRAGT     = 'beantragt';
    public const ABGELEHNT     = 'abgelehnt';
    public const VERWORFEN     = 'verworfen';
    public const AKTIV         = 'aktiv';
    public const GEKUENDIGT    = 'gekuendigt';
    public const AUSGESCHIEDEN = 'ausgeschieden';
    public const ANONYMISIERT  = 'anonymisiert';

    /**
     * Erlaubte Zielzustände je Ausgangszustand.
     *
     * @var array<string,array<int,string>>
     */
    private const UEBERGAENGE = [
        self::UNBESTAETIGT  => [self::BEANTRAGT, self::VERWORFEN],
        self::BEANTRAGT     => [self::AKTIV, self::ABGELEHNT],
        self::AKTIV         => [self::GEKUENDIGT, self::AUSGESCHIEDEN],
        self::GEKUENDIGT    => [self::AKTIV, self::AUSGESCHIEDEN],
        self::AUSGESCHIEDEN => [self::ANONYMISIERT],
        self::ABGELEHNT     => [],
        self::VERWORFEN     => [],
        self::ANONYMISIERT  => [],
    ];

    /** @var array<string,string> */
    private const LABELS = [
        self::UNBESTAETIGT  => 'unbestätigt',
        self::BEANTRAGT     => 'beantragt',
        self::ABGELEHNT     => 'abgelehnt',
        self::VERWORFEN     => 'verworfen',
        self::AKTIV         => 'aktiv',
        self::GEKUENDIGT    => 'gekündigt',
        self::AUSGESCHIEDEN => 'ausgeschieden',
        self::ANONYMISIERT  => 'anonymisiert',
    ];

    /** @var array<string,string> Badge-Klassen aus dem Styleguide */
    private const BADGES = [
        self::UNBESTAETIGT  => 'badge-neutral',
        self::BEANTRAGT     => 'badge-info',
        self::ABGELEHNT     => 'badge-fehler',
        self::VERWORFEN     => 'badge-neutral',
        self::AKTIV         => 'badge-erfolg',
        self::GEKUENDIGT    => 'badge-warn',
        self::AUSGESCHIEDEN => 'badge-neutral',
        self::ANONYMISIERT  => 'badge-neutral',
    ];

    public static function darfWechseln(string $von, string $nach): bool
    {
        return in_array($nach, self::UEBERGAENGE[$von] ?? [], true);
    }

    /**
     * @throws \DomainException wenn der Übergang nicht erlaubt ist
     */
    public static function pruefeWechsel(string $von, string $nach): void
    {
        if (!self::darfWechseln($von, $nach)) {
            throw new \DomainException(sprintf('Statuswechsel von %s nach %s ist nicht erlaubt.', $von, $nach));
        }
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
     * @return array<string,string> status => Label
     */
    public static function alle(): array
    {
        return self::LABELS;
    }
}
