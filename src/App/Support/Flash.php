<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Flash-Messages über die Session: einmalig anzeigen, dann verbrauchen.
 * Typen entsprechen den Alert-Klassen im Styleguide: success/error/warn/info.
 */
final class Flash
{
    private const SESSION_KEY = '_flash';

    public function __construct(private readonly Session $session)
    {
    }

    public function erfolg(string $text): void
    {
        $this->hinzufuegen('success', $text);
    }

    public function fehler(string $text): void
    {
        $this->hinzufuegen('error', $text);
    }

    public function warnung(string $text): void
    {
        $this->hinzufuegen('warn', $text);
    }

    public function info(string $text): void
    {
        $this->hinzufuegen('info', $text);
    }

    public function hinzufuegen(string $typ, string $text): void
    {
        $alle = $this->session->get(self::SESSION_KEY, []);
        $alle[] = ['typ' => $typ, 'text' => $text];
        $this->session->set(self::SESSION_KEY, $alle);
    }

    /**
     * @return array<int,array{typ:string,text:string}>
     */
    public function abholen(): array
    {
        $alle = $this->session->get(self::SESSION_KEY, []);
        $this->session->loeschen(self::SESSION_KEY);

        return is_array($alle) ? $alle : [];
    }
}
