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

        // Priorität „sofort" (0) vor Massenversand, dann FIFO. Für den Retry
        // wartende Mails erst nach naechster_versuch berücksichtigen.
        return $this->db->alleZeilen(
            "SELECT * FROM email_queue
              WHERE status = 'wartend'
                AND geplant_ab <= :jetzt
                AND (naechster_versuch IS NULL OR naechster_versuch <= :jetzt2)
              ORDER BY prioritaet ASC, geplant_ab ASC, id ASC
              LIMIT {$limit}",
            ['jetzt' => $jetzt, 'jetzt2' => $jetzt],
        );
    }

    /**
     * Verarbeitet bis zu $limit wartende Mails. $sender liefert je Mail
     * ['status' => 'ok'|'temp'|'perm', 'fehltext' => string]. Temporäre Fehler
     * (4xx) werden einmal nach 15 Minuten wiederholt, danach als Fehler markiert.
     *
     * @param callable(array<string,mixed>):array{status:string,fehltext?:string} $sender
     * @return array{gesendet:int,fehler:int,wiederholung:int}
     */
    public function verarbeite(int $limit, callable $sender): array
    {
        $gesendet = 0;
        $fehler = 0;
        $wiederholung = 0;

        foreach ($this->naechsteWartende($limit) as $mail) {
            $ergebnis = $sender($mail);
            $status = $ergebnis['status'] ?? 'perm';
            $fehltext = (string) ($ergebnis['fehltext'] ?? '');

            if ($status === 'ok') {
                $this->alsGesendet($mail['id']);
                $gesendet++;
            } elseif ($status === 'temp' && (int) $mail['versuche'] < 1) {
                $this->planeWiederholung($mail['id'], $fehltext);
                $wiederholung++;
            } else {
                $this->alsFehler($mail['id'], $fehltext);
                $fehler++;
            }
        }

        return ['gesendet' => $gesendet, 'fehler' => $fehler, 'wiederholung' => $wiederholung];
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

    /**
     * Plant eine Mail nach temporärem Fehler in 15 Minuten erneut ein.
     */
    public function planeWiederholung(int|string $id, string $fehltext, int $minuten = 15): void
    {
        $naechster = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))
            ->modify("+{$minuten} minutes")->format('Y-m-d H:i:s');
        $this->db->ausfuehren(
            "UPDATE email_queue
                SET status = 'wartend', versuche = versuche + 1, naechster_versuch = :nv, fehltext = :txt
              WHERE id = :id",
            ['nv' => $naechster, 'txt' => mb_substr($fehltext, 0, 500), 'id' => $id],
        );
    }

    /**
     * Fehlgeschlagene Mails wieder einreihen (einzeln oder alle einer Versandaktion).
     *
     * @return int Anzahl neu eingereihter Mails
     */
    public function fehlerNeuEinreihen(?int $versandaktionId = null, ?int $mailId = null): int
    {
        $jetzt = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d H:i:s');
        if ($mailId !== null) {
            $stmt = $this->db->ausfuehren(
                "UPDATE email_queue SET status = 'wartend', naechster_versuch = NULL, versuche = 0, geplant_ab = :g WHERE id = :id AND status = 'fehler'",
                ['g' => $jetzt, 'id' => $mailId],
            );

            return $stmt->rowCount();
        }
        if ($versandaktionId !== null) {
            $stmt = $this->db->ausfuehren(
                "UPDATE email_queue SET status = 'wartend', naechster_versuch = NULL, versuche = 0, geplant_ab = :g WHERE versandaktion_id = :v AND status = 'fehler'",
                ['g' => $jetzt, 'v' => $versandaktionId],
            );

            return $stmt->rowCount();
        }

        return 0;
    }
}
