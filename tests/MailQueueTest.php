<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\MailDienst;
use App\Support\Db;
use App\Tests\Support\TestDb;
use PHPUnit\Framework\TestCase;

final class MailQueueTest extends TestCase
{
    private Db $db;
    private MailDienst $mail;

    protected function setUp(): void
    {
        $this->db = TestDb::erstellen();
        $this->mail = new MailDienst($this->db);
    }

    public function testPrioritaetSofortWirdZuerstVerarbeitet(): void
    {
        $this->mail->einreihen('normal@x.de', 'N', 'Text', prioritaet: MailDienst::PRIO_NORMAL);
        $this->mail->einreihen('sofort@x.de', 'S', 'Text', prioritaet: MailDienst::PRIO_SOFORT);

        $reihenfolge = [];
        $this->mail->verarbeite(10, function (array $m) use (&$reihenfolge): array {
            $reihenfolge[] = (string) $m['empfaenger'];

            return ['status' => 'ok'];
        });

        self::assertSame(['sofort@x.de', 'normal@x.de'], $reihenfolge);
    }

    public function testTemporaererFehlerWirdEinmalWiederholt(): void
    {
        $id = $this->mail->einreihen('a@x.de', 'B', 'T');

        $r = $this->mail->verarbeite(10, fn (array $m): array => ['status' => 'temp', 'fehltext' => '451 busy']);
        self::assertSame(1, $r['wiederholung']);

        $zeile = $this->db->eineZeile('SELECT * FROM email_queue WHERE id = :id', ['id' => $id]);
        self::assertSame('wartend', $zeile['status']);
        self::assertSame(1, (int) $zeile['versuche']);
        self::assertNotNull($zeile['naechster_versuch']);

        // Sofort erneut verarbeitet: naechster_versuch liegt in der Zukunft ⇒ nicht dran.
        $r2 = $this->mail->verarbeite(10, fn (array $m): array => ['status' => 'ok']);
        self::assertSame(0, $r2['gesendet']);
    }

    public function testZweiterTemporaererFehlerWirdPermanent(): void
    {
        $id = $this->mail->einreihen('a@x.de', 'B', 'T');
        // Erster Fehler → Wiederholung.
        $this->mail->verarbeite(10, fn (array $m): array => ['status' => 'temp']);
        // naechster_versuch auf jetzt vorziehen, damit die Mail wieder drankommt.
        $this->db->ausfuehren('UPDATE email_queue SET naechster_versuch = :n WHERE id = :id', ['n' => '2000-01-01 00:00:00', 'id' => $id]);

        $this->mail->verarbeite(10, fn (array $m): array => ['status' => 'temp']);
        self::assertSame('fehler', $this->db->einWert('SELECT status FROM email_queue WHERE id = :id', ['id' => $id]));
    }

    public function testPermanenterFehlerSofort(): void
    {
        $id = $this->mail->einreihen('a@x.de', 'B', 'T');
        $r = $this->mail->verarbeite(10, fn (array $m): array => ['status' => 'perm', 'fehltext' => '550 rejected']);

        self::assertSame(1, $r['fehler']);
        $zeile = $this->db->eineZeile('SELECT * FROM email_queue WHERE id = :id', ['id' => $id]);
        self::assertSame('fehler', $zeile['status']);
        self::assertStringContainsString('550', (string) $zeile['fehltext']);
    }

    public function testFehlerNeuEinreihen(): void
    {
        $id = $this->mail->einreihen('a@x.de', 'B', 'T');
        $this->mail->verarbeite(10, fn (array $m): array => ['status' => 'perm']);
        self::assertSame('fehler', $this->db->einWert('SELECT status FROM email_queue WHERE id = :id', ['id' => $id]));

        self::assertSame(1, $this->mail->fehlerNeuEinreihen(null, (int) $id));
        $zeile = $this->db->eineZeile('SELECT * FROM email_queue WHERE id = :id', ['id' => $id]);
        self::assertSame('wartend', $zeile['status']);
        self::assertSame(0, (int) $zeile['versuche']);
    }
}
