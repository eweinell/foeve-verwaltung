<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\Krypto;
use PHPUnit\Framework\TestCase;

final class KryptoTest extends TestCase
{
    private function krypto(): Krypto
    {
        return new Krypto(base64_encode(str_repeat("\x01", SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
    }

    public function testRoundtrip(): void
    {
        $krypto = $this->krypto();
        $iban = 'DE89370400440532013000';

        $chiffre = $krypto->verschluesseln($iban);

        self::assertNotSame($iban, $chiffre);
        self::assertSame($iban, $krypto->entschluesseln($chiffre));
    }

    public function testGleicherKlartextErgibtUnterschiedlicheChiffren(): void
    {
        $krypto = $this->krypto();

        $a = $krypto->verschluesseln('DE89370400440532013000');
        $b = $krypto->verschluesseln('DE89370400440532013000');

        self::assertNotSame($a, $b, 'Nonce sollte je Aufruf variieren.');
    }

    public function testManipuliertesChiffratWirftFehler(): void
    {
        $krypto = $this->krypto();
        $chiffre = $krypto->verschluesseln('DE89370400440532013000');

        $roh = base64_decode($chiffre, true);
        self::assertIsString($roh);
        $roh[strlen($roh) - 1] = $roh[strlen($roh) - 1] === 'A' ? 'B' : 'A';

        $this->expectException(\RuntimeException::class);
        $krypto->entschluesseln(base64_encode($roh));
    }

    public function testMaskiereIban(): void
    {
        $krypto = $this->krypto();

        self::assertSame('DE89 …… 3000', $krypto->maskiereIban('DE89370400440532013000'));
        self::assertSame('DE89 …… 3000', $krypto->maskiereIban('DE89 3704 0044 0532 0130 00'));
        self::assertSame('NL91 …… 6789', $krypto->maskiereIban('NL91ABNA0417164300'.'6789'));
    }

    public function testUngueltigerSchluesselWirftFehler(): void
    {
        $this->expectException(\RuntimeException::class);
        new Krypto('zu-kurz');
    }
}
