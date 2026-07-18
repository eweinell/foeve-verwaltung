<?php

declare(strict_types=1);

namespace App\Tests;

use App\Domain\Bankarbeitstage;
use App\Repository\VorlageRepository;
use App\Repository\AntragRepository;
use App\Repository\EinzugslaufRepository;
use App\Repository\ForderungRepository;
use App\Repository\MandatRepository;
use App\Repository\MitgliedRepository;
use App\Service\AnredeDienst;
use App\Service\Audit;
use App\Service\VorlagenService;
use App\Service\Einstellungen;
use App\Service\EinzugslaufService;
use App\Service\Krypto;
use App\Service\MailDienst;
use App\Service\MandatService;
use App\Service\SepaXmlValidator;
use App\Service\SollstellungService;
use App\Service\Versionierung;
use App\Support\Db;
use App\Tests\Support\TestDb;
use PHPUnit\Framework\TestCase;

final class EinzugslaufServiceTest extends TestCase
{
    private Db $db;
    private EinzugslaufService $service;
    private ForderungRepository $forderungen;
    private MandatRepository $mandate;
    private Einstellungen $einstellungen;
    private Krypto $krypto;
    private string $tmp;

    protected function setUp(): void
    {
        $this->db = TestDb::erstellen();
        $this->krypto = new Krypto(base64_encode(str_repeat("\x06", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
        $this->forderungen = new ForderungRepository($this->db);
        $this->mandate = new MandatRepository($this->db);
        $this->einstellungen = new Einstellungen($this->db);
        $audit = new Audit($this->db);
        $versionierung = new Versionierung($this->db);
        $mandatService = new MandatService($this->db, $versionierung, $this->mandate, new MitgliedRepository($this->db), new AntragRepository($this->db), $this->krypto, $audit);
        $sollstellung = new SollstellungService($this->db, $this->forderungen, $audit);

        $this->tmp = sys_get_temp_dir() . '/foeve-test-' . bin2hex(random_bytes(4));
        @mkdir($this->tmp, 0770, true);

        $this->service = new EinzugslaufService(
            $this->db,
            new EinzugslaufRepository($this->db),
            $this->forderungen,
            $mandatService,
            $sollstellung,
            $this->krypto,
            new MailDienst($this->db),
            new AnredeDienst(),
            new VorlagenService(new VorlageRepository($this->db), new AnredeDienst(), $this->einstellungen),
            $this->einstellungen,
            new SepaXmlValidator(),
            $audit,
            $this->tmp,
        );

        // Vereins-Stammdaten.
        $this->einstellungen->setze('verein_name', 'Foerderverein Gymnasium Herzogenrath');
        $this->einstellungen->setze('glaeubiger_id', 'DE98ZZZ09999999999');
        $this->einstellungen->setze('verein_iban', $this->krypto->verschluesseln('DE89370400440532013000'));
        $this->einstellungen->setze('verein_bic', '');
        $this->einstellungen->setze('prenotification_tage', '14');
        $this->einstellungen->setze('ruecklastschrift_gebuehr', '3.00');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmp . '/var/sepa/*') ?: []);
        @rmdir($this->tmp . '/var/sepa');
        @rmdir($this->tmp . '/var');
        @rmdir($this->tmp);
    }

    private function faelligIn(int $banktage): string
    {
        return Bankarbeitstage::fruehesteFaelligkeit(new \DateTimeImmutable('today'), $banktage)->format('Y-m-d');
    }

    /**
     * Legt ein aktives Lastschrift-Mitglied mit Mandat und offener Forderung an.
     */
    private function mitgliedMitForderung(string $nachname, string $iban, bool $sequenzGenutzt, int $nummer, bool $email = true): array
    {
        $mitglieder = new MitgliedRepository($this->db);
        $mid = $mitglieder->anlegen([
            'status' => 'aktiv', 'anrede' => 'herr', 'nachname' => $nachname,
            'email' => $email ? strtolower(preg_replace('/[^a-z]/i', '', $nachname)) . '@example.de' : null,
            'kein_email_kontakt' => $email ? 0 : 1,
            'jahresbeitrag' => '30.00', 'zahlweise' => 'lastschrift',
        ]);
        $this->db->ausfuehren('UPDATE mitglied SET mitgliedsnummer = :n WHERE id = :id', ['n' => $nummer, 'id' => $mid]);
        $this->mandate->anlegen([
            'mitglied_id' => $mid, 'lfd_nr' => 1, 'mandatsreferenz' => 'FGH-' . $nummer . '-01',
            'iban_verschluesselt' => $this->krypto->verschluesseln($iban), 'kontoinhaber' => $nachname,
            'erteilt_am' => '2025-01-15', 'status' => 'aktiv', 'sequenz_genutzt' => $sequenzGenutzt ? 1 : 0,
        ]);
        $fid = $this->forderungen->anlegen(['mitglied_id' => $mid, 'jahr' => 2027, 'betrag' => '30.00', 'typ' => 'beitrag']);

        return ['mitglied_id' => $mid, 'forderung_id' => $fid];
    }

    public function testFaelligkeitMorgenWirdAbgelehnt(): void
    {
        $this->mitgliedMitForderung('Alpha', 'DE89370400440532013000', false, 2000);
        $this->expectException(\DomainException::class);
        $this->service->anlegen('Lauf', (new \DateTimeImmutable('tomorrow'))->format('Y-m-d'), 1);
    }

    public function testKompletterDurchlaufErzeugtSchemavalidesXml(): void
    {
        $this->mitgliedMitForderung('Alpha', 'DE89370400440532013000', false, 2000);          // FRST
        $this->mitgliedMitForderung('Beta', 'NL91ABNA0417164300', true, 2001);                // RCUR
        $this->mitgliedMitForderung('Müller-Lüdenscheidt', 'DE89370400440532013000', false, 2002); // FRST + Umlaut

        $lauf = $this->service->anlegen('Jahreseinzug 2027', $this->faelligIn(20), 1);
        $laufId = $lauf['id'];

        $vorschau = $this->service->vorschau($laufId);
        self::assertSame(3, $vorschau['anzahl']);
        self::assertSame('90.00', $vorschau['summe']);
        self::assertSame('60.00', $vorschau['summe_frst']);
        self::assertSame('30.00', $vorschau['summe_rcur']);

        $this->service->ankuendigen($laufId, 1);
        self::assertSame(3, (int) $this->db->einWert("SELECT COUNT(*) FROM email_queue WHERE betreff = 'Ankündigung SEPA-Lastschrift'"));

        $ergebnis = $this->service->xmlErzeugen($laufId, 1);
        $xml = $ergebnis['xml'];

        // Schemavalide + SEPA-konform (reines ASCII → Umlaute transliteriert).
        self::assertTrue((new SepaXmlValidator())->istValide($xml), 'pain.008 muss schemavalide sein');
        self::assertSame(0, preg_match('/[^\x00-\x7F]/', $xml), 'XML muss ASCII/SEPA-konform sein');
        self::assertStringContainsString('DE98ZZZ09999999999', $xml, 'Gläubiger-ID im XML');
        self::assertStringContainsString('<NbOfTxs>2</NbOfTxs>', $xml, 'FRST-Sammler mit 2 Positionen');

        // Nebenwirkungen: Forderungen im_einzug, Mandate sequenz_genutzt.
        self::assertSame(3, (int) $this->db->einWert("SELECT COUNT(*) FROM forderung WHERE status = 'im_einzug'"));
        self::assertSame(0, (int) $this->db->einWert("SELECT COUNT(*) FROM mandat WHERE status='aktiv' AND sequenz_genutzt = 0"));
        self::assertSame('exportiert', $this->db->einWert('SELECT status FROM einzugslauf WHERE id = :id', ['id' => $laufId]));

        // Re-Export liefert dieselbe Datei, kein erneuter Statuswechsel.
        $wieder = $this->service->xmlErzeugen($laufId, 1);
        self::assertSame($ergebnis['dateiname'], $wieder['dateiname']);
    }

    public function testFrstWirdBeimNaechstenLaufZuRcur(): void
    {
        $ids = $this->mitgliedMitForderung('Alpha', 'DE89370400440532013000', false, 2000); // FRST
        $lauf1 = $this->service->anlegen('Lauf 1', $this->faelligIn(20), 1);
        $this->service->ankuendigen($lauf1['id'], 1);
        $this->service->xmlErzeugen($lauf1['id'], 1);

        // Neue offene Forderung fürs Folgejahr → neuer Lauf stuft als RCUR ein.
        $this->forderungen->anlegen(['mitglied_id' => $ids['mitglied_id'], 'jahr' => 2028, 'betrag' => '30.00', 'typ' => 'beitrag']);
        $lauf2 = $this->service->anlegen('Lauf 2', $this->faelligIn(20), 1);
        $vorschau = $this->service->vorschau($lauf2['id']);

        self::assertSame(1, $vorschau['anzahl']);
        self::assertSame('RCUR', $vorschau['positionen'][0]['sequenztyp']);
    }

    public function testForderungKannNichtInZweiLaeufen(): void
    {
        $this->mitgliedMitForderung('Alpha', 'DE89370400440532013000', false, 2000);
        $lauf1 = $this->service->anlegen('Lauf 1', $this->faelligIn(20), 1);
        self::assertSame(1, $this->service->vorschau($lauf1['id'])['anzahl']);

        // Zweiter Lauf findet keine aufnehmbaren Forderungen mehr.
        $lauf2 = $this->service->anlegen('Lauf 2', $this->faelligIn(20), 1);
        self::assertSame(0, $this->service->vorschau($lauf2['id'])['anzahl']);
    }

    public function testPostMitgliedAufBriefliste(): void
    {
        $this->mitgliedMitForderung('Email', 'DE89370400440532013000', false, 2000, true);
        $this->mitgliedMitForderung('Post', 'NL91ABNA0417164300', false, 2001, false);

        $lauf = $this->service->anlegen('Lauf', $this->faelligIn(20), 1);
        $this->service->ankuendigen($lauf['id'], 1);

        self::assertCount(1, $this->service->briefListe($lauf['id']));
        self::assertSame(1, (int) $this->db->einWert('SELECT anzahl_email FROM einzugslauf WHERE id = :id', ['id' => $lauf['id']]));
        self::assertSame(1, (int) $this->db->einWert('SELECT anzahl_post FROM einzugslauf WHERE id = :id', ['id' => $lauf['id']]));
    }

    public function testRuecklastschriftMachtForderungOffenUndStelltSelbstzahler(): void
    {
        $ids = $this->mitgliedMitForderung('Alpha', 'DE89370400440532013000', false, 2000);
        $lauf = $this->service->anlegen('Lauf', $this->faelligIn(20), 1);
        $this->service->ankuendigen($lauf['id'], 1);
        $this->service->xmlErzeugen($lauf['id'], 1);
        $this->service->abschliessen($lauf['id'], 1);

        self::assertSame('bezahlt', $this->db->einWert('SELECT status FROM forderung WHERE id = :id', ['id' => $ids['forderung_id']]));

        $this->service->ruecklastschriftErfassen($ids['forderung_id'], true, true, 1);

        self::assertSame('offen', $this->db->einWert('SELECT status FROM forderung WHERE id = :id', ['id' => $ids['forderung_id']]));
        self::assertNull($this->db->einWert('SELECT einzugslauf_id FROM forderung WHERE id = :id', ['id' => $ids['forderung_id']]));
        self::assertSame(1, (int) $this->db->einWert("SELECT COUNT(*) FROM forderung WHERE typ='gebuehr' AND betrag='3.00'"));
        self::assertSame('selbstzahler', $this->db->einWert('SELECT zahlweise FROM mitglied WHERE id = :id', ['id' => $ids['mitglied_id']]));
        self::assertSame(0, (int) $this->db->einWert("SELECT COUNT(*) FROM mandat WHERE mitglied_id = :id AND status='aktiv'", ['id' => $ids['mitglied_id']]));
    }

    public function testFehlendeVereinsdatenVerhindernLauf(): void
    {
        $this->einstellungen->setze('glaeubiger_id', '');
        $this->mitgliedMitForderung('Alpha', 'DE89370400440532013000', false, 2000);
        $this->expectException(\DomainException::class);
        $this->service->anlegen('Lauf', $this->faelligIn(20), 1);
    }
}
