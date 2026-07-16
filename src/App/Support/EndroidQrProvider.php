<?php

declare(strict_types=1);

namespace App\Support;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use RobThree\Auth\Providers\Qr\IQRCodeProvider;

/**
 * QR-Code-Provider für robthree/twofactorauth, der die QR-Erzeugung an
 * endroid/qr-code delegiert (lokal, kein CDN — KONZEPT §6). Wird nur für die
 * TOTP-Einrichtung genutzt.
 */
final class EndroidQrProvider implements IQRCodeProvider
{
    public function getQRCodeImage(string $qrText, int $size): string
    {
        return Builder::create()
            ->writer(new PngWriter())
            ->data($qrText)
            ->size($size)
            ->margin(8)
            ->build()
            ->getString();
    }

    public function getMimeType(): string
    {
        return 'image/png';
    }
}
