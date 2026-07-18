<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Mitgliedsstatus;
use App\Repository\MitgliedRepository;
use App\Service\Einstellungen;
use App\Support\Ansicht;
use App\Support\Db;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Startseite der Verwaltung. Zeigt die offenen (bestätigten) Anträge (F2) sowie
 * den Stand der Mail-Queue (F6/AP4) inkl. Warnung bei hängendem Cron.
 */
final class DashboardController
{
    public function __construct(
        private readonly Ansicht $ansicht,
        private readonly MitgliedRepository $mitglieder,
        private readonly Db $db,
        private readonly Einstellungen $einstellungen,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->ansicht->render($response, 'dashboard.twig', [
            'aktuelle_seite'  => 'start',
            'offene_antraege' => $this->mitglieder->nachStatus(Mitgliedsstatus::BEANTRAGT, 10),
            'anzahl_antraege' => $this->mitglieder->anzahlNachStatus(Mitgliedsstatus::BEANTRAGT),
            'anzahl_aktiv'    => $this->mitglieder->anzahlNachStatus(Mitgliedsstatus::AKTIV),
            'queue'           => $this->queueStand(),
        ]);
    }

    /**
     * @return array{wartend:int,fehler:int,letzter_lauf:?string,cron_alt:bool}
     */
    private function queueStand(): array
    {
        $wartend = (int) $this->db->einWert("SELECT COUNT(*) FROM email_queue WHERE status = 'wartend'");
        $fehler = (int) $this->db->einWert("SELECT COUNT(*) FROM email_queue WHERE status = 'fehler'");
        $letzter = $this->einstellungen->hole('mail_letzter_lauf', '');

        $cronAlt = false;
        if ($letzter !== '') {
            $letzterZeit = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $letzter, new \DateTimeZone('Europe/Berlin'));
            if ($letzterZeit instanceof \DateTimeImmutable) {
                $cronAlt = $letzterZeit < new \DateTimeImmutable('-10 minutes', new \DateTimeZone('Europe/Berlin'));
            }
        } elseif ($wartend > 0) {
            $cronAlt = true;
        }

        return ['wartend' => $wartend, 'fehler' => $fehler, 'letzter_lauf' => $letzter ?: null, 'cron_alt' => $cronAlt];
    }
}
