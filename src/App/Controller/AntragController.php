<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AntragService;
use App\Service\Captcha;
use App\Service\Einstellungen;
use App\Service\Laender;
use App\Service\Validierung;
use App\Support\Ansicht;
use App\Support\Flash;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Öffentliches Antragsformular mit Double-Opt-In (F2). Diese Routen liegen
 * außerhalb der Auth-Middleware. Die DOI-Bestätigung erfolgt bewusst per POST
 * (GET zeigt nur den Button) — Schutz gegen Mail-Scanner.
 */
final class AntragController
{
    private const RATE_LIMIT = 5; // Anträge pro IP und Stunde

    public function __construct(
        private readonly Ansicht $ansicht,
        private readonly Flash $flash,
        private readonly AntragService $antrag,
        private readonly Validierung $validierung,
        private readonly Captcha $captcha,
        private readonly Einstellungen $einstellungen,
    ) {
    }

    public function formular(Request $request, Response $response, array $eingabe = []): Response
    {
        return $this->ansicht->render($response, 'antrag/formular.twig', [
            'stufen'        => $this->stufen(),
            'laender'       => $this->laenderReihenfolge(),
            'captcha_aktiv' => $this->captcha->aktiv(),
            'captcha_key'   => $this->captcha->siteKey(),
            'captcha_skript' => $this->captcha->skriptUrl(),
            'alt'           => $eingabe,
            'beitrag_min'   => $this->einstellungen->hole('beitrag_min', '12.00'),
            'beitrag_max'   => $this->einstellungen->hole('beitrag_max', '500.00'),
            'verein_name'   => $this->antrag->vereinName(),
            'glaeubiger_id' => $this->antrag->glaeubigerId(),
        ]);
    }

    public function absenden(Request $request, Response $response): Response
    {
        $d = (array) $request->getParsedBody();
        $ipHash = $this->antrag->ipHash($this->clientIp($request));

        // Rate-Limit pro IP.
        if ($this->antrag->anzahlProIp($ipHash, 1) >= self::RATE_LIMIT) {
            $this->flash->fehler('Es wurden zu viele Anträge von diesem Anschluss gestellt. Bitte versuchen Sie es später erneut.');

            return $this->formular($request, $response, $d);
        }

        // Bot-Schutz (optional). Feldname wie in der Anmelde-App.
        $captchaToken = (string) ($d[Captcha::TOKEN_FELD] ?? '');
        if (!$this->captcha->pruefe($captchaToken !== '' ? $captchaToken : null)) {
            $this->flash->fehler('Die Sicherheitsprüfung ist fehlgeschlagen. Bitte laden Sie die Seite neu und versuchen Sie es erneut.');

            return $this->formular($request, $response, $d);
        }

        [$eingabe, $fehler] = $this->pruefeEingabe($d);
        if ($fehler !== null) {
            $this->flash->fehler($fehler);

            return $this->formular($request, $response, $d);
        }

        $resendToken = $this->antrag->einreichen($eingabe, $ipHash);

        return $response->withHeader('Location', '/antrag/warten?ref=' . urlencode($resendToken))->withStatus(302);
    }

    /**
     * Warteseite. Angesteuert über das Resend-Token — das Bestätigungstoken
     * steht ausschließlich in der DOI-Mail und nie in einer URL im Browser.
     */
    public function warten(Request $request, Response $response): Response
    {
        $ref = (string) ($request->getQueryParams()['ref'] ?? '');
        $status = $this->antrag->warteStatus($ref);

        return $this->ansicht->render($response, 'antrag/warten.twig', [
            'ref'             => $ref,
            'zustand'         => $status['zustand'],
            'wartet_sekunden' => $status['wartet_sekunden'],
            'sperre'          => AntragService::RESEND_SPERRE_SEKUNDEN,
        ]);
    }

    public function erneutSenden(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $ref = (string) ($body['ref'] ?? '');

        match ($this->antrag->erneutSenden($ref)) {
            'ok'       => $this->flash->erfolg('Wir haben Ihnen die Bestätigungs-E-Mail erneut geschickt. Bitte prüfen Sie Ihr Postfach.'),
            'gesperrt' => $this->flash->fehler('Bitte warten Sie einen Moment, bevor Sie die E-Mail erneut anfordern.'),
            'schon'    => $this->flash->info('Ihre E-Mail-Adresse wurde bereits bestätigt. Sie müssen nichts weiter tun.'),
            default    => $this->flash->fehler('Ungültige Anfrage.'),
        };

        return $response->withHeader('Location', '/antrag/warten?ref=' . urlencode($ref))->withStatus(302);
    }

    /**
     * DOI: GET zeigt NUR den Bestätigungsknopf — bestätigt NICHT.
     */
    public function bestaetigenFormular(Request $request, Response $response): Response
    {
        $token = (string) ($request->getQueryParams()['token'] ?? '');

        return $this->ansicht->render($response, 'antrag/bestaetigen.twig', ['token' => $token]);
    }

    /**
     * DOI: erst der POST bestätigt.
     */
    public function bestaetigen(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $token = (string) ($body['token'] ?? '');
        $ergebnis = $this->antrag->bestaetige($token);

        return $this->ansicht->render($response, 'antrag/ergebnis.twig', ['ergebnis' => $ergebnis]);
    }

    // ---- intern ----------------------------------------------------------

    /**
     * @param array<string,mixed> $d
     * @return array{0:array<string,mixed>,1:?string}  [normalisierte Eingabe, Fehlermeldung|null]
     */
    private function pruefeEingabe(array $d): array
    {
        $anrede = (string) ($d['anrede'] ?? '');
        $vorname = trim((string) ($d['vorname'] ?? ''));
        $nachname = trim((string) ($d['nachname'] ?? ''));
        $strasse = trim((string) ($d['strasse'] ?? ''));
        $land = strtoupper(trim((string) ($d['land'] ?? 'DE')));
        $plz = trim((string) ($d['plz'] ?? ''));
        $ort = trim((string) ($d['ort'] ?? ''));
        $email = trim((string) ($d['email'] ?? ''));
        $iban = $this->validierung->ibanNormalisieren((string) ($d['iban'] ?? ''));
        $kontoinhaber = trim((string) ($d['kontoinhaber'] ?? ''));
        $mandat = isset($d['mandat']);
        $datenschutz = isset($d['datenschutz']);

        $beitragRoh = (string) ($d['jahresbeitrag'] ?? ($d['jahresbeitrag_wunsch'] ?? ''));
        if (($d['jahresbeitrag'] ?? '') === 'wunsch') {
            $beitragRoh = (string) ($d['jahresbeitrag_wunsch'] ?? '');
        }
        $beitrag = (float) str_replace(',', '.', $beitragRoh);
        $min = (float) $this->einstellungen->hole('beitrag_min', '12.00');
        $max = (float) $this->einstellungen->hole('beitrag_max', '500.00');

        if (!in_array($anrede, ['herr', 'frau', 'familie'], true)) {
            return [[], 'Bitte wählen Sie eine Anrede.'];
        }
        if ($nachname === '' || $strasse === '' || $ort === '') {
            return [[], 'Bitte füllen Sie Name und Adresse vollständig aus.'];
        }
        // Regel der Anmelde-App: ohne Vorname (z. B. „Familie Müller") braucht das
        // Mandat einen ausgeschriebenen Kontoinhaber.
        if ($vorname === '' && $kontoinhaber === '') {
            return [[], 'Bitte geben Sie entweder einen Vornamen oder einen abweichenden Kontoinhaber an.'];
        }
        if (!Laender::bekannt($land)) {
            return [[], 'Bitte wählen Sie ein gültiges Land.'];
        }
        if (!$this->validierung->plzGueltig($land, $plz)) {
            return [[], 'Die Postleitzahl passt nicht zum gewählten Land.'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [[], 'Bitte geben Sie eine gültige E-Mail-Adresse an.'];
        }
        if (!$this->validierung->ibanGueltig($iban)) {
            return [[], 'Die angegebene IBAN ist ungültig (Prüfziffer/Länge).'];
        }
        if ($beitrag < $min || $beitrag > $max) {
            return [[], sprintf('Der Jahresbeitrag muss zwischen %s und %s Euro liegen.', number_format($min, 2, ',', '.'), number_format($max, 2, ',', '.'))];
        }
        if (!$datenschutz) {
            return [[], 'Bitte stimmen Sie der Datenschutzerklärung zu.'];
        }
        if (!$mandat) {
            return [[], 'Bitte erteilen Sie das SEPA-Lastschriftmandat.'];
        }

        $eingabe = [
            'anrede'        => $anrede,
            'vorname'       => $vorname,
            'nachname'      => $nachname,
            'strasse'       => $strasse,
            'plz'           => $this->validierung->plzNormalisieren($land, $plz),
            'ort'           => $ort,
            'land'          => $land,
            'email'         => $email,
            'jahresbeitrag' => number_format($beitrag, 2, '.', ''),
            'iban'          => $iban,
            'kontoinhaber'  => $kontoinhaber ?: trim($vorname . ' ' . $nachname),
        ];

        return [$eingabe, null];
    }

    /** Beitragsstufe, die in der Anmelde-App als Durchschnittsbeitrag markiert und vorausgewählt war. */
    private const STUFE_EMPFOHLEN = '30.00';

    /**
     * @return array<int,array{wert:string,label:string,empfohlen:bool}>
     */
    private function stufen(): array
    {
        $roh = $this->einstellungen->hole('beitrag_stufen', '12,30,60,120');
        $stufen = [];
        foreach (array_filter(array_map('trim', explode(',', $roh))) as $s) {
            if (is_numeric($s)) {
                $wert = number_format((float) $s, 2, '.', '');
                $stufen[] = [
                    'wert'      => $wert,
                    'label'     => number_format((float) $s, 0, ',', '.') . ' € / Jahr',
                    'empfohlen' => $wert === self::STUFE_EMPFOHLEN,
                ];
            }
        }

        return $stufen;
    }

    /**
     * @return array<string,string>
     */
    private function laenderReihenfolge(): array
    {
        return Laender::NAMEN;
    }

    private function clientIp(Request $request): ?string
    {
        $server = $request->getServerParams();
        $ip = $server['REMOTE_ADDR'] ?? null;

        return is_string($ip) ? $ip : null;
    }
}
