<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Passwort-Hashing mit Argon2id (CLAUDE.md Regel 6). Zentralisiert, damit der
 * Algorithmus an einer Stelle festgelegt ist.
 */
final class Passwoerter
{
    public static function hash(string $klartext): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;

        return password_hash($klartext, $algo);
    }

    public static function pruefe(string $klartext, string $hash): bool
    {
        return password_verify($klartext, $hash);
    }

    public static function mussNeuGehashtWerden(string $hash): bool
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;

        return password_needs_rehash($hash, $algo);
    }
}
