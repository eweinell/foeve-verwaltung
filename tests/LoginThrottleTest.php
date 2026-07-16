<?php

declare(strict_types=1);

namespace App\Tests;

use App\Repository\BenutzerRepository;
use App\Service\LoginThrottle;
use App\Support\Db;
use App\Support\Passwoerter;
use App\Tests\Support\TestDb;
use PHPUnit\Framework\TestCase;

final class LoginThrottleTest extends TestCase
{
    private Db $db;
    private BenutzerRepository $repo;
    private LoginThrottle $throttle;

    protected function setUp(): void
    {
        $this->db = TestDb::erstellen();
        $this->repo = new BenutzerRepository($this->db);
        $this->throttle = new LoginThrottle($this->repo);
    }

    private function benutzer(): array
    {
        $id = (int) $this->repo->anlegen('Test', 'test@verein.de', Passwoerter::hash('geheim12345'), 'vorstand', false);

        return $this->repo->findePerId($id);
    }

    public function testSperreNachFuenfFehlversuchen(): void
    {
        $benutzer = $this->benutzer();
        $id = (int) $benutzer['id'];

        for ($i = 1; $i <= LoginThrottle::MAX_FEHLVERSUCHE - 1; $i++) {
            $ergebnis = $this->throttle->registriereFehlversuch($id);
            self::assertFalse($ergebnis['gesperrt'], "Nach {$i} Versuchen noch nicht gesperrt.");
        }

        $letzter = $this->throttle->registriereFehlversuch($id);
        self::assertTrue($letzter['gesperrt'], 'Nach 5 Versuchen muss gesperrt sein.');

        self::assertTrue($this->throttle->istGesperrt($this->repo->findePerId($id)));
    }

    public function testErfolgReichtFehlversucheZurueck(): void
    {
        $benutzer = $this->benutzer();
        $id = (int) $benutzer['id'];

        $this->throttle->registriereFehlversuch($id);
        $this->throttle->registriereFehlversuch($id);
        $this->throttle->registriereErfolg($id);

        $frisch = $this->repo->findePerId($id);
        self::assertSame(0, (int) $frisch['fehlversuche']);
        self::assertFalse($this->throttle->istGesperrt($frisch));
    }

    public function testAbgelaufeneSperreGiltNichtMehr(): void
    {
        $benutzer = $this->benutzer();
        $id = (int) $benutzer['id'];

        // Sperre in der Vergangenheit setzen.
        $this->repo->setzeSperre($id, (new \DateTimeImmutable('-1 minute', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d H:i:s'));
        self::assertFalse($this->throttle->istGesperrt($this->repo->findePerId($id)));

        // Sperre in der Zukunft gilt.
        $this->repo->setzeSperre($id, (new \DateTimeImmutable('+10 minutes', new \DateTimeZone('Europe/Berlin')))->format('Y-m-d H:i:s'));
        self::assertTrue($this->throttle->istGesperrt($this->repo->findePerId($id)));
    }
}
