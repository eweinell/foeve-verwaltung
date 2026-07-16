<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\MailDienst;
use App\Support\Db;
use App\Tests\Support\TestDb;
use PHPUnit\Framework\TestCase;

final class MailDienstTest extends TestCase
{
    private Db $db;
    private MailDienst $mail;

    protected function setUp(): void
    {
        $this->db = TestDb::erstellen();
        $this->mail = new MailDienst($this->db);
    }

    public function testEinreihenSchreibtWartendeZeile(): void
    {
        $id = $this->mail->einreihen('a@verein.de', 'Betreff', 'Text');

        $zeile = $this->db->eineZeile('SELECT * FROM email_queue WHERE id = :id', ['id' => $id]);
        self::assertNotNull($zeile);
        self::assertSame('wartend', $zeile['status']);
        self::assertSame('a@verein.de', $zeile['empfaenger']);
        self::assertSame(MailDienst::PRIO_NORMAL, (int) $zeile['prioritaet']);
    }

    public function testSofortWirdVorNormalVersandt(): void
    {
        $this->mail->einreihen('normal@verein.de', 'N', 'Text', prioritaet: MailDienst::PRIO_NORMAL);
        $this->mail->einreihen('sofort@verein.de', 'S', 'Text', prioritaet: MailDienst::PRIO_SOFORT);

        $reihenfolge = array_map(
            static fn (array $m): string => (string) $m['empfaenger'],
            $this->mail->naechsteWartende(10),
        );

        self::assertSame(['sofort@verein.de', 'normal@verein.de'], $reihenfolge);
    }

    public function testStatuswechsel(): void
    {
        $id = $this->mail->einreihen('a@verein.de', 'B', 'T');
        $this->mail->alsGesendet($id);
        self::assertSame('gesendet', $this->db->einWert('SELECT status FROM email_queue WHERE id = :id', ['id' => $id]));

        $id2 = $this->mail->einreihen('b@verein.de', 'B', 'T');
        $this->mail->alsFehler($id2, 'SMTP-Fehler');
        $zeile = $this->db->eineZeile('SELECT status, versuche, fehltext FROM email_queue WHERE id = :id', ['id' => $id2]);
        self::assertSame('fehler', $zeile['status']);
        self::assertSame(1, (int) $zeile['versuche']);
        self::assertSame('SMTP-Fehler', $zeile['fehltext']);
    }
}
