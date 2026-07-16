<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Krypto-Service für IBANs (CLAUDE.md Regel 3): authentifizierte Verschlüsselung
 * mit libsodium (secretbox = XSalsa20-Poly1305). Schlüssel aus .env (APP_CRYPTO_KEY),
 * base64-kodierter 32-Byte-Schlüssel.
 *
 * Speicherformat: base64( nonce(24) || ciphertext ). Der Nonce wird je Aufruf
 * zufällig erzeugt und mitgespeichert — gleiche IBAN ergibt so unterschiedliche
 * Chiffrate.
 */
final class Krypto
{
    private readonly string $schluessel;

    public function __construct(string $schluesselBase64)
    {
        $roh = base64_decode($schluesselBase64, true);
        if ($roh === false || strlen($roh) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException(
                'APP_CRYPTO_KEY fehlt oder ist ungültig (erwartet: base64 eines 32-Byte-Schlüssels). '
                . 'Erzeugen mit: php -r "echo base64_encode(sodium_crypto_secretbox_keygen());"'
            );
        }
        $this->schluessel = $roh;
    }

    public function verschluesseln(string $klartext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $chiffre = sodium_crypto_secretbox($klartext, $nonce, $this->schluessel);
        $ergebnis = base64_encode($nonce . $chiffre);

        sodium_memzero($klartext);

        return $ergebnis;
    }

    public function entschluesseln(string $gespeichert): string
    {
        $roh = base64_decode($gespeichert, true);
        if ($roh === false || strlen($roh) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new \RuntimeException('Chiffrat beschädigt oder ungültig.');
        }

        $nonce = substr($roh, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $chiffre = substr($roh, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $klartext = sodium_crypto_secretbox_open($chiffre, $nonce, $this->schluessel);
        if ($klartext === false) {
            throw new \RuntimeException('Entschlüsselung fehlgeschlagen (Authentifizierung ungültig).');
        }

        return $klartext;
    }

    /**
     * Maskiert eine IBAN für Anzeige/Export: die ersten 4 und die letzten 4 Zeichen
     * bleiben sichtbar, dazwischen „……". Beispiel:
     *   DE89370400440532013000  ⇒  DE89 …… 3000
     */
    public function maskiereIban(string $iban): string
    {
        $normal = strtoupper(preg_replace('/\s+/', '', $iban) ?? '');
        if (strlen($normal) < 8) {
            return '…… ' . substr($normal, -min(4, strlen($normal)));
        }

        return substr($normal, 0, 4) . ' …… ' . substr($normal, -4);
    }
}
