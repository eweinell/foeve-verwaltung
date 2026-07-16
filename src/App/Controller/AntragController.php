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
            'alt'           => $eingabe,
            'beitrag_min'   => $this->einstellungen->hole('beitrag_min', '12.00'),
            'beitrag_max'   => $this->einstellungen->hole('beitrag_max', '500.00'),
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

        // Bot-Schutz (optional).
        $captchaToken = (string) ($d['tc-token'] ?? $d['captcha_token'] ?? '');
        if (!$this->captcha->pruefe($captchaToken !== '' ? $captchaToken : null)) {
            $this->flash->fehler('Der Bot-Schutz konnte nicht bestätigt werden. Bitte laden Sie die Seite neu.');

            return $this->formular($request, $response, $d);
        }

        [$eingabe, $fehler] = $this->pruefeEingabe($d);
        if ($fehler !== null) {
            $this->flash->fehler($fehler);

            return $this->formular($request, $response, $d);
        }

        $token = $this->antrag->einreichen($eingabe, $ipHash);

        return $response->withHeader('Location', '/antrag/warten?token=' . $token)->withStatus(302);
    }

    public function warten(Request $request, Response $response): Response
    {
        $token = (string) ($request->getQueryParams()['token'] ?? '');

        return $this->ansicht->render($response, 'antrag/warten.twig', ['token' => $token]);
    }

    public function erneutSenden(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $token = (string) ($body['token'] ?? '');
        if ($this->antrag->erneutSenden($token)) {
            $this->flash->erfolg('Wir haben Ihnen die Bestätigungs-E-Mail erneut geschickt.');
        } else {
            $this->flash->info('Es konnte keine E-Mail versendet werden (evtl. bereits bestätigt).');
        }

        return $response->withHeader('Location', '/antrag/warten?token=' . urlencode($token))->withStatus(302);
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
        $telefon = trim((string) ($d['telefon'] ?? ''));
        $iban = $this->validierung->ibanNormalisieren((string) ($d['iban'] ?? ''));
        $mandat = isset($d['mandat']);

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
        if ($anrede !== 'familie' && $vorname === '') {
            return [[], 'Bitte geben Sie Ihren Vornamen an.'];
        }
        if ($nachname === '' || $strasse === '' || $ort === '') {
            return [[], 'Bitte füllen Sie Name und Adresse vollständig aus.'];
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
        if (!$mandat) {
            return [[], 'Bitte stimmen Sie dem SEPA-Lastschriftmandat zu.'];
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
            'telefon'       => $telefon,
            'jahresbeitrag' => number_format($beitrag, 2, '.', ''),
            'iban'          => $iban,
            'kontoinhaber'  => trim((string) ($d['kontoinhaber'] ?? '')) ?: trim($vorname . ' ' . $nachname),
        ];

        return [$eingabe, null];
    }

    /**
     * @return array<int,array{wert:string,label:string}>
     */
    private function stufen(): array
    {
        $roh = $this->einstellungen->hole('beitrag_stufen', '12,30,60,120');
        $stufen = [];
        foreach (array_filter(array_map('trim', explode(',', $roh))) as $s) {
            if (is_numeric($s)) {
                $stufen[] = ['wert' => number_format((float) $s, 2, '.', ''), 'label' => number_format((float) $s, 0, ',', '.') . ' €'];
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
