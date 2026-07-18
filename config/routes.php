<?php

declare(strict_types=1);

use App\Controller\AntragController;
use App\Controller\AuditLogController;
use App\Controller\AuthController;
use App\Controller\BenutzerController;
use App\Controller\DashboardController;
use App\Controller\EinstellungenController;
use App\Controller\EinzugController;
use App\Controller\EmailController;
use App\Controller\ForderungController;
use App\Controller\VorlageController;
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
        $group->get('/mitglieder/neu', [MitgliedController::class, 'neuFormular']);
        $group->post('/mitglieder/neu', [MitgliedController::class, 'neu']);
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

        // Mandate & Beiträge am Mitglied (AP2)
        $group->post('/mitglieder/{id:[0-9]+}/zahlweise', [MitgliedController::class, 'zahlweiseUmstellen']);
        $group->post('/mitglieder/{id:[0-9]+}/mandat', [MitgliedController::class, 'mandatAnlegen']);
        $group->post('/mitglieder/{id:[0-9]+}/gebuehr', [MitgliedController::class, 'gebuehrAnlegen']);
        $group->post('/mandat/{mandatId:[0-9]+}/widerruf', [MitgliedController::class, 'mandatWiderrufen']);
        $group->post('/mandat/{mandatId:[0-9]+}/deaktivieren', [MitgliedController::class, 'mandatDeaktivieren']);
        $group->post('/forderung/{forderungId:[0-9]+}/storno', [MitgliedController::class, 'forderungStornieren']);
        $group->post('/forderung/{forderungId:[0-9]+}/bezahlt', [MitgliedController::class, 'forderungBezahlt']);

        // Sollstellung & offene Posten (AP2)
        $group->get('/sollstellung', [ForderungController::class, 'sollstellung']);
        $group->post('/sollstellung', [ForderungController::class, 'sollstellungAusfuehren']);
        $group->get('/forderungen', [ForderungController::class, 'offenePosten']);

        // E-Mail-System / Versandaktionen (AP4)
        $group->get('/email', [EmailController::class, 'uebersicht']);
        $group->get('/email/neu', [EmailController::class, 'assistent']);
        $group->post('/email/vorschau', [EmailController::class, 'vorschau']);
        $group->post('/email/testmail', [EmailController::class, 'testmail']);
        $group->post('/email', [EmailController::class, 'starten']);
        $group->get('/email/vorlagen', [VorlageController::class, 'liste']);
        $group->get('/email/vorlagen/{schluessel:[a-z0-9_]+}', [VorlageController::class, 'bearbeiten']);
        $group->post('/email/vorlagen/{schluessel:[a-z0-9_]+}', [VorlageController::class, 'speichern']);
        $group->post('/email/vorlagen/{schluessel:[a-z0-9_]+}/zuruecksetzen', [VorlageController::class, 'zuruecksetzen']);
        $group->get('/email/{id:[0-9]+}', [EmailController::class, 'detail']);
        $group->post('/email/{id:[0-9]+}/neu-einreihen', [EmailController::class, 'neuEinreihen']);

        // SEPA-Einzugslauf (AP3)
        $group->get('/einzug', [EinzugController::class, 'liste']);
        $group->post('/einzug', [EinzugController::class, 'anlegen']);
        $group->get('/einzug/{id:[0-9]+}', [EinzugController::class, 'detail']);
        $group->post('/einzug/{id:[0-9]+}/position/{forderungId:[0-9]+}/abwaehlen', [EinzugController::class, 'positionAbwaehlen']);
        $group->post('/einzug/{id:[0-9]+}/ankuendigen', [EinzugController::class, 'ankuendigen']);
        $group->post('/einzug/{id:[0-9]+}/exportieren', [EinzugController::class, 'exportieren']);
        $group->get('/einzug/{id:[0-9]+}/download', [EinzugController::class, 'download']);
        $group->post('/einzug/{id:[0-9]+}/abschliessen', [EinzugController::class, 'abschliessen']);
        $group->post('/einzug/{id:[0-9]+}/loeschen', [EinzugController::class, 'loeschen']);
        $group->post('/einzug/{id:[0-9]+}/ruecklastschrift', [EinzugController::class, 'ruecklastschrift']);

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
