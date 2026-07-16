<?php

declare(strict_types=1);

namespace App\Tests;

use App\Domain\Mitgliedsstatus as S;
use PHPUnit\Framework\TestCase;

final class StatusmaschineTest extends TestCase
{
    public function testErlaubteUebergaenge(): void
    {
        self::assertTrue(S::darfWechseln(S::UNBESTAETIGT, S::BEANTRAGT));
        self::assertTrue(S::darfWechseln(S::UNBESTAETIGT, S::VERWORFEN));
        self::assertTrue(S::darfWechseln(S::BEANTRAGT, S::AKTIV));
        self::assertTrue(S::darfWechseln(S::BEANTRAGT, S::ABGELEHNT));
        self::assertTrue(S::darfWechseln(S::AKTIV, S::GEKUENDIGT));
        self::assertTrue(S::darfWechseln(S::GEKUENDIGT, S::AKTIV));
        self::assertTrue(S::darfWechseln(S::GEKUENDIGT, S::AUSGESCHIEDEN));
        self::assertTrue(S::darfWechseln(S::AUSGESCHIEDEN, S::ANONYMISIERT));
    }

    public function testVerboteneUebergaenge(): void
    {
        self::assertFalse(S::darfWechseln(S::BEANTRAGT, S::GEKUENDIGT));
        self::assertFalse(S::darfWechseln(S::UNBESTAETIGT, S::AKTIV));
        self::assertFalse(S::darfWechseln(S::ABGELEHNT, S::AKTIV));
        self::assertFalse(S::darfWechseln(S::AKTIV, S::BEANTRAGT));
        self::assertFalse(S::darfWechseln(S::VERWORFEN, S::BEANTRAGT));
    }

    public function testPruefeWechselWirftBeiVerboten(): void
    {
        $this->expectException(\DomainException::class);
        S::pruefeWechsel(S::BEANTRAGT, S::GEKUENDIGT);
    }

    public function testLabelUndBadge(): void
    {
        self::assertSame('gekündigt', S::label(S::GEKUENDIGT));
        self::assertSame('badge-erfolg', S::badge(S::AKTIV));
    }
}
