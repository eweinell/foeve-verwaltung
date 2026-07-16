<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Dünne Kapselung der PHP-Session mit gehärteten Cookie-Parametern
 * (HttpOnly, Secure, SameSite=Lax — CLAUDE.md Regel 6).
 */
final class Session
{
    private bool $gestartet = false;

    public function __construct(private readonly bool $secure)
    {
    }

    public function starten(): void
    {
        if ($this->gestartet || session_status() === PHP_SESSION_ACTIVE) {
            $this->gestartet = true;

            return;
        }

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'secure'   => $this->secure,
            'samesite' => 'Lax',
        ]);
        session_name('foeve_sess');
        session_start();
        $this->gestartet = true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $wert): void
    {
        $_SESSION[$key] = $wert;
    }

    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public function loeschen(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * Session-ID nach Login neu erzeugen (Session-Fixation-Schutz).
     */
    public function regenerieren(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            session_regenerate_id(true);
        }
    }

    public function beenden(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}
