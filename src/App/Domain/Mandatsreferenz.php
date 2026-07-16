<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Mandatsreferenz-Schema (KONZEPT §3.3): FGH-{Mitgliedsnr 4-stellig}-{lfdNr 2-stellig},
 * z. B. FGH-2001-01. Importierte Altmandate behalten ihre Referenz (AP6).
 */
final class Mandatsreferenz
{
    public static function bilde(int $mitgliedsnummer, int $lfdNr): string
    {
        return sprintf('FGH-%04d-%02d', $mitgliedsnummer, $lfdNr);
    }
}
