<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Audit;
use App\Support\Ansicht;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Audit-Log-Ansicht — nur Rolle admin. Neueste zuerst, Filter nach Benutzer/Aktion.
 */
final class AuditLogController
{
    public function __construct(
        private readonly Ansicht $ansicht,
        private readonly Audit $audit,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $benutzerId = isset($params['benutzer']) && ctype_digit((string) $params['benutzer'])
            ? (int) $params['benutzer']
            : null;
        $aktion = isset($params['aktion']) ? trim((string) $params['aktion']) : null;

        return $this->ansicht->render($response, 'admin/audit_log.twig', [
            'aktuelle_seite' => 'einstellungen',
            'eintraege'      => $this->audit->liste($benutzerId, $aktion !== '' ? $aktion : null),
            'aktionen'       => $this->audit->aktionen(),
            'filter_aktion'  => $aktion,
        ]);
    }
}
