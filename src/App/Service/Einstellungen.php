<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\Db;

/**
 * Key-Value-Einstellungen (Tabelle einstellung). In AP0: mail_rate_pro_minute,
 * Absendername/-adresse. Weitere (CI, Vereins-IBAN, Beitragsgrenzen …) kommen
 * in späteren APs hinzu.
 */
final class Einstellungen
{
    /** @var array<string,string>|null */
    private ?array $cache = null;

    public function __construct(private readonly Db $db)
    {
    }

    public function hole(string $schluessel, string $default = ''): string
    {
        $this->laden();

        return $this->cache[$schluessel] ?? $default;
    }

    public function holeInt(string $schluessel, int $default): int
    {
        $wert = $this->hole($schluessel, (string) $default);

        return is_numeric($wert) ? (int) $wert : $default;
    }

    public function setze(string $schluessel, string $wert): void
    {
        // Portables Upsert ohne DB-spezifische Syntax.
        $vorhanden = $this->db->einWert(
            'SELECT 1 FROM einstellung WHERE schluessel = :s',
            ['s' => $schluessel],
        );
        if ($vorhanden !== null) {
            $this->db->ausfuehren('UPDATE einstellung SET wert = :w WHERE schluessel = :s', ['w' => $wert, 's' => $schluessel]);
        } else {
            $this->db->ausfuehren('INSERT INTO einstellung (schluessel, wert) VALUES (:s, :w)', ['s' => $schluessel, 'w' => $wert]);
        }
        $this->cache = null;
    }

    /**
     * @return array<string,string>
     */
    public function alle(): array
    {
        $this->laden();

        return $this->cache ?? [];
    }

    private function laden(): void
    {
        if ($this->cache !== null) {
            return;
        }
        $this->cache = [];
        foreach ($this->db->alleZeilen('SELECT schluessel, wert FROM einstellung') as $zeile) {
            $this->cache[(string) $zeile['schluessel']] = (string) $zeile['wert'];
        }
    }
}
