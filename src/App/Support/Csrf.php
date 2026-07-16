<?php

declare(strict_types=1);

namespace App\Support;

/**
 * CSRF-Token in der Session (CLAUDE.md Regel 6). Ein Token pro Session,
 * Vergleich in konstanter Zeit. Genutzt von CsrfMiddleware und als Twig-Helfer.
 */
final class Csrf
{
    private const SESSION_KEY = '_csrf_token';
    public const FELDNAME = '_csrf';

    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get(self::SESSION_KEY);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->session->set(self::SESSION_KEY, $token);
        }

        return $token;
    }

    public function pruefe(?string $eingabe): bool
    {
        $token = $this->session->get(self::SESSION_KEY);

        return is_string($token) && is_string($eingabe) && hash_equals($token, $eingabe);
    }
}
