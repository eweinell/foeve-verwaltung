<?php

declare(strict_types=1);

namespace App\Tests;

use App\Domain\Mitgliedsstatus;
use App\Repository\AntragRepository;
use App\Repository\MitgliedRepository;
use App\Service\AntragService;
use App\Service\Audit;
use App\Service\Krypto;
use App\Service\MailDienst;
use App\Service\Versionierung;
use App\Support\Db;
use App\Tests\Support\TestDb;
use PHPUnit\Framework\TestCase;

final class AntragServiceTest extends TestCase
{
    private Db $db;
    private AntragService $service;
    private MitgliedRepository $mitglieder;
    private AntragRepository $antraege;
    private Krypto $krypto;

    protected function setUp(): void
    {
        $this->db = TestDb::erstellen();
        $this->mitglieder = new MitgliedRepository($this->db);
        $this->antraege = new AntragRepository($this->db);
        $this->krypto = new Krypto(base64_encode(str_repeat("\x03", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));

        $this->service = new AntragService(
            $this->db,
            $this->mitglieder,
            $this->antraege,
            $this->krypto,
            new MailDienst($this->db),
            new Versionierung($this->db),
            new Audit($this->db),
            'https://verwaltung.example.de',
            'pepper',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function eingabe(array $ueberschreiben = []): array
    {
        return array_merge([
            'anrede'        => 'herr',
            'vorname'       => 'Jan',
            'nachname'      => 'de Vries',
            'strasse'       => 'Dorpsstraat 5',
            'plz'           => '6291 AB',
            'ort'           => 'Vaals',
            'land'          => 'NL',
            'email'         => 'jan@example.nl',
            'telefon'       => '',
            'jahresbeitrag' => '60.00',
            'iban'          => 'BE68539007547034',
            'kontoinhaber'  => 'Jan de Vries',
        ], $ueberschreiben);
    }

    public function testEinreichenLegtUnbestaetigtesMitgliedUndMailAn(): void
    {
        $token = $this->service->einreichen($this->eingabe(), 'iphash');

        self::assertNotSame('', $token);

        $mitglied = $this->db->eineZeile("SELECT * FROM mitglied WHERE nachname = 'de Vries'");
        self::assertSame(Mitgliedsstatus::UNBESTAETIGT, $mitglied['status']);
        self::assertSame('6291 AB', $mitglied['plz']);

        // DOI-Mail mit Priorität „sofort" in der Queue.
        $mail = $this->db->eineZeile("SELECT * FROM email_queue WHERE empfaenger = 'jan@example.nl'");
        self::assertNotNull($mail);
        self::assertSame(MailDienst::PRIO_SOFORT, (int) $mail['prioritaet']);
        self::assertStringContainsString('/antrag/bestaetigen?token=' . $token, (string) $mail['body']);
    }

    public function testIbanNieImKlartext(): void
    {
        $iban = 'BE68539007547034';
        $this->service->einreichen($this->eingabe(['iban' => $iban]), 'iphash');

        $antrag = $this->db->eineZeile('SELECT * FROM antrag_rohdaten LIMIT 1');
        $payload = (string) $antrag['payload'];

        // Klartext-IBAN darf nirgends stehen.
        self::assertStringNotContainsStringIgnoringCase($iban, $payload);
        self::assertStringNotContainsStringIgnoringCase($iban, (string) $this->db->einWert('SELECT COALESCE(GROUP_CONCAT(details), "") FROM audit_log'));

        // Verschlüsselter Wert ist vorhanden und wieder entschlüsselbar.
        $daten = json_decode($payload, true);
        self::assertArrayHasKey('iban_verschluesselt', $daten);
        self::assertSame($iban, $this->krypto->entschluesseln($daten['iban_verschluesselt']));
        self::assertSame('BE68 …… 7034', $daten['iban_maskiert']);
    }

    public function testBestaetigenSetztBeantragtUndIstIdempotent(): void
    {
        $token = $this->service->einreichen($this->eingabe(), null);

        self::assertSame('ok', $this->service->bestaetige($token));

        $mitglied = $this->db->eineZeile("SELECT * FROM mitglied WHERE nachname = 'de Vries'");
        self::assertSame(Mitgliedsstatus::BEANTRAGT, $mitglied['status']);
        self::assertNotNull($mitglied['bestaetigt_am']);

        // Zweite Bestätigung ändert nichts.
        self::assertSame('schon', $this->service->bestaetige($token));
    }

    public function testUnbekanntesTokenWirdAbgewiesen(): void
    {
        self::assertSame('unbekannt', $this->service->bestaetige('gibt-es-nicht'));
    }

    public function testErneutSendenNurBeiUnbestaetigt(): void
    {
        $token = $this->service->einreichen($this->eingabe(), null);
        self::assertTrue($this->service->erneutSenden($token));

        $this->service->bestaetige($token);
        self::assertFalse($this->service->erneutSenden($token));
    }
}
