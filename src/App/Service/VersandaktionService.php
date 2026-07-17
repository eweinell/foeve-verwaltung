<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\MitgliedRepository;
use App\Support\Db;

/**
 * Versandaktionen / „Vereinspost" (F6): Massenversand an gefilterte Mitglieder
 * über die Queue, mit Vorlage oder Freitext und optionalem gemeinsamem PDF-Anhang.
 * Mitglieder ohne E-Mail (Flag „kein E-Mail-Kontakt" oder ohne Adresse) landen auf
 * der Post-Liste (Übergabe an den AP5-Export).
 */
final class VersandaktionService
{
    public function __construct(
        private readonly Db $db,
        private readonly MitgliedRepository $mitglieder,
        private readonly MailDienst $mail,
        private readonly VorlagenService $vorlagen,
        private readonly Audit $audit,
    ) {
    }

    /**
     * Empfänger eines Filters, aufgeteilt in E-Mail und Post.
     *
     * @param array<string,mixed> $filter
     * @return array{email:array<int,array<string,mixed>>,post:array<int,array<string,mixed>>,anzahl_email:int,anzahl_post:int}
     */
    public function empfaenger(array $filter): array
    {
        $email = [];
        $post = [];
        foreach ($this->mitglieder->alleGefiltert($filter) as $m) {
            if ($this->hatEmail($m)) {
                $email[] = $m;
            } else {
                $post[] = $m;
            }
        }

        return ['email' => $email, 'post' => $post, 'anzahl_email' => count($email), 'anzahl_post' => count($post)];
    }

    /**
     * Betreff/Text einer Aktion auflösen (Vorlage oder Freitext) und validieren.
     *
     * @return array{betreff:string,text:string,html:?string}
     */
    public function inhalt(?string $vorlageSchluessel, string $betreff, string $text): array
    {
        if ($vorlageSchluessel !== null && $vorlageSchluessel !== '') {
            $v = $this->vorlagen->hole($vorlageSchluessel);

            return ['betreff' => $v['betreff'], 'text' => $v['body_text'], 'html' => $v['body_html']];
        }
        // Freitext: gegen die Platzhalter-Whitelist prüfen (unbekannt ⇒ Fehler).
        $this->vorlagen->validiere($betreff, $text);

        return ['betreff' => $betreff, 'text' => $text, 'html' => null];
    }

    /**
     * Rendert eine Vorschau mit den echten Daten eines Beispiel-Mitglieds.
     *
     * @param array<string,mixed> $mitglied
     * @return array{betreff:string,text:string}
     */
    public function vorschau(?string $vorlageSchluessel, string $betreff, string $text, array $mitglied): array
    {
        $inhalt = $this->inhalt($vorlageSchluessel, $betreff, $text);
        $kontext = $this->vorlagen->kontext($mitglied);

        return [
            'betreff' => $this->vorlagen->render($inhalt['betreff'], $kontext),
            'text'    => $this->vorlagen->render($inhalt['text'], $kontext),
        ];
    }

    /**
     * Testmail an die eigene Adresse — startet die Aktion NICHT.
     *
     * @param array<string,mixed>|null $beispiel
     */
    public function testmail(string $anEmail, ?string $vorlageSchluessel, string $betreff, string $text, ?array $beispiel, ?string $anhangPfad): void
    {
        $inhalt = $this->inhalt($vorlageSchluessel, $betreff, $text);
        $kontext = $beispiel !== null ? $this->vorlagen->kontext($beispiel) : [];
        $this->mail->einreihen(
            $anEmail,
            '[Test] ' . $this->vorlagen->render($inhalt['betreff'], $kontext),
            $this->vorlagen->render($inhalt['text'], $kontext),
            $inhalt['html'] !== null ? $this->vorlagen->render($inhalt['html'], $kontext) : null,
            $anhangPfad,
            prioritaet: MailDienst::PRIO_SOFORT,
        );
    }

    /**
     * Startet eine Versandaktion: reiht je E-Mail-Empfänger eine gerenderte Mail ein.
     *
     * @param array<string,mixed> $filter
     * @return array{versandaktion_id:int,anzahl_email:int,anzahl_post:int}
     */
    public function starten(array $filter, string $typ, ?string $vorlageSchluessel, string $betreff, string $text, ?string $anhangPfad, ?int $benutzerId): array
    {
        $inhalt = $this->inhalt($vorlageSchluessel, $betreff, $text);
        $empfaenger = $this->empfaenger($filter);

        $jetzt = $this->jetzt();
        $this->db->ausfuehren(
            'INSERT INTO versandaktion (typ, vorlage_schluessel, betreff, anhang_pfad, erstellt_von, erstellt_am, anzahl_gesamt)
             VALUES (:typ, :vs, :betreff, :anhang, :von, :am, :gesamt)',
            [
                'typ'     => $typ,
                'vs'      => $vorlageSchluessel ?: null,
                'betreff' => $inhalt['betreff'],
                'anhang'  => $anhangPfad,
                'von'     => $benutzerId,
                'am'      => $jetzt,
                'gesamt'  => $empfaenger['anzahl_email'],
            ],
        );
        $versandaktionId = (int) $this->db->letzteId();

        foreach ($empfaenger['email'] as $m) {
            $kontext = $this->vorlagen->kontext($m);
            $this->mail->einreihen(
                (string) $m['email'],
                $this->vorlagen->render($inhalt['betreff'], $kontext),
                $this->vorlagen->render($inhalt['text'], $kontext),
                $inhalt['html'] !== null ? $this->vorlagen->render($inhalt['html'], $kontext) : null,
                $anhangPfad,
                (int) $m['id'],
                $versandaktionId,
            );
        }

        $this->audit->protokolliere($benutzerId, 'versandaktion_gestartet', 'versandaktion', $versandaktionId, [
            'typ' => $typ, 'email' => $empfaenger['anzahl_email'], 'post' => $empfaenger['anzahl_post'],
        ]);

        return [
            'versandaktion_id' => $versandaktionId,
            'anzahl_email'     => $empfaenger['anzahl_email'],
            'anzahl_post'      => $empfaenger['anzahl_post'],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function alle(): array
    {
        $zeilen = $this->db->alleZeilen('SELECT * FROM versandaktion ORDER BY id DESC');
        foreach ($zeilen as &$z) {
            $z = array_merge($z, $this->fortschritt((int) $z['id']));
        }

        return $zeilen;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findePerId(int $id): ?array
    {
        $z = $this->db->eineZeile('SELECT * FROM versandaktion WHERE id = :id', ['id' => $id]);

        return $z === null ? null : array_merge($z, $this->fortschritt($id));
    }

    /**
     * @return array{gesendet:int,wartend:int,fehler:int}
     */
    public function fortschritt(int $versandaktionId): array
    {
        return [
            'gesendet' => (int) $this->db->einWert("SELECT COUNT(*) FROM email_queue WHERE versandaktion_id = :v AND status = 'gesendet'", ['v' => $versandaktionId]),
            'wartend'  => (int) $this->db->einWert("SELECT COUNT(*) FROM email_queue WHERE versandaktion_id = :v AND status = 'wartend'", ['v' => $versandaktionId]),
            'fehler'   => (int) $this->db->einWert("SELECT COUNT(*) FROM email_queue WHERE versandaktion_id = :v AND status = 'fehler'", ['v' => $versandaktionId]),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function mails(int $versandaktionId): array
    {
        return $this->db->alleZeilen('SELECT * FROM email_queue WHERE versandaktion_id = :v ORDER BY id ASC', ['v' => $versandaktionId]);
    }

    /**
     * @param array<string,mixed> $mitglied
     */
    private function hatEmail(array $mitglied): bool
    {
        return (int) ($mitglied['kein_email_kontakt'] ?? 0) !== 1
            && trim((string) ($mitglied['email'] ?? '')) !== '';
    }

    private function jetzt(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d H:i:s');
    }
}
