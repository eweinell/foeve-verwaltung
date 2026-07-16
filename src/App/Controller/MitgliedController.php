<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Mitgliedsstatus;
use App\Repository\MitgliedRepository;
use App\Service\AnredeDienst;
use App\Service\Einstellungen;
use App\Service\Laender;
use App\Service\MitgliedService;
use App\Service\Validierung;
use App\Support\Ansicht;
use App\Support\Db;
use App\Support\Flash;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;

/**
 * Mitgliederverwaltung (F1): Liste mit Suche/Filter, Detailansicht mit Reitern,
 * versionierte Stammdaten-/Beitragsänderung, Änderungshistorie mit Revert und die
 * Statusaktionen des Lebenszyklus. Zugänglich für admin und vorstand.
 */
final class MitgliedController
{
    private const ANREDEN = ['herr', 'frau', 'familie'];
    private const ZAHLWEISEN = ['lastschrift', 'selbstzahler'];

    public function __construct(
        private readonly Ansicht $ansicht,
        private readonly Flash $flash,
        private readonly Db $db,
        private readonly MitgliedRepository $mitglieder,
        private readonly MitgliedService $service,
        private readonly Validierung $validierung,
        private readonly AnredeDienst $anrede,
        private readonly Einstellungen $einstellungen,
    ) {
    }

    public function liste(Request $request, Response $response): Response
    {
        $p = $request->getQueryParams();
        $filter = [
            'q'         => $p['q'] ?? '',
            'status'    => $p['status'] ?? '',
            'zahlweise' => $p['zahlweise'] ?? '',
            'land'      => $p['land'] ?? '',
            'email'     => $p['email'] ?? '',
        ];
        $seite = isset($p['seite']) && ctype_digit((string) $p['seite']) ? (int) $p['seite'] : 1;
        $sort = (string) ($p['sort'] ?? 'nachname');
        $richtung = (string) ($p['richtung'] ?? 'asc');

        $ergebnis = $this->mitglieder->suchen($filter, $seite, 25, $sort, $richtung);

        return $this->ansicht->render($response, 'mitglied/liste.twig', [
            'aktuelle_seite' => 'mitglieder',
            'filter'         => $filter,
            'sort'           => $sort,
            'richtung'       => $richtung,
            'ergebnis'       => $ergebnis,
            'statusliste'    => Mitgliedsstatus::alle(),
            'laender'        => Laender::NAMEN,
            'status_meta'    => $this->statusMeta(),
        ]);
    }

    public function detail(Request $request, Response $response, array $args): Response
    {
        $mitglied = $this->laden((int) $args['id'], $request);
        $tab = (string) ($request->getQueryParams()['tab'] ?? 'stammdaten');

        $daten = [
            'aktuelle_seite' => 'mitglieder',
            'mitglied'       => $mitglied,
            'tab'            => $tab,
            'status_meta'    => $this->statusMeta(),
            'briefanrede'    => $this->anrede->briefanrede($mitglied),
            'postanschrift'  => $this->anrede->postanschriftZeilen($mitglied),
            'laender'        => Laender::NAMEN,
        ];

        if ($tab === 'email') {
            $daten['emails'] = $this->db->alleZeilen(
                'SELECT * FROM email_queue WHERE mitglied_id = :id ORDER BY id DESC',
                ['id' => $mitglied['id']],
            );
        } elseif ($tab === 'historie') {
            $daten['historie'] = $this->historie((int) $mitglied['id'], $mitglied);
        }

        return $this->ansicht->render($response, 'mitglied/detail.twig', $daten);
    }

    public function bearbeitenFormular(Request $request, Response $response, array $args): Response
    {
        $mitglied = $this->laden((int) $args['id'], $request);

        return $this->ansicht->render($response, 'mitglied/bearbeiten.twig', [
            'aktuelle_seite' => 'mitglieder',
            'mitglied'       => $mitglied,
            'anreden'        => self::ANREDEN,
            'zahlweisen'     => self::ZAHLWEISEN,
            'laender'        => Laender::NAMEN,
        ]);
    }

    public function bearbeiten(Request $request, Response $response, array $args): Response
    {
        $mitglied = $this->laden((int) $args['id'], $request);
        $d = (array) $request->getParsedBody();

        $nachname = trim((string) ($d['nachname'] ?? ''));
        $land = strtoupper(trim((string) ($d['land'] ?? 'DE')));
        $plz = trim((string) ($d['plz'] ?? ''));
        $email = trim((string) ($d['email'] ?? ''));

        if ($nachname === '') {
            return $this->zurueckMitFehler($response, $args['id'], 'Der Nachname darf nicht leer sein.', '/bearbeiten');
        }
        if ($plz !== '' && !$this->validierung->plzGueltig($land, $plz)) {
            return $this->zurueckMitFehler($response, $args['id'], 'Die PLZ passt nicht zum gewählten Land.', '/bearbeiten');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->zurueckMitFehler($response, $args['id'], 'Die E-Mail-Adresse ist ungültig.', '/bearbeiten');
        }

        $keinEmail = isset($d['kein_email_kontakt']) ? 1 : 0;
        // Warnhinweis bei widersprüchlicher E-Mail-Kennzeichnung.
        if ($keinEmail === 1 && $email !== '') {
            $this->flash->warnung('Hinweis: „kein E-Mail-Kontakt" ist gesetzt, obwohl eine E-Mail-Adresse hinterlegt ist.');
        } elseif ($keinEmail === 0 && $email === '') {
            $this->flash->warnung('Hinweis: Es ist keine E-Mail-Adresse hinterlegt — ggf. „kein E-Mail-Kontakt" setzen (Postversand).');
        }

        $felder = [
            'anrede'              => in_array($d['anrede'] ?? '', self::ANREDEN, true) ? $d['anrede'] : $mitglied['anrede'],
            'vorname'             => trim((string) ($d['vorname'] ?? '')) ?: null,
            'nachname'            => $nachname,
            'briefanrede_manuell' => trim((string) ($d['briefanrede_manuell'] ?? '')) ?: null,
            'adresszeile_manuell' => trim((string) ($d['adresszeile_manuell'] ?? '')) ?: null,
            'strasse'             => trim((string) ($d['strasse'] ?? '')) ?: null,
            'plz'                 => $plz !== '' ? $this->validierung->plzNormalisieren($land, $plz) : null,
            'ort'                 => trim((string) ($d['ort'] ?? '')) ?: null,
            'land'                => Laender::bekannt($land) ? $land : 'DE',
            'email'               => $email ?: null,
            'telefon'             => trim((string) ($d['telefon'] ?? '')) ?: null,
            'zahlweise'           => in_array($d['zahlweise'] ?? '', self::ZAHLWEISEN, true) ? $d['zahlweise'] : $mitglied['zahlweise'],
            'notizen'             => trim((string) ($d['notizen'] ?? '')) ?: null,
            'kein_email_kontakt'  => $keinEmail,
        ];

        $this->service->stammdatenAendern((int) $mitglied['id'], $felder, $this->benutzerId($request));
        $this->flash->erfolg('Die Stammdaten wurden gespeichert.');

        return $this->zu($response, (int) $mitglied['id']);
    }

    public function beitragAendern(Request $request, Response $response, array $args): Response
    {
        $mitglied = $this->laden((int) $args['id'], $request);
        $body = (array) $request->getParsedBody();
        $roh = trim((string) ($body['beitrag'] ?? ''));
        $wert = (float) str_replace(',', '.', $roh);

        $min = (float) $this->einstellungen->hole('beitrag_min', '12.00');
        $max = (float) $this->einstellungen->hole('beitrag_max', '500.00');
        if ($wert < $min || $wert > $max) {
            return $this->zurueckMitFehler(
                $response,
                $args['id'],
                sprintf('Der Jahresbeitrag muss zwischen %s und %s Euro liegen.', number_format($min, 2, ',', '.'), number_format($max, 2, ',', '.')),
            );
        }

        $this->service->beitragAendern((int) $mitglied['id'], (string) $wert, $this->benutzerId($request));
        $this->flash->erfolg('Der Jahresbeitrag wurde geändert (wirksam ab der nächsten Sollstellung).');

        return $this->zu($response, (int) $mitglied['id']);
    }

    public function aktivieren(Request $request, Response $response, array $args): Response
    {
        return $this->statusAktion($request, $response, (int) $args['id'], function (int $id, int $benutzer): string {
            $nummer = $this->service->aktivieren($id, $benutzer);

            return sprintf('Mitglied aktiviert. Vergebene Mitgliedsnummer: %04d.', $nummer);
        });
    }

    public function ablehnen(Request $request, Response $response, array $args): Response
    {
        return $this->statusAktion($request, $response, (int) $args['id'], function (int $id, int $benutzer): string {
            $this->service->ablehnen($id, $benutzer);

            return 'Der Antrag wurde abgelehnt.';
        });
    }

    public function kuendigen(Request $request, Response $response, array $args): Response
    {
        $d = (array) $request->getParsedBody();
        $kuendigungAm = trim((string) ($d['kuendigung_am'] ?? '')) ?: null;
        $wirksamZum = trim((string) ($d['wirksam_zum'] ?? '')) ?: null;

        return $this->statusAktion($request, $response, (int) $args['id'], function (int $id, int $benutzer) use ($kuendigungAm, $wirksamZum): string {
            $this->service->kuendigen($id, $benutzer, $kuendigungAm, $wirksamZum);

            return 'Die Kündigung wurde erfasst; eine Bestätigung liegt in der Mail-Queue.';
        });
    }

    public function kuendigungWiderrufen(Request $request, Response $response, array $args): Response
    {
        return $this->statusAktion($request, $response, (int) $args['id'], function (int $id, int $benutzer): string {
            $this->service->kuendigungWiderrufen($id, $benutzer);

            return 'Die Kündigung wurde widerrufen; das Mitglied ist wieder aktiv.';
        });
    }

    public function austritt(Request $request, Response $response, array $args): Response
    {
        return $this->statusAktion($request, $response, (int) $args['id'], function (int $id, int $benutzer): string {
            $this->service->austrittVollziehen($id, $benutzer);

            return 'Der Austritt wurde vollzogen.';
        });
    }

    public function revert(Request $request, Response $response, array $args): Response
    {
        $mitglied = $this->laden((int) $args['id'], $request);
        $d = (array) $request->getParsedBody();
        $versionId = isset($d['version_id']) && ctype_digit((string) $d['version_id']) ? (int) $d['version_id'] : 0;
        $feld = trim((string) ($d['feld'] ?? ''));

        if ($versionId === 0) {
            return $this->zurueckMitFehler($response, $args['id'], 'Ungültige Version.', '?tab=historie');
        }

        try {
            $this->service->revert((int) $mitglied['id'], $versionId, $this->benutzerId($request), $feld !== '' ? [$feld] : null);
            $this->flash->erfolg($feld !== '' ? "Feld »{$feld}« wurde auf den gewählten Stand zurückgesetzt." : 'Der Datensatz wurde auf den gewählten Stand zurückgesetzt.');
        } catch (\Throwable $e) {
            $this->flash->fehler('Zurücksetzen nicht möglich: ' . $e->getMessage());
        }

        return $response->withHeader('Location', '/mitglieder/' . $mitglied['id'] . '?tab=historie')->withStatus(302);
    }

    // ---- intern ----------------------------------------------------------

    private function statusAktion(Request $request, Response $response, int $id, callable $aktion): Response
    {
        $mitglied = $this->laden($id, $request);
        try {
            $meldung = $aktion((int) $mitglied['id'], $this->benutzerId($request));
            $this->flash->erfolg($meldung);
        } catch (\DomainException $e) {
            $this->flash->fehler($e->getMessage());
        } catch (\Throwable $e) {
            $this->flash->fehler('Aktion fehlgeschlagen: ' . $e->getMessage());
        }

        return $this->zu($response, (int) $mitglied['id']);
    }

    /**
     * @return array<string,mixed>
     */
    private function laden(int $id, Request $request): array
    {
        $mitglied = $this->mitglieder->findePerId($id);
        if ($mitglied === null) {
            throw new HttpNotFoundException($request, 'Mitglied nicht gefunden.');
        }

        return $mitglied;
    }

    /**
     * Baut die Änderungshistorie: je Version wer/wann und Feld alt → neu.
     *
     * @param array<string,mixed> $aktuell
     * @return array<int,array<string,mixed>>
     */
    private function historie(int $id, array $aktuell): array
    {
        $versionen = $this->db->alleZeilen(
            'SELECT v.*, b.name AS benutzer_name FROM mitglied_version v
               LEFT JOIN benutzer b ON b.id = v.geaendert_von
              WHERE v.mitglied_id = :id ORDER BY v.version_nr ASC',
            ['id' => $id],
        );

        $eintraege = [];
        $anzahl = count($versionen);
        foreach ($versionen as $k => $version) {
            $vorher = json_decode((string) $version['snapshot'], true) ?: [];
            $nachher = $k + 1 < $anzahl
                ? (json_decode((string) $versionen[$k + 1]['snapshot'], true) ?: [])
                : $aktuell;
            $felder = json_decode((string) $version['geaenderte_felder'], true) ?: [];

            $diffs = [];
            foreach ($felder as $feld) {
                $diffs[] = [
                    'feld' => $feld,
                    'alt'  => $this->anzeige($vorher[$feld] ?? null),
                    'neu'  => $this->anzeige($nachher[$feld] ?? null),
                ];
            }

            $eintraege[] = [
                'version_id' => $version['id'],
                'version_nr' => (int) $version['version_nr'],
                'wann'       => $version['geaendert_am'],
                'wer'        => $version['benutzer_name'] ?? 'System',
                'ist_revert' => $version['ist_revert_von'] !== null,
                'diffs'      => $diffs,
            ];
        }

        return array_reverse($eintraege);
    }

    private function anzeige(mixed $wert): string
    {
        if ($wert === null || $wert === '') {
            return '—';
        }

        return (string) $wert;
    }

    /**
     * @return array<string,array{label:string,badge:string}>
     */
    private function statusMeta(): array
    {
        $meta = [];
        foreach (Mitgliedsstatus::alle() as $status => $label) {
            $meta[$status] = ['label' => $label, 'badge' => Mitgliedsstatus::badge($status)];
        }

        return $meta;
    }

    private function benutzerId(Request $request): int
    {
        $benutzer = $request->getAttribute('benutzer');

        return is_array($benutzer) ? (int) $benutzer['id'] : 0;
    }

    private function zu(Response $response, int $id): Response
    {
        return $response->withHeader('Location', '/mitglieder/' . $id)->withStatus(302);
    }

    private function zurueckMitFehler(Response $response, int|string $id, string $text, string $suffix = ''): Response
    {
        $this->flash->fehler($text);

        return $response->withHeader('Location', '/mitglieder/' . $id . $suffix)->withStatus(302);
    }
}
