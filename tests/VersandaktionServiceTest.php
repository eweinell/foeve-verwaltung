<?php

declare(strict_types=1);

namespace App\Tests;

use App\Repository\MitgliedRepository;
use App\Repository\VorlageRepository;
use App\Service\AnredeDienst;
use App\Service\Audit;
use App\Service\Einstellungen;
use App\Service\MailDienst;
use App\Service\VersandaktionService;
use App\Service\VorlagenService;
use App\Support\Db;
use App\Tests\Support\TestDb;
use PHPUnit\Framework\TestCase;

final class VersandaktionServiceTest extends TestCase
{
    private Db $db;
    private VersandaktionService $service;
    private MitgliedRepository $mitglieder;

    protected function setUp(): void
    {
        $this->db = TestDb::erstellen();
        $this->mitglieder = new MitgliedRepository($this->db);
        $vorlagen = new VorlagenService(new VorlageRepository($this->db), new AnredeDienst(), new Einstellungen($this->db));
        $this->service = new VersandaktionService($this->db, $this->mitglieder, new MailDienst($this->db), $vorlagen, new Audit($this->db));
    }

    private function seed(): void
    {
        $this->mitglieder->anlegen(['status' => 'aktiv', 'anrede' => 'herr', 'vorname' => 'Jan', 'nachname' => 'Alpha', 'email' => 'jan@x.de']);
        $this->mitglieder->anlegen(['status' => 'aktiv', 'anrede' => 'familie', 'nachname' => 'Beta', 'email' => 'beta@x.de']);
        $this->mitglieder->anlegen(['status' => 'aktiv', 'anrede' => 'frau', 'nachname' => 'Post', 'email' => null, 'kein_email_kontakt' => 1]);
    }

    public function testEmpfaengerAufteilung(): void
    {
        $this->seed();
        $e = $this->service->empfaenger(['status' => 'aktiv']);
        self::assertSame(2, $e['anzahl_email']);
        self::assertSame(1, $e['anzahl_post']);
    }

    public function testStartenReihtGerenderteMailsEin(): void
    {
        $this->seed();
        $ergebnis = $this->service->starten(['status' => 'aktiv'], 'vereinspost', null, 'Hallo {{vorname}}', 'Guten Tag, {{briefanrede}}!', null, 1);

        self::assertSame(2, $ergebnis['anzahl_email']);
        self::assertSame(1, $ergebnis['anzahl_post']);

        $mails = $this->service->mails($ergebnis['versandaktion_id']);
        self::assertCount(2, $mails);

        $anJan = array_values(array_filter($mails, static fn ($m) => $m['empfaenger'] === 'jan@x.de'))[0];
        self::assertSame('Hallo Jan', $anJan['betreff']);
        self::assertStringContainsString('Sehr geehrter Herr Alpha', (string) $anJan['body']);

        $anFamilie = array_values(array_filter($mails, static fn ($m) => $m['empfaenger'] === 'beta@x.de'))[0];
        self::assertStringContainsString('Sehr geehrte Familie Beta', (string) $anFamilie['body']);
    }

    public function testTestmailNurAnEigeneAdresseOhneAktion(): void
    {
        $this->seed();
        $beispiel = $this->mitglieder->alleGefiltert(['status' => 'aktiv'])[0];
        $this->service->testmail('ich@verein.de', null, 'Betreff {{vorname}}', 'Text {{briefanrede}}', $beispiel, null);

        self::assertSame(0, (int) $this->db->einWert('SELECT COUNT(*) FROM versandaktion'), 'Testmail startet keine Aktion');
        $mail = $this->db->eineZeile('SELECT * FROM email_queue');
        self::assertSame('ich@verein.de', $mail['empfaenger']);
        self::assertStringStartsWith('[Test]', (string) $mail['betreff']);
        self::assertSame(MailDienst::PRIO_SOFORT, (int) $mail['prioritaet']);
    }

    public function testFreitextMitUnbekanntemPlatzhalterWirdAbgewiesen(): void
    {
        $this->expectException(\DomainException::class);
        $this->service->inhalt(null, 'Betreff', 'Text mit {{quatsch}}');
    }

    public function testVorlageMitKontextabhaengigenPlatzhalternWirdBeimStartAbgewiesen(): void
    {
        $this->seed();
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/\{\{mandatsreferenz\}\}/');
        $this->service->starten(['status' => 'aktiv'], 'vereinspost', 'prenotification', '', '', null, 1);
    }

    public function testFreitextMitKontextabhaengigemPlatzhalterWirdBeimStartAbgewiesen(): void
    {
        $this->seed();
        $this->expectException(\DomainException::class);
        $this->service->starten(['status' => 'aktiv'], 'vereinspost', null, 'Betreff', 'IBAN: {{iban_maskiert}}', null, 1);
    }

    public function testFortschritt(): void
    {
        $this->seed();
        $ergebnis = $this->service->starten(['status' => 'aktiv'], 'vereinspost', null, 'B', 'T', null, 1);
        $f = $this->service->fortschritt($ergebnis['versandaktion_id']);
        self::assertSame(2, $f['wartend']);
        self::assertSame(0, $f['gesendet']);
    }
}
