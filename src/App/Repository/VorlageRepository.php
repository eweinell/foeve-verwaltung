<?php

declare(strict_types=1);

namespace App\Repository;

use App\Support\Db;

/**
 * Zugriff auf email_vorlage (Overrides der Systemvorlagen + eigene Vorlagen).
 */
final class VorlageRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findePerSchluessel(string $schluessel): ?array
    {
        return $this->db->eineZeile('SELECT * FROM email_vorlage WHERE schluessel = :s', ['s' => $schluessel]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function alle(): array
    {
        return $this->db->alleZeilen('SELECT * FROM email_vorlage ORDER BY schluessel');
    }

    public function speichern(string $schluessel, string $betreff, string $bodyText, ?string $bodyHtml, bool $system): void
    {
        $jetzt = $this->jetzt();
        if ($this->findePerSchluessel($schluessel) !== null) {
            $this->db->ausfuehren(
                'UPDATE email_vorlage SET betreff = :b, body_text = :t, body_html = :h, updated_at = :u WHERE schluessel = :s',
                ['b' => $betreff, 't' => $bodyText, 'h' => $bodyHtml, 'u' => $jetzt, 's' => $schluessel],
            );

            return;
        }
        $this->db->ausfuehren(
            'INSERT INTO email_vorlage (schluessel, betreff, body_text, body_html, system, created_at, updated_at)
             VALUES (:s, :b, :t, :h, :sys, :c, :u)',
            ['s' => $schluessel, 'b' => $betreff, 't' => $bodyText, 'h' => $bodyHtml, 'sys' => $system ? 1 : 0, 'c' => $jetzt, 'u' => $jetzt],
        );
    }

    public function loeschen(string $schluessel): void
    {
        $this->db->ausfuehren('DELETE FROM email_vorlage WHERE schluessel = :s', ['s' => $schluessel]);
    }

    private function jetzt(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d H:i:s');
    }
}
