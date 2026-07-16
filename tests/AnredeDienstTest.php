<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\AnredeDienst;
use PHPUnit\Framework\TestCase;

final class AnredeDienstTest extends TestCase
{
    private AnredeDienst $anrede;

    protected function setUp(): void
    {
        $this->anrede = new AnredeDienst();
    }

    public function testBriefanredeHerr(): void
    {
        self::assertSame('Sehr geehrter Herr Muster', $this->anrede->briefanrede(['anrede' => 'herr', 'nachname' => 'Muster']));
    }

    public function testBriefanredeFrau(): void
    {
        self::assertSame('Sehr geehrte Frau Klein', $this->anrede->briefanrede(['anrede' => 'frau', 'nachname' => 'Klein']));
    }

    public function testBriefanredeFamilie(): void
    {
        self::assertSame('Sehr geehrte Familie Muster', $this->anrede->briefanrede(['anrede' => 'familie', 'nachname' => 'Muster']));
    }

    public function testBriefanredeManuellHatVorrang(): void
    {
        $m = ['anrede' => 'frau', 'nachname' => 'Meier', 'briefanrede_manuell' => 'Sehr geehrte Frau Dr. Meier'];
        self::assertSame('Sehr geehrte Frau Dr. Meier', $this->anrede->briefanrede($m));
    }

    public function testAdresszeileFamilie(): void
    {
        self::assertSame('Familie Muster', $this->anrede->adresszeile(['anrede' => 'familie', 'nachname' => 'Muster']));
    }

    public function testAdresszeilePerson(): void
    {
        self::assertSame('Anna Muster', $this->anrede->adresszeile(['anrede' => 'frau', 'vorname' => 'Anna', 'nachname' => 'Muster']));
    }

    public function testAdresszeileOhneVorname(): void
    {
        self::assertSame('Muster', $this->anrede->adresszeile(['anrede' => 'herr', 'vorname' => '', 'nachname' => 'Muster']));
    }

    public function testAdresszeileManuellHatVorrang(): void
    {
        $m = ['anrede' => 'familie', 'nachname' => 'Muster', 'adresszeile_manuell' => 'Eheleute Meier-Schulz'];
        self::assertSame('Eheleute Meier-Schulz', $this->anrede->adresszeile($m));
    }

    public function testPostanschriftInlandOhneLaenderzeile(): void
    {
        $m = ['anrede' => 'familie', 'nachname' => 'Muster', 'strasse' => 'Musterweg 1', 'plz' => '52134', 'ort' => 'Herzogenrath', 'land' => 'DE'];
        self::assertSame(['Familie Muster', 'Musterweg 1', '52134 Herzogenrath'], $this->anrede->postanschriftZeilen($m));
    }

    public function testPostanschriftAuslandMitLaenderzeile(): void
    {
        $m = ['anrede' => 'herr', 'vorname' => 'Jan', 'nachname' => 'de Vries', 'strasse' => 'Dorpsstraat 5', 'plz' => '6291 AB', 'ort' => 'Vaals', 'land' => 'NL'];
        self::assertSame(['Jan de Vries', 'Dorpsstraat 5', '6291 AB Vaals', 'Niederlande'], $this->anrede->postanschriftZeilen($m));
    }
}
