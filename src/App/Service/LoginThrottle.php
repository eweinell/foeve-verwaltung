<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\BenutzerRepository;

/**
 * Brute-Force-Schutz (F9): nach 5 Fehlversuchen 15 Minuten Kontosperre.
 * Sperren und Fehlversuche werden pro Konto in der Tabelle benutzer geführt und
 * über das Audit-Log protokolliert (durch den Aufrufer).
 */
final class LoginThrottle
{
    public const MAX_FEHLVERSUCHE = 5;
    public const SPERRE_MINUTEN = 15;

    public function __construct(private readonly BenutzerRepository $benutzer)
    {
    }

    /**
     * @param array<string,mixed> $benutzer
     */
    public function istGesperrt(array $benutzer): bool
    {
        return $this->gesperrtBis($benutzer) !== null;
    }

    /**
     * @param array<string,mixed> $benutzer
     */
    public function gesperrtBis(array $benutzer): ?\DateTimeImmutable
    {
        $bis = $benutzer['gesperrt_bis'] ?? null;
        if ($bis === null || $bis === '') {
            return null;
        }
        $zeitpunkt = new \DateTimeImmutable((string) $bis, new \DateTimeZone('Europe/Berlin'));

        return $zeitpunkt > $this->jetzt() ? $zeitpunkt : null;
    }

    /**
     * Registriert einen Fehlversuch. Ab MAX_FEHLVERSUCHE wird das Konto gesperrt
     * und der Zähler zurückgesetzt (frisches Fenster nach Ablauf der Sperre).
     *
     * @return array{gesperrt:bool,verbleibend:int}
     */
    public function registriereFehlversuch(int $id): array
    {
        $anzahl = $this->benutzer->erhoeheFehlversuche($id);

        if ($anzahl >= self::MAX_FEHLVERSUCHE) {
            $bis = $this->jetzt()->modify('+' . self::SPERRE_MINUTEN . ' minutes')->format('Y-m-d H:i:s');
            $this->benutzer->setzeSperre($id, $bis);
            $this->benutzer->setzeFehlversuche($id, 0);

            return ['gesperrt' => true, 'verbleibend' => 0];
        }

        return ['gesperrt' => false, 'verbleibend' => self::MAX_FEHLVERSUCHE - $anzahl];
    }

    public function registriereErfolg(int $id): void
    {
        $this->benutzer->merkeLogin($id);
    }

    private function jetzt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin'));
    }
}
