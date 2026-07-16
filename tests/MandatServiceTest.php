<?php

declare(strict_types=1);

namespace App\Tests;

use App\Domain\Mandatsstatus;
use App\Repository\AntragRepository;
use App\Repository\MandatRepository;
use App\Repository\MitgliedRepository;
use App\Service\Audit;
use App\Service\Krypto;
use App\Service\MandatService;
use App\Service\Versionierung;
use App\Support\Db;
use App\Tests\Support\TestDb;
use PHPUnit\Framework\TestCase;

final class MandatServiceTest extends TestCase
{
    private Db $db;
    private MandatService $service;
    private MandatRepository $mandate;
    private MitgliedRepository $mitglieder;
    private Krypto $krypto;

    protected function setUp(): void
    {
        $this->db = TestDb::erstellen();
        $this->mandate = new MandatRepository($this->db);
        $this->mitglieder = new MitgliedRepository($this->db);
        $this->krypto = new Krypto(base64_encode(str_repeat("\x05", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
        $this->service = new MandatService(
            $this->db,
            new Versionierung($this->db),
            $this->mandate,
            $this->mitglieder,
            new AntragRepository($this->db),
            $this->krypto,
            new Audit($this->db),
        );
    }

    private function seedAktiv(int $nummer = 2000): int
    {
        $id = $this->mitglieder->anlegen([
            'status' => 'aktiv', 'anrede' => 'herr', 'nachname' => 'Test', 'zahlweise' => 'lastschrift',
        ]);
        $this->db->ausfuehren('UPDATE mitglied SET mitgliedsnummer = :n WHERE id = :id', ['n' => $nummer, 'id' => $id]);

        return $id;
    }

    public function testNeuesMandatMitReferenzUndVerschluesselterIban(): void
    {
        $mid = $this->seedAktiv(2000);
        $id = $this->service->neuesMandat($mid, 'DE89370400440532013000', 'Test', null, 1);

        $mandat = $this->mandate->findePerId($id);
        self::assertSame('FGH-2000-01', $mandat['mandatsreferenz']);
        self::assertSame(Mandatsstatus::AKTIV, $mandat['status']);
        self::assertSame('DE89370400440532013000', $this->krypto->entschluesseln((string) $mandat['iban_verschluesselt']));
        self::assertStringNotContainsString('DE89370400440532013000', (string) $mandat['iban_verschluesselt']);
    }

    public function testBankwechselDeaktiviertAltesUndNieZweiAktive(): void
    {
        $mid = $this->seedAktiv(2001);
        $alt = $this->service->neuesMandat($mid, 'DE89370400440532013000', 'Test', null, 1);
        $neu = $this->service->neuesMandat($mid, 'NL91ABNA0417164300', 'Test', null, 1);

        self::assertSame(Mandatsstatus::INAKTIV, $this->mandate->findePerId($alt)['status']);
        self::assertSame(Mandatsstatus::AKTIV, $this->mandate->findePerId($neu)['status']);
        self::assertSame('FGH-2001-02', $this->mandate->findePerId($neu)['mandatsreferenz']);

        // Höchstens ein aktives Mandat.
        self::assertSame(1, (int) $this->db->einWert("SELECT COUNT(*) FROM mandat WHERE mitglied_id = :id AND status = 'aktiv'", ['id' => $mid]));
    }

    public function testWiderrufSetztMitgliedAufSelbstzahler(): void
    {
        $mid = $this->seedAktiv(2002);
        $id = $this->service->neuesMandat($mid, 'DE89370400440532013000', 'Test', null, 1);

        $this->service->widerrufen($id, 1);

        self::assertSame(Mandatsstatus::WIDERRUFEN, $this->mandate->findePerId($id)['status']);
        self::assertSame('selbstzahler', $this->db->einWert('SELECT zahlweise FROM mitglied WHERE id = :id', ['id' => $mid]));
    }

    public function testAenderungInVersionshistorie(): void
    {
        $mid = $this->seedAktiv(2003);
        $id = $this->service->neuesMandat($mid, 'DE89370400440532013000', 'Test', null, 1);
        $this->service->deaktivieren($id, 1);

        self::assertGreaterThanOrEqual(1, (int) $this->db->einWert('SELECT COUNT(*) FROM mandat_version WHERE mandat_id = :id', ['id' => $id]));
    }

    public function testVerfallNach36Monaten(): void
    {
        $mid = $this->seedAktiv(2004);
        $id = $this->service->neuesMandat($mid, 'DE89370400440532013000', 'Test', null, 1);

        $alt = (new \DateTimeImmutable('-40 months'))->format('Y-m-d');
        $this->db->ausfuehren('UPDATE mandat SET erteilt_am = :d, zuletzt_genutzt_am = NULL WHERE id = :id', ['d' => $alt, 'id' => $id]);
        self::assertTrue($this->service->istVerfallen($this->mandate->findePerId($id)));

        $neu = (new \DateTimeImmutable('-2 months'))->format('Y-m-d');
        $this->db->ausfuehren('UPDATE mandat SET erteilt_am = :d WHERE id = :id', ['d' => $neu, 'id' => $id]);
        self::assertFalse($this->service->istVerfallen($this->mandate->findePerId($id)));
    }
}
