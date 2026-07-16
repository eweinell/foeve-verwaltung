<?php

declare(strict_types=1);

namespace App\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Minimaler PSR-3-Logger: schreibt nach var/log/app.log (eine Zeile je Eintrag).
 * Bewusst ohne Monolog — das System soll mit minimaler Pflege laufen (CLAUDE.md).
 * Keine Secrets loggen (Regel 9) — das ist Aufgabe der Aufrufer.
 */
final class DateiLogger extends AbstractLogger
{
    public function __construct(private readonly string $verzeichnis)
    {
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (!is_dir($this->verzeichnis)) {
            @mkdir($this->verzeichnis, 0770, true);
        }

        $zeit = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d H:i:s');
        $text = $this->platzhalterErsetzen((string) $message, $context);
        $extra = $context !== [] ? ' ' . json_encode($this->skalar($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';

        $zeile = sprintf('[%s] %s: %s%s%s', $zeit, strtoupper((string) $level), $text, $extra, PHP_EOL);
        @file_put_contents($this->verzeichnis . '/app.log', $zeile, FILE_APPEND | LOCK_EX);
    }

    /**
     * @param array<string,mixed> $context
     */
    private function platzhalterErsetzen(string $nachricht, array $context): string
    {
        $ersatz = [];
        foreach ($context as $key => $wert) {
            if (is_scalar($wert) || $wert instanceof Stringable) {
                $ersatz['{' . $key . '}'] = (string) $wert;
            }
        }

        return strtr($nachricht, $ersatz);
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function skalar(array $context): array
    {
        $aus = [];
        foreach ($context as $key => $wert) {
            $aus[$key] = (is_scalar($wert) || $wert === null) ? $wert : gettype($wert);
        }

        return $aus;
    }
}
