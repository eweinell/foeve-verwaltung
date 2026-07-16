<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Mitgliedsstatus;
use App\Repository\MitgliedRepository;
use App\Support\Ansicht;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Startseite der Verwaltung. Zeigt die offenen (bestätigten) Anträge zur
 * Bearbeitung durch den Vorstand (F2). Weitere Kacheln folgen in späteren APs.
 */
final class DashboardController
{
    public function __construct(
        private readonly Ansicht $ansicht,
        private readonly MitgliedRepository $mitglieder,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->ansicht->render($response, 'dashboard.twig', [
            'aktuelle_seite'  => 'start',
            'offene_antraege' => $this->mitglieder->nachStatus(Mitgliedsstatus::BEANTRAGT, 10),
            'anzahl_antraege' => $this->mitglieder->anzahlNachStatus(Mitgliedsstatus::BEANTRAGT),
            'anzahl_aktiv'    => $this->mitglieder->anzahlNachStatus(Mitgliedsstatus::AKTIV),
        ]);
    }
}
