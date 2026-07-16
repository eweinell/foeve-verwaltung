<?php

declare(strict_types=1);

namespace App\Tests;

use App\Domain\Forderungsstatus;
use App\Repository\ForderungRepository;
use App\Repository\MitgliedRepository;
use App\Service\Audit;
use App\Service\SollstellungService;
use App\Support\Db;
use App\Tests\Support\TestDb;
use PHPUnit\Framework\TestCase;

final class SollstellungServiceTest extends TestCase
{
    private Db $db;
    private SollstellungService $service;
    private ForderungRepository $forderungen;
    private MitgliedRepository $mitglieder;

    protected function setUp(): void
    {
        $this->db = TestDb::erstellen();
        $this->forderungen = new ForderungRepository($this->db);
        $this->mitglieder = new MitgliedRepository($this->db);
        $this->service = new SollstellungService($this->db, $this->forderungen, new Audit($this->db));
    }

    private function seed(string $status, string $beitrag = '30.00', ?string $wirksam = null): int
    {
        $id = $this->mitglieder->anlegen(['status' => $status, 'anrede' => 'herr', 'nachname' => 'M', 'jahresbeitrag' => $beitrag]);
        if ($wirksam !== null) {
            $this->db->ausfuehren('UPDATE mitglied SET wirksam_zum = :w WHERE id = :id', ['w' => $wirksam, 'id' => $id]);
        }

        return $id;
    }

    public function testVorschauUndAusfuehrenIdempotent(): void
    {
        $this->seed('aktiv', '30.00');
        $this->seed('aktiv', '60.00');
        $this->seed('beantragt', '30.00'); // nicht aktiv → nicht enthalten

        $vorschau = $this->service->vorschau(2027);
        self::assertSame(2, $vorschau['anzahl']);
        self::assertSame('90.00', $vorschau['summe']);

        self::assertSame(2, $this->service->ausfuehren(2027, 1));
        // Zweiter Lauf erzeugt nichts mehr.
        self::assertSame(0, $this->service->ausfuehren(2027, 1));
        self::assertSame(2, (int) $this->db->einWert("SELECT COUNT(*) FROM forderung WHERE jahr = 2027 AND typ = 'beitrag'"));
    }

    public function testGekuendigtKeineForderungNachWirksamkeitsjahr(): void
    {
        $this->seed('gekuendigt', '30.00', '2026-12-31');

        self::assertSame(1, $this->service->vorschau(2026)['anzahl'], 'im Wirksamkeitsjahr noch fällig');
        self::assertSame(0, $this->service->vorschau(2027)['anzahl'], 'im Folgejahr nicht mehr');
    }

    public function testEinzelsollstellungIdempotent(): void
    {
        $id = $this->seed('aktiv', '45.00');
        $jahr = (int) date('Y');

        $this->service->einzelsollstellung($id, '45.00', 1);
        $this->service->einzelsollstellung($id, '45.00', 1);

        self::assertSame(1, (int) $this->db->einWert('SELECT COUNT(*) FROM forderung WHERE mitglied_id = :id', ['id' => $id]));
        self::assertSame('45.00', $this->db->einWert('SELECT betrag FROM forderung WHERE mitglied_id = :id', ['id' => $id]));
        self::assertSame($jahr, (int) $this->db->einWert('SELECT jahr FROM forderung WHERE mitglied_id = :id', ['id' => $id]));
    }

    public function testStornoStattLoeschen(): void
    {
        $id = $this->seed('aktiv');
        $fid = $this->forderungen->anlegen(['mitglied_id' => $id, 'jahr' => 2027, 'betrag' => '30.00', 'typ' => 'beitrag']);

        $this->service->stornieren($fid, 1);
        $f = $this->forderungen->findePerId($fid);
        self::assertNotNull($f, 'Forderung bleibt bestehen (kein DELETE)');
        self::assertSame(Forderungsstatus::STORNIERT, $f['status']);
    }

    public function testStornoBeiImEinzugVerboten(): void
    {
        $id = $this->seed('aktiv');
        $fid = $this->forderungen->anlegen(['mitglied_id' => $id, 'jahr' => 2027, 'betrag' => '30.00', 'typ' => 'beitrag', 'status' => 'im_einzug']);

        $this->expectException(\DomainException::class);
        $this->service->stornieren($fid, 1);
    }

    public function testBeitragForderungAnpassenNurBeiOffen(): void
    {
        $id = $this->seed('aktiv');
        $fid = $this->forderungen->anlegen(['mitglied_id' => $id, 'jahr' => 2027, 'betrag' => '30.00', 'typ' => 'beitrag', 'status' => 'offen']);

        self::assertTrue($this->service->beitragForderungAnpassen($id, 2027, '60,00', 1));
        self::assertSame('60.00', $this->db->einWert('SELECT betrag FROM forderung WHERE id = :id', ['id' => $fid]));

        // Im Einzug → keine Anpassung.
        $this->db->ausfuehren("UPDATE forderung SET status = 'im_einzug' WHERE id = :id", ['id' => $fid]);
        self::assertFalse($this->service->beitragForderungAnpassen($id, 2027, '90,00', 1));
        self::assertSame('60.00', $this->db->einWert('SELECT betrag FROM forderung WHERE id = :id', ['id' => $fid]));
    }

    public function testGebuehrErlaubtMehrereProJahr(): void
    {
        $id = $this->seed('aktiv');
        $this->service->gebuehrAnlegen($id, '5,00', 2027, 1);
        $this->service->gebuehrAnlegen($id, '5,00', 2027, 1);

        self::assertSame(2, (int) $this->db->einWert("SELECT COUNT(*) FROM forderung WHERE typ = 'gebuehr'"));
    }

    public function testAlsBezahltMarkieren(): void
    {
        $id = $this->seed('aktiv');
        $fid = $this->forderungen->anlegen(['mitglied_id' => $id, 'jahr' => 2027, 'betrag' => '30.00', 'typ' => 'beitrag']);

        $this->service->alsBezahltMarkieren($fid, 'ueberweisung', '2027-01-15', 1);
        $f = $this->forderungen->findePerId($fid);
        self::assertSame(Forderungsstatus::BEZAHLT, $f['status']);
        self::assertSame('ueberweisung', $f['zahlungsart']);
    }
}
