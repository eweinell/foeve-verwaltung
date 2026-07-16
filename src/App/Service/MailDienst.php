<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\Db;

/**
 * Mail-Queue-Basis (F6, Minimalausbau — Vollausbau in AP4). Jede Mail wird in
 * email_queue eingereiht (CLAUDE.md Regel 4: nie direkt aus dem Request senden).
 * Der Versand erfolgt gedrosselt über bin/mailqueue.php (Cron).
 *
 * Priorität „sofort" (0) für 2FA-Codes und DOI; normale Mails (1) danach.
 */
final class MailDienst
{
    public const PRIO_SOFORT = 0;
    public const PRIO_NORMAL = 1;

    public function __construct(private readonly Db $db)
    {
    }

    public function einreihen(
        string $empfaenger,
        string $betreff,
        string $textBody,
        ?string $htmlBody = null,
        ?string $anhangPfad = null,
        ?int $mitgliedId = null,
        ?int $versandaktionId = null,
        int $prioritaet = self::PRIO_NORMAL,
    ): string {
        $jetzt = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d H:i:s');

        $this->db->ausfuehren(
            'INSERT INTO email_queue
                (mitglied_id, versandaktion_id, empfaenger, betreff, body, body_html, anhang_pfad,
                 prioritaet, status, versuche, geplant_ab)
             VALUES
                (:mid, :vid, :empf, :betreff, :body, :html, :anhang,
                 :prio, \'wartend\', 0, :geplant)',
            [
                'mid'     => $mitgliedId,
                'vid'     => $versandaktionId,
                'empf'    => $empfaenger,
                'betreff' => $betreff,
                'body'    => $textBody,
                'html'    => $htmlBody,
                'anhang'  => $anhangPfad,
                'prio'    => $prioritaet,
                'geplant' => $jetzt,
            ],
        );

        return $this->db->letzteId();
    }

    /**
     * Nächste versandbereite Mails, Priorität „sofort" zuerst, dann FIFO.
     *
     * @return array<int,array<string,mixed>>
     */
    public function naechsteWartende(int $limit): array
    {
        $jetzt = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d H:i:s');
        $limit = max(1, $limit);

        return $this->db->alleZeilen(
            "SELECT * FROM email_queue
              WHERE status = 'wartend' AND geplant_ab <= :jetzt
              ORDER BY prioritaet ASC, geplant_ab ASC, id ASC
              LIMIT {$limit}",
            ['jetzt' => $jetzt],
        );
    }

    public function alsGesendet(int|string $id): void
    {
        $jetzt = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d H:i:s');
        $this->db->ausfuehren(
            "UPDATE email_queue SET status = 'gesendet', gesendet_am = :am, fehltext = NULL WHERE id = :id",
            ['am' => $jetzt, 'id' => $id],
        );
    }

    public function alsFehler(int|string $id, string $fehltext): void
    {
        $this->db->ausfuehren(
            "UPDATE email_queue
                SET status = 'fehler', versuche = versuche + 1, fehltext = :txt
              WHERE id = :id",
            ['txt' => mb_substr($fehltext, 0, 500), 'id' => $id],
        );
    }
}
