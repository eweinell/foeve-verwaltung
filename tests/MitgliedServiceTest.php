<?php

declare(strict_types=1);

namespace App\Tests;

use App\Domain\Mitgliedsstatus;
use App\Repository\AntragRepository;
use App\Repository\ForderungRepository;
use App\Repository\MandatRepository;
use App\Repository\MitgliedRepository;
use App\Service\AnredeDienst;
use App\Service\Audit;
use App\Service\Krypto;
use App\Service\MailDienst;
use App\Service\MandatService;
use App\Service\MitgliedService;
use App\Service\SollstellungService;
use App\Service\Versionierung;
use App\Support\Db;
use App\Tests\Support\TestDb;
use PHPUnit\Framework\TestCase;

final class MitgliedServiceTest extends TestCase
{
    private Db $db;
    private MitgliedService $service;
    private MitgliedRepository $mitglieder;

    protected function setUp(): void
    {
        $this->db = TestDb::erstellen();
        $this->mitglieder = new MitgliedRepository($this->db);
        $versionierung = new Versionierung($this->db);
        $audit = new Audit($this->db);
        $krypto = new Krypto(base64_encode(str_repeat("\x04", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
        $mandatService = new MandatService(
            $this->db,
            $versionierung,
            new MandatRepository($this->db),
            $this->mitglieder,
            new AntragRepository($this->db),
            $krypto,
            $audit,
        );
        $sollstellung = new SollstellungService($this->db, new ForderungRepository($this->db), $audit);
        $this->service = new MitgliedService(
            $this->db,
            $versionierung,
            $this->mitglieder,
            new AntragRepository($this->db),
            new MailDienst($this->db),
            new AnredeDienst(),
            $audit,
            $mandatService,
            $sollstellung,
        );
    }

    private function seedBeantragt(string $nachname = 'Muster'): int
    {
        return $this->mitglieder->anlegen([
            'status'   => Mitgliedsstatus::BEANTRAGT,
            'anrede'   => 'familie',
            'nachname' => $nachname,
            'email'    => strtolower($nachname) . '@example.de',
            'jahresbeitrag' => '30.00',
        ]);
    }

    public function testAnlegenErzeugtBeantragtenAntragOhneNummer(): void
    {
        $id = $this->service->anlegen([
            'anrede'    => 'herr',
            'vorname'   => 'Jan',
            'nachname'  => 'Papier',
            'plz'       => '52134',
            'ort'       => 'Herzogenrath',
            'land'      => 'DE',
            'zahlweise' => 'selbstzahler',
        ], '30,00', 7);

        $mitglied = $this->mitglieder->findePerId($id);
        self::assertSame(Mitgliedsstatus::BEANTRAGT, $mitglied['status']);
        self::assertNull($mitglied['mitgliedsnummer']);
        self::assertNull($mitglied['eintrittsdatum']);
        self::assertSame('30.00', $mitglied['jahresbeitrag']);
        // Ohne DOI: kein Antrags-Rohdatensatz, keine Mail.
        self::assertSame(0, (int) $this->db->einWert('SELECT COUNT(*) FROM email_queue WHERE mitglied_id = :id', ['id' => $id]));
        self::assertSame(1, (int) $this->db->einWert("SELECT COUNT(*) FROM audit_log WHERE aktion = 'mitglied_angelegt'"));
    }

    public function testAnlegenUndAktivierenLaeuftUeberDenRegulaerenWeg(): void
    {
        $id = $this->service->anlegen(['anrede' => 'familie', 'nachname' => 'Papier', 'email' => 'papier@example.de'], '12.00', 7);

        $nummer = $this->service->aktivieren($id, 7);

        self::assertSame(2000, $nummer);
        self::assertSame(Mitgliedsstatus::AKTIV, $this->db->einWert('SELECT status FROM mitglied WHERE id = :id', ['id' => $id]));
        // Begrüßungsmail und Beitragsforderung wie beim Online-Antrag.
        self::assertSame(1, (int) $this->db->einWert('SELECT COUNT(*) FROM email_queue WHERE mitglied_id = :id', ['id' => $id]));
        self::assertSame(1, (int) $this->db->einWert('SELECT COUNT(*) FROM forderung WHERE mitglied_id = :id', ['id' => $id]));
        // Ohne Antrag kein automatisches Mandat — das erfasst der Vorstand von Hand.
        self::assertSame(0, (int) $this->db->einWert('SELECT COUNT(*) FROM mandat WHERE mitglied_id = :id', ['id' => $id]));
    }

    public function testAnlegenIgnoriertNichtErlaubteFelder(): void
    {
        $id = $this->service->anlegen([
            'nachname'        => 'Schlau',
            'anrede'          => 'frau',
            'status'          => Mitgliedsstatus::AKTIV,
            'mitgliedsnummer' => 4711,
        ], '12.00', 7);

        $mitglied = $this->mitglieder->findePerId($id);
        self::assertSame(Mitgliedsstatus::BEANTRAGT, $mitglied['status']);
        self::assertNull($mitglied['mitgliedsnummer']);
    }

    public function testAnlegenOhneNachnamenScheitert(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->anlegen(['anrede' => 'herr', 'vorname' => 'Nur'], '12.00', 7);
    }

    public function testAktivierenVergibtNummernAb2000(): void
    {
        $a = $this->seedBeantragt('Alpha');
        $b = $this->seedBeantragt('Beta');

        $nrA = $this->service->aktivieren($a, 1);
        $nrB = $this->service->aktivieren($b, 1);

        self::assertSame(2000, $nrA);
        self::assertSame(2001, $nrB);

        $mitglied = $this->mitglieder->findePerId($a);
        self::assertSame(Mitgliedsstatus::AKTIV, $mitglied['status']);
        self::assertNotNull($mitglied['eintrittsdatum']);

        // Begrüßungsmail in der Queue.
        self::assertSame(2, (int) $this->db->einWert("SELECT COUNT(*) FROM email_queue WHERE betreff = 'Willkommen im Förderverein'"));
        // Audit-Eintrag.
        self::assertGreaterThanOrEqual(1, (int) $this->db->einWert("SELECT COUNT(*) FROM audit_log WHERE aktion = 'mitglied_aktiviert'"));
    }

    public function testAktivierenNurAusBeantragt(): void
    {
        $id = $this->seedBeantragt();
        $this->service->aktivieren($id, 1);

        $this->expectException(\DomainException::class);
        $this->service->aktivieren($id, 1); // schon aktiv
    }

    public function testKuendigenUndWiderruf(): void
    {
        $id = $this->seedBeantragt();
        $this->service->aktivieren($id, 1);

        $this->service->kuendigen($id, 1);
        $mitglied = $this->mitglieder->findePerId($id);
        self::assertSame(Mitgliedsstatus::GEKUENDIGT, $mitglied['status']);
        self::assertSame((new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y') . '-12-31', substr((string) $mitglied['wirksam_zum'], 0, 10));
        self::assertSame(1, (int) $this->db->einWert("SELECT COUNT(*) FROM email_queue WHERE betreff = 'Kündigungsbestätigung'"));

        $this->service->kuendigungWiderrufen($id, 1);
        $mitglied = $this->mitglieder->findePerId($id);
        self::assertSame(Mitgliedsstatus::AKTIV, $mitglied['status']);
        self::assertNull($mitglied['wirksam_zum']);
    }

    public function testStammdatenAenderungErzeugtVersionMitDiff(): void
    {
        $id = $this->seedBeantragt('Alt');
        $ergebnis = $this->service->stammdatenAendern($id, ['nachname' => 'Neu'], 1);

        self::assertContains('nachname', $ergebnis['geaenderte_felder']);
        self::assertSame('Neu', $this->db->einWert('SELECT nachname FROM mitglied WHERE id = :id', ['id' => $id]));

        // Snapshot hält den alten Wert.
        $snapshot = json_decode((string) $this->db->einWert('SELECT snapshot FROM mitglied_version WHERE id = :id', ['id' => $ergebnis['version_id']]), true);
        self::assertSame('Alt', $snapshot['nachname']);
    }

    public function testRevertStelltAltenStandWiederHer(): void
    {
        $id = $this->seedBeantragt('Original');
        $v = $this->service->stammdatenAendern($id, ['nachname' => 'Geaendert'], 1);

        $this->service->revert($id, (int) $v['version_id'], 9);

        self::assertSame('Original', $this->db->einWert('SELECT nachname FROM mitglied WHERE id = :id', ['id' => $id]));
        $revVersion = $this->db->eineZeile('SELECT * FROM mitglied_version ORDER BY id DESC LIMIT 1');
        self::assertSame((int) $v['version_id'], (int) $revVersion['ist_revert_von']);
    }

    public function testBeitragAenderung(): void
    {
        $id = $this->seedBeantragt();
        $this->service->beitragAendern($id, '60,00', 1);

        self::assertSame('60.00', $this->db->einWert('SELECT jahresbeitrag FROM mitglied WHERE id = :id', ['id' => $id]));
    }

    public function testVerwerfeUnbestaetigte(): void
    {
        // Unbestätigt, „alt" (createdvor 40 Tagen) — createdat direkt setzen.
        $id = $this->mitglieder->anlegen(['status' => Mitgliedsstatus::UNBESTAETIGT, 'nachname' => 'Alt', 'anrede' => 'herr']);
        $alt = (new \DateTimeImmutable('-40 days'))->format('Y-m-d H:i:s');
        $this->db->ausfuehren('UPDATE mitglied SET created_at = :c WHERE id = :id', ['c' => $alt, 'id' => $id]);
        $antragId = (new AntragRepository($this->db))->anlegen($id, ['x' => 1], null, 'tok-' . $id, 'ref-' . $id);

        $anzahl = $this->service->verwerfeUnbestaetigte(30);

        self::assertSame(1, $anzahl);
        self::assertSame(Mitgliedsstatus::VERWORFEN, $this->db->einWert('SELECT status FROM mitglied WHERE id = :id', ['id' => $id]));
        // Beide Tokens entwertet — auch die Warteseite des verworfenen Antrags.
        self::assertNull((new AntragRepository($this->db))->findePerToken('tok-' . $id));
        self::assertNull((new AntragRepository($this->db))->findePerResendToken('ref-' . $id));
    }
}
