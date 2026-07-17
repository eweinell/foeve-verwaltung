<?php

declare(strict_types=1);

namespace App\Repository;

use App\Support\Db;

/**
 * Zugriff auf antrag_rohdaten — der DOI-/Mandatsnachweis eines Online-Antrags
 * (Payload JSON mit maskierter IBAN, IP-Hash, Bestätigungstoken). F2.
 */
final class AntragRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function anlegen(int $mitgliedId, array $payload, ?string $ipHash, string $token, string $resendToken): int
    {
        $this->db->ausfuehren(
            'INSERT INTO antrag_rohdaten (mitglied_id, eingegangen_am, ip_hash, payload, bestaetigungs_token, resend_token)
             VALUES (:mid, :am, :ip, :payload, :token, :resend)',
            [
                'mid'     => $mitgliedId,
                'am'      => $this->jetzt(),
                'ip'      => $ipHash,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'token'   => $token,
                'resend'  => $resendToken,
            ],
        );

        return (int) $this->db->letzteId();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findePerToken(string $token): ?array
    {
        return $this->db->eineZeile('SELECT * FROM antrag_rohdaten WHERE bestaetigungs_token = :t', ['t' => $token]);
    }

    /**
     * Antrag über das Warteseiten-Token. Getrennt vom Bestätigungstoken, damit
     * dieses nicht in der URL der Warteseite landet.
     *
     * @return array<string,mixed>|null
     */
    public function findePerResendToken(string $resendToken): ?array
    {
        return $this->db->eineZeile('SELECT * FROM antrag_rohdaten WHERE resend_token = :t', ['t' => $resendToken]);
    }

    /**
     * Zeitpunkt des letzten Nachsendens (für die Sperrfrist auf der Warteseite).
     */
    public function markiereErneutGesendet(int $id): void
    {
        $this->db->ausfuehren(
            'UPDATE antrag_rohdaten SET erneut_gesendet_am = :am WHERE id = :id',
            ['am' => $this->jetzt(), 'id' => $id],
        );
    }

    /**
     * Jüngster Antrag eines Mitglieds (für die Mandatsanlage bei Aktivierung).
     *
     * @return array<string,mixed>|null
     */
    public function neuesterFuerMitglied(int $mitgliedId): ?array
    {
        return $this->db->eineZeile(
            'SELECT * FROM antrag_rohdaten WHERE mitglied_id = :id ORDER BY id DESC LIMIT 1',
            ['id' => $mitgliedId],
        );
    }

    public function markiereBestaetigt(int $id): void
    {
        $this->db->ausfuehren(
            'UPDATE antrag_rohdaten SET bestaetigt_am = :am WHERE id = :id',
            ['am' => $this->jetzt(), 'id' => $id],
        );
    }

    /**
     * Token entwerten (nach Verwerfen unbestätigter Anträge). Das Warteseiten-Token
     * fällt mit weg, sonst bliebe die Warteseite eines verworfenen Antrags nutzbar.
     */
    public function tokenLoeschen(int $mitgliedId): void
    {
        $this->db->ausfuehren(
            'UPDATE antrag_rohdaten SET bestaetigungs_token = :neu, resend_token = NULL WHERE mitglied_id = :mid',
            ['neu' => 'verworfen-' . bin2hex(random_bytes(16)), 'mid' => $mitgliedId],
        );
    }

    /**
     * Anzahl Anträge einer IP in den letzten $stunden Stunden (Rate-Limit).
     */
    public function anzahlProIpSeit(string $ipHash, int $stunden): int
    {
        $grenze = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))
            ->modify("-{$stunden} hours")->format('Y-m-d H:i:s');

        return (int) $this->db->einWert(
            'SELECT COUNT(*) FROM antrag_rohdaten WHERE ip_hash = :ip AND eingegangen_am >= :grenze',
            ['ip' => $ipHash, 'grenze' => $grenze],
        );
    }

    private function jetzt(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d H:i:s');
    }
}
