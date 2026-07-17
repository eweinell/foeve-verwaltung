<?php

declare(strict_types=1);

namespace App\Tests;

use App\Repository\VorlageRepository;
use App\Service\AnredeDienst;
use App\Service\Einstellungen;
use App\Service\VorlagenService;
use App\Support\Db;
use App\Tests\Support\TestDb;
use PHPUnit\Framework\TestCase;

final class VorlagenServiceTest extends TestCase
{
    private Db $db;
    private VorlagenService $service;

    protected function setUp(): void
    {
        $this->db = TestDb::erstellen();
        $this->service = new VorlagenService(new VorlageRepository($this->db), new AnredeDienst(), new Einstellungen($this->db));
    }

    public function testRenderErsetztPlatzhalter(): void
    {
        $text = 'Hallo {{vorname}} {{nachname}}, Beitrag {{beitrag}} EUR.';
        self::assertSame('Hallo Anna Muster, Beitrag 30,00 EUR.', $this->service->render($text, [
            'vorname' => 'Anna', 'nachname' => 'Muster', 'beitrag' => '30,00',
        ]));
    }

    public function testUnbekannterPlatzhalterWirdAbgewiesen(): void
    {
        $this->expectException(\DomainException::class);
        $this->service->validiere('Hallo {{unbekannt}}');
    }

    public function testBriefanredeProAnrede(): void
    {
        foreach ([
            ['anrede' => 'herr', 'nachname' => 'Muster', 'erwartet' => 'Sehr geehrter Herr Muster'],
            ['anrede' => 'frau', 'nachname' => 'Klein', 'erwartet' => 'Sehr geehrte Frau Klein'],
            ['anrede' => 'familie', 'nachname' => 'Muster', 'erwartet' => 'Sehr geehrte Familie Muster'],
        ] as $fall) {
            $ergebnis = $this->service->rendere('begruessung', $this->service->kontext($fall));
            self::assertStringContainsString($fall['erwartet'], $ergebnis['text']);
        }
    }

    public function testDefaultUndOverride(): void
    {
        $default = $this->service->hole('begruessung');
        self::assertFalse($default['ist_override']);
        self::assertTrue($default['system']);

        $this->service->speichern('begruessung', 'Neuer Betreff {{mitgliedsnummer}}', 'Neuer Text {{briefanrede}}', null);
        $nach = $this->service->hole('begruessung');
        self::assertTrue($nach['ist_override']);
        self::assertSame('Neuer Betreff {{mitgliedsnummer}}', $nach['betreff']);

        // „Löschen" setzt auf den Default zurück.
        $this->service->loeschen('begruessung');
        self::assertFalse($this->service->hole('begruessung')['ist_override']);
    }

    public function testSpeichernValidiertPlatzhalter(): void
    {
        $this->expectException(\DomainException::class);
        $this->service->speichern('eigene_vorlage', 'Betreff', 'Text mit {{quatsch}}', null);
    }

    public function testAlleEnthaeltSystemvorlagen(): void
    {
        $schluessel = array_column($this->service->alle(), 'schluessel');
        foreach (['begruessung', 'kuendigungsbestaetigung', 'prenotification', 'doi_bestaetigung', 'login_code'] as $s) {
            self::assertContains($s, $schluessel);
        }
    }
}
