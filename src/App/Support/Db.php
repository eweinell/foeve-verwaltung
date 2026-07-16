<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

/**
 * Schlanker PDO-Wrapper (kein ORM, CLAUDE.md). Prepared Statements überall,
 * Exceptions statt stiller Fehler. Zeitzone der Verbindung auf Europe/Berlin.
 */
final class Db
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function ausDsn(string $dsn, string $user, string $pass): self
    {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        // Verbindungszeitzone auf Europe/Berlin (CLAUDE.md Regel 8).
        // Nur für MySQL/MariaDB sinnvoll; bei SQLite (Tests) übersprungen.
        if (str_starts_with($dsn, 'mysql:')) {
            // +02:00/+01:00 je nach Sommerzeit — Named Zone falls Tabellen geladen,
            // sonst fester Offset als Fallback.
            $offset = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('P');
            $pdo->exec("SET time_zone = '{$offset}'");
        }

        return new self($pdo);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * @param array<string,mixed> $params
     */
    public function ausfuehren(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt;
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>|null
     */
    public function eineZeile(string $sql, array $params = []): ?array
    {
        $zeile = $this->ausfuehren($sql, $params)->fetch();

        return $zeile === false ? null : $zeile;
    }

    /**
     * @param array<string,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    public function alleZeilen(string $sql, array $params = []): array
    {
        return $this->ausfuehren($sql, $params)->fetchAll();
    }

    /**
     * @param array<string,mixed> $params
     */
    public function einWert(string $sql, array $params = []): mixed
    {
        $wert = $this->ausfuehren($sql, $params)->fetchColumn();

        return $wert === false ? null : $wert;
    }

    public function letzteId(): string
    {
        return $this->pdo->lastInsertId();
    }

    public function inTransaktion(callable $fn): mixed
    {
        // Verschachtelte Transaktionen vermeiden: nur die äußerste steuert Commit/Rollback.
        if ($this->pdo->inTransaction()) {
            return $fn($this);
        }

        $this->pdo->beginTransaction();
        try {
            $ergebnis = $fn($this);
            $this->pdo->commit();

            return $ergebnis;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
