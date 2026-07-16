<?php

declare(strict_types=1);

namespace App\Controller;

use App\Support\Ansicht;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Startseite der Verwaltung. In AP0 bewusst schlicht — die Fachmodule
 * (Mitglieder, Anträge, Beiträge …) folgen in AP1 ff.
 */
final class DashboardController
{
    public function __construct(private readonly Ansicht $ansicht)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->ansicht->render($response, 'dashboard.twig', ['aktuelle_seite' => 'start']);
    }
}
