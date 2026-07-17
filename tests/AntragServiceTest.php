<?php

declare(strict_types=1);

namespace App\Tests;

use App\Domain\Mitgliedsstatus;
use App\Repository\VorlageRepository;
use App\Repository\AntragRepository;
use App\Repository\MitgliedRepository;
use App\Service\AntragService;
use App\Service\AnredeDienst;
use App\Service\Audit;
use App\Service\VorlagenService;
use App\Service\Einstellungen;
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

        $einstellungen = new Einstellungen($this->db);
        $einstellungen->setze('glaeubiger_id', 'DE98ZZZ09999999999');
        $einstellungen->setze('verein_name', 'Förderverein Testschule e.V.');

        $this->service = new AntragService(
            $this->db,
            $this->mitglieder,
            $this->antraege,
            $this->krypto,
            new MailDienst($this->db),
            new Versionierung($this->db),
            new Audit($this->db),
            $einstellungen,
            new VorlagenService(new VorlageRepository($this->db), new AnredeDienst(), $einstellungen),
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
            'jahresbeitrag' => '60.00',
            'iban'          => 'BE68539007547034',
            'kontoinhaber'  => 'Jan de Vries',
        ], $ueberschreiben);
    }

    /** Bestätigungstoken des zuletzt eingereichten Antrags (steht sonst nur in der Mail). */
    private function doiToken(): string
    {
        return (string) $this->db->einWert('SELECT bestaetigungs_token FROM antrag_rohdaten ORDER BY id DESC LIMIT 1');
    }

    public function testEinreichenLegtUnbestaetigtesMitgliedUndMailAn(): void
    {
        $ref = $this->service->einreichen($this->eingabe(), 'iphash');

        self::assertNotSame('', $ref);

        $mitglied = $this->db->eineZeile("SELECT * FROM mitglied WHERE nachname = 'de Vries'");
        self::assertSame(Mitgliedsstatus::UNBESTAETIGT, $mitglied['status']);
        self::assertSame('6291 AB', $mitglied['plz']);

        // DOI-Mail mit Priorität „sofort" in der Queue.
        $mail = $this->db->eineZeile("SELECT * FROM email_queue WHERE empfaenger = 'jan@example.nl'");
        self::assertNotNull($mail);
        self::assertSame(MailDienst::PRIO_SOFORT, (int) $mail['prioritaet']);
        self::assertStringContainsString('/antrag/bestaetigen?token=' . $this->doiToken(), (string) $mail['body']);

        // Mandatstext: Gläubiger-ID und Kontoinhaber gehören in Text- und HTML-Teil.
        self::assertStringContainsString('DE98ZZZ09999999999', (string) $mail['body']);
        self::assertStringContainsString('Jan de Vries', (string) $mail['body']);
        self::assertStringContainsString('DE98ZZZ09999999999', (string) $mail['body_html']);
    }

    /**
     * Das Bestätigungstoken darf nur per Mail gehen — der Rückgabewert von
     * einreichen() landet in der URL der Warteseite.
     */
    public function testEinreichenGibtResendTokenNichtBestaetigungstokenZurueck(): void
    {
        $ref = $this->service->einreichen($this->eingabe(), null);

        self::assertNotSame($this->doiToken(), $ref);
        self::assertSame($ref, (string) $this->db->einWert('SELECT resend_token FROM antrag_rohdaten LIMIT 1'));
        self::assertSame('unbekannt', $this->service->bestaetige($ref));
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
        $this->service->einreichen($this->eingabe(), null);
        $token = $this->doiToken();

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

    public function testErneutSendenIstWaehrendDerSperrfristGeblockt(): void
    {
        $ref = $this->service->einreichen($this->eingabe(), null);

        // Direkt nach dem Einreichen läuft die Sperre noch.
        self::assertSame('gesperrt', $this->service->erneutSenden($ref));
        self::assertSame(1, (int) $this->db->einWert('SELECT COUNT(*) FROM email_queue'));

        $status = $this->service->warteStatus($ref);
        self::assertSame('offen', $status['zustand']);
        self::assertGreaterThan(0, $status['wartet_sekunden']);
    }

    public function testErneutSendenNachAblaufDerSperrfrist(): void
    {
        $ref = $this->service->einreichen($this->eingabe(), null);
        $this->sperrfristAbgelaufen();

        self::assertSame('ok', $this->service->erneutSenden($ref));
        self::assertSame(2, (int) $this->db->einWert('SELECT COUNT(*) FROM email_queue'));

        // Der Versand startet die Sperre neu.
        self::assertSame('gesperrt', $this->service->erneutSenden($ref));
    }

    public function testErneutSendenNurBeiUnbestaetigt(): void
    {
        $ref = $this->service->einreichen($this->eingabe(), null);
        $this->service->bestaetige($this->doiToken());

        self::assertSame('schon', $this->service->erneutSenden($ref));
        self::assertSame('bestaetigt', $this->service->warteStatus($ref)['zustand']);
    }

    public function testUnbekanntesResendTokenWirdAbgewiesen(): void
    {
        self::assertSame('unbekannt', $this->service->erneutSenden('gibt-es-nicht'));
        self::assertSame('unbekannt', $this->service->warteStatus('gibt-es-nicht')['zustand']);
    }

    /** Antrag künstlich altern lassen, damit die Sperrfrist abgelaufen ist. */
    private function sperrfristAbgelaufen(): void
    {
        $alt = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))
            ->modify('-' . (AntragService::RESEND_SPERRE_SEKUNDEN + 60) . ' seconds')
            ->format('Y-m-d H:i:s');

        $this->db->ausfuehren('UPDATE antrag_rohdaten SET eingegangen_am = :am, erneut_gesendet_am = NULL', ['am' => $alt]);
    }
}
