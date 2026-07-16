<?php

declare(strict_types=1);

namespace App\Repository;

use App\Support\Db;

/**
 * Zugriff auf die Tabelle benutzer. Passwörter werden hier nie geloggt und nur
 * als Argon2id-Hash gespeichert.
 */
final class BenutzerRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findePerId(int $id): ?array
    {
        return $this->db->eineZeile('SELECT * FROM benutzer WHERE id = :id', ['id' => $id]);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findePerEmail(string $email): ?array
    {
        return $this->db->eineZeile('SELECT * FROM benutzer WHERE email = :e', ['e' => mb_strtolower(trim($email))]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function alle(): array
    {
        return $this->db->alleZeilen('SELECT * FROM benutzer ORDER BY name');
    }

    public function anlegen(
        string $name,
        string $email,
        string $passwortHash,
        string $rolle,
        bool $passwortAendernPflicht = true,
    ): string {
        $jetzt = $this->jetzt();
        $this->db->ausfuehren(
            'INSERT INTO benutzer
                (name, email, passwort_hash, rolle, aktiv, passwort_aendern_pflicht,
                 zwei_faktor_methode, fehlversuche, created_at, updated_at)
             VALUES (:name, :email, :hash, :rolle, 1, :pfl, \'keine\', 0, :now, :now)',
            [
                'name'  => $name,
                'email' => mb_strtolower(trim($email)),
                'hash'  => $passwortHash,
                'rolle' => $rolle,
                'pfl'   => $passwortAendernPflicht ? 1 : 0,
                'now'   => $jetzt,
            ],
        );

        return $this->db->letzteId();
    }

    public function aktualisiereStammdaten(int $id, string $name, string $email, string $rolle): void
    {
        $this->db->ausfuehren(
            'UPDATE benutzer SET name = :name, email = :email, rolle = :rolle, updated_at = :now WHERE id = :id',
            ['name' => $name, 'email' => mb_strtolower(trim($email)), 'rolle' => $rolle, 'now' => $this->jetzt(), 'id' => $id],
        );
    }

    public function setzeAktiv(int $id, bool $aktiv): void
    {
        $this->db->ausfuehren(
            'UPDATE benutzer SET aktiv = :a, updated_at = :now WHERE id = :id',
            ['a' => $aktiv ? 1 : 0, 'now' => $this->jetzt(), 'id' => $id],
        );
    }

    public function setzePasswort(int $id, string $passwortHash, bool $aendernPflicht): void
    {
        $this->db->ausfuehren(
            'UPDATE benutzer SET passwort_hash = :hash, passwort_aendern_pflicht = :pfl, updated_at = :now WHERE id = :id',
            ['hash' => $passwortHash, 'pfl' => $aendernPflicht ? 1 : 0, 'now' => $this->jetzt(), 'id' => $id],
        );
    }

    public function setzeZweiFaktor(int $id, string $methode, ?string $totpSecret): void
    {
        $this->db->ausfuehren(
            'UPDATE benutzer SET zwei_faktor_methode = :m, totp_secret = :s, updated_at = :now WHERE id = :id',
            ['m' => $methode, 's' => $totpSecret, 'now' => $this->jetzt(), 'id' => $id],
        );
    }

    public function setzeEmailCode(int $id, ?string $codeHash, ?string $gueltigBis): void
    {
        $this->db->ausfuehren(
            'UPDATE benutzer SET email_code_hash = :h, email_code_bis = :b WHERE id = :id',
            ['h' => $codeHash, 'b' => $gueltigBis, 'id' => $id],
        );
    }

    public function merkeLogin(int $id): void
    {
        $this->db->ausfuehren(
            'UPDATE benutzer SET letzter_login = :now, fehlversuche = 0, gesperrt_bis = NULL WHERE id = :id',
            ['now' => $this->jetzt(), 'id' => $id],
        );
    }

    public function erhoeheFehlversuche(int $id): int
    {
        $this->db->ausfuehren('UPDATE benutzer SET fehlversuche = fehlversuche + 1 WHERE id = :id', ['id' => $id]);

        return (int) $this->db->einWert('SELECT fehlversuche FROM benutzer WHERE id = :id', ['id' => $id]);
    }

    public function setzeSperre(int $id, ?string $gesperrtBis): void
    {
        $this->db->ausfuehren('UPDATE benutzer SET gesperrt_bis = :b WHERE id = :id', ['b' => $gesperrtBis, 'id' => $id]);
    }

    public function setzeFehlversuche(int $id, int $anzahl): void
    {
        $this->db->ausfuehren('UPDATE benutzer SET fehlversuche = :n WHERE id = :id', ['n' => $anzahl, 'id' => $id]);
    }

    public function anzahl(): int
    {
        return (int) $this->db->einWert('SELECT COUNT(*) FROM benutzer');
    }

    private function jetzt(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d H:i:s');
    }
}
