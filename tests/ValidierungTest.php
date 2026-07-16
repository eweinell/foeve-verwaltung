<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\Validierung;
use PHPUnit\Framework\TestCase;

final class ValidierungTest extends TestCase
{
    private Validierung $v;

    protected function setUp(): void
    {
        $this->v = new Validierung();
    }

    public function testPlzDe(): void
    {
        self::assertTrue($this->v->plzGueltig('DE', '52134'));
        self::assertFalse($this->v->plzGueltig('DE', '5213'));
        self::assertFalse($this->v->plzGueltig('DE', '521345'));
    }

    public function testPlzBe(): void
    {
        self::assertTrue($this->v->plzGueltig('BE', '4700'));
        self::assertFalse($this->v->plzGueltig('BE', '47000'));
    }

    public function testPlzNl(): void
    {
        self::assertTrue($this->v->plzGueltig('NL', '6291 AB'));
        self::assertTrue($this->v->plzGueltig('NL', '6291AB'));
        self::assertFalse($this->v->plzGueltig('NL', '6291'));
    }

    public function testPlzNlNormalisieren(): void
    {
        self::assertSame('6291 AB', $this->v->plzNormalisieren('NL', '6291ab'));
        self::assertSame('6291 AB', $this->v->plzNormalisieren('NL', '6291 AB'));
    }

    public function testPlzAnderesLandNurNichtLeer(): void
    {
        self::assertTrue($this->v->plzGueltig('FR', '75001'));
        self::assertFalse($this->v->plzGueltig('FR', ''));
    }

    public function testIbanDeGueltig(): void
    {
        self::assertTrue($this->v->ibanGueltig('DE89370400440532013000'));
        self::assertTrue($this->v->ibanGueltig('DE89 3704 0044 0532 0130 00'));
    }

    public function testIbanDeFalschePruefziffer(): void
    {
        self::assertFalse($this->v->ibanGueltig('DE89370400440532013001'));
        self::assertFalse($this->v->ibanGueltig('DE00370400440532013000'));
    }

    public function testIbanBeUndNlGueltig(): void
    {
        self::assertTrue($this->v->ibanGueltig('BE68539007547034'));
        self::assertTrue($this->v->ibanGueltig('NL91ABNA0417164300'));
    }

    public function testIbanFalscheLaenge(): void
    {
        // DE mit zu wenigen Stellen.
        self::assertFalse($this->v->ibanGueltig('DE8937040044053201300'));
    }

    public function testIbanGruppieren(): void
    {
        self::assertSame('DE89 3704 0044 0532 0130 00', $this->v->ibanGruppieren('DE89370400440532013000'));
    }
}
