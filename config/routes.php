<?php

declare(strict_types=1);

use App\Controller\AntragController;
use App\Controller\AuditLogController;
use App\Controller\AuthController;
use App\Controller\BenutzerController;
use App\Controller\DashboardController;
use App\Controller\EinstellungenController;
use App\Controller\MitgliedController;
use App\Controller\ProfilController;
use App\Middleware\AuthMiddleware;
use App\Middleware\RolleMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

/**
 * Routendefinition. Öffentliche Routen (Login/2FA) liegen außerhalb der
 * Auth-Gruppe; alles Übrige ist durch AuthMiddleware geschützt (CLAUDE.md Regel 5).
 * Der Admin-Bereich ist zusätzlich per RolleMiddleware auf „admin" beschränkt.
 */
return static function (App $app): void {
    $container = $app->getContainer();

    // --- Öffentlich: Antragsformular & Double-Opt-In (F2) ---
    $app->get('/antrag', [AntragController::class, 'formular']);
    $app->post('/antrag', [AntragController::class, 'absenden']);
    $app->get('/antrag/warten', [AntragController::class, 'warten']);
    $app->post('/antrag/erneut-senden', [AntragController::class, 'erneutSenden']);
    $app->get('/antrag/bestaetigen', [AntragController::class, 'bestaetigenFormular']);
    $app->post('/antrag/bestaetigen', [AntragController::class, 'bestaetigen']);

    // --- Öffentlich: Login & zweiter Faktor ---
    $app->get('/login', [AuthController::class, 'loginFormular']);
    $app->post('/login', [AuthController::class, 'login']);
    $app->get('/login/2fa', [AuthController::class, 'zweiFaktorFormular']);
    $app->post('/login/2fa', [AuthController::class, 'zweiFaktor']);

    // --- Geschützt: alles hinter der Auth-Middleware ---
    $app->group('', function (RouteCollectorProxy $group): void {
        $group->get('/', [DashboardController::class, 'index']);

        // Mitgliederverwaltung (F1) — admin und vorstand.
        $group->get('/mitglieder', [MitgliedController::class, 'liste']);
        $group->get('/mitglieder/{id:[0-9]+}', [MitgliedController::class, 'detail']);
        $group->get('/mitglieder/{id:[0-9]+}/bearbeiten', [MitgliedController::class, 'bearbeitenFormular']);
        $group->post('/mitglieder/{id:[0-9]+}/bearbeiten', [MitgliedController::class, 'bearbeiten']);
        $group->post('/mitglieder/{id:[0-9]+}/beitrag', [MitgliedController::class, 'beitragAendern']);
        $group->post('/mitglieder/{id:[0-9]+}/aktivieren', [MitgliedController::class, 'aktivieren']);
        $group->post('/mitglieder/{id:[0-9]+}/ablehnen', [MitgliedController::class, 'ablehnen']);
        $group->post('/mitglieder/{id:[0-9]+}/kuendigen', [MitgliedController::class, 'kuendigen']);
        $group->post('/mitglieder/{id:[0-9]+}/kuendigung-widerrufen', [MitgliedController::class, 'kuendigungWiderrufen']);
        $group->post('/mitglieder/{id:[0-9]+}/austritt', [MitgliedController::class, 'austritt']);
        $group->post('/mitglieder/{id:[0-9]+}/revert', [MitgliedController::class, 'revert']);

        $group->get('/passwort-aendern', [AuthController::class, 'passwortAendernFormular']);
        $group->post('/passwort-aendern', [AuthController::class, 'passwortAendern']);
        $group->post('/logout', [AuthController::class, 'logout']);

        // Eigenes Profil (zweiter Faktor)
        $group->get('/profil', [ProfilController::class, 'index']);
        $group->get('/profil/totp', [ProfilController::class, 'totpEinrichten']);
        $group->post('/profil/totp', [ProfilController::class, 'totpBestaetigen']);
        $group->post('/profil/email', [ProfilController::class, 'emailAktivieren']);
        $group->post('/profil/2fa/aus', [ProfilController::class, 'deaktivieren']);

        // Admin-Bereich: Benutzerverwaltung, Einstellungen, Audit-Log
        $group->group('/einstellungen', function (RouteCollectorProxy $admin): void {
            $admin->get('', [EinstellungenController::class, 'index']);
            $admin->post('', [EinstellungenController::class, 'speichern']);

            $admin->get('/benutzer', [BenutzerController::class, 'liste']);
            $admin->get('/benutzer/neu', [BenutzerController::class, 'neuFormular']);
            $admin->post('/benutzer', [BenutzerController::class, 'anlegen']);
            $admin->post('/benutzer/{id:[0-9]+}', [BenutzerController::class, 'aktualisieren']);
            $admin->post('/benutzer/{id:[0-9]+}/aktiv', [BenutzerController::class, 'aktivSetzen']);
            $admin->post('/benutzer/{id:[0-9]+}/passwort', [BenutzerController::class, 'passwortZuruecksetzen']);

            $admin->get('/audit', [AuditLogController::class, 'index']);
        })->add(new RolleMiddleware('admin'));
    })->add($container->get(AuthMiddleware::class));
};
