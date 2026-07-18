<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\SystemVorlagen;
use App\Repository\VorlageRepository;

/**
 * E-Mail-Vorlagen (F6): einfache `{{name}}`-Platzhalter-Engine (kein Twig für
 * Nutzereingaben). Systemvorlagen kommen als Default aus dem Code und sind über
 * die DB überschreibbar. Unbekannte Platzhalter werden beim Speichern/Testen
 * abgewiesen — nicht erst beim Massenversand.
 */
final class VorlagenService
{
    public function __construct(
        private readonly VorlageRepository $repo,
        private readonly AnredeDienst $anrede,
        private readonly Einstellungen $einstellungen,
    ) {
    }

    /**
     * Ersetzt {{platzhalter}} durch Werte; unbekannte Kontext-Werte werden leer.
     *
     * @param array<string,string> $kontext
     */
    public function render(string $text, array $kontext): string
    {
        return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', static function (array $m) use ($kontext): string {
            return (string) ($kontext[$m[1]] ?? '');
        }, $text) ?? $text;
    }

    /**
     * Alle in den Texten vorkommenden Platzhalternamen (ohne Dubletten).
     *
     * @return array<int,string>
     */
    public function platzhalterIn(string ...$texte): array
    {
        $namen = [];
        foreach ($texte as $text) {
            if (preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', $text, $treffer)) {
                foreach ($treffer[1] as $name) {
                    $namen[$name] = true;
                }
            }
        }

        return array_keys($namen);
    }

    /**
     * Prüft, dass nur bekannte Platzhalter vorkommen.
     *
     * @throws \DomainException mit Auflistung der unbekannten Platzhalter
     */
    public function validiere(string ...$texte): void
    {
        $unbekannt = array_values(array_filter(
            $this->platzhalterIn(...$texte),
            static fn (string $name): bool => !in_array($name, SystemVorlagen::PLATZHALTER, true),
        ));
        if ($unbekannt !== []) {
            throw new \DomainException('Unbekannte Platzhalter: ' . self::liste($unbekannt));
        }
    }

    /**
     * @param array<int,string> $namen
     */
    public static function liste(array $namen): string
    {
        return implode(', ', array_map(static fn (string $n): string => '{{' . $n . '}}', $namen));
    }

    /**
     * Effektive Vorlage (DB-Override oder Code-Default).
     *
     * @return array{schluessel:string,betreff:string,body_text:string,body_html:?string,system:bool,ist_override:bool}
     */
    public function hole(string $schluessel): array
    {
        $db = $this->repo->findePerSchluessel($schluessel);
        if ($db !== null) {
            return [
                'schluessel'   => $schluessel,
                'betreff'      => (string) $db['betreff'],
                'body_text'    => (string) $db['body_text'],
                'body_html'    => $db['body_html'] !== null ? (string) $db['body_html'] : null,
                'system'       => (int) $db['system'] === 1 || SystemVorlagen::istSystem($schluessel),
                'ist_override' => true,
            ];
        }

        $default = SystemVorlagen::defaults()[$schluessel] ?? null;
        if ($default === null) {
            throw new \RuntimeException("Vorlage »{$schluessel}« nicht gefunden.");
        }

        return [
            'schluessel'   => $schluessel,
            'betreff'      => $default['betreff'],
            'body_text'    => $default['body_text'],
            'body_html'    => null,
            'system'       => true,
            'ist_override' => false,
        ];
    }

    /**
     * Rendert eine Vorlage mit Kontext.
     *
     * @param array<string,string> $kontext
     * @return array{betreff:string,text:string,html:?string}
     */
    public function rendere(string $schluessel, array $kontext): array
    {
        $v = $this->hole($schluessel);

        return [
            'betreff' => $this->render($v['betreff'], $kontext),
            'text'    => $this->render($v['body_text'], $kontext),
            'html'    => $v['body_html'] !== null ? $this->render($v['body_html'], $kontext) : null,
        ];
    }

    public function speichern(string $schluessel, string $betreff, string $bodyText, ?string $bodyHtml): void
    {
        $schluessel = strtolower(trim($schluessel));
        if (preg_match('/^[a-z][a-z0-9_]*$/', $schluessel) !== 1) {
            throw new \DomainException('Ungültiger Vorlagen-Schlüssel (nur Kleinbuchstaben, Ziffern, Unterstrich).');
        }
        if (trim($betreff) === '' || trim($bodyText) === '') {
            throw new \DomainException('Betreff und Text dürfen nicht leer sein.');
        }
        $this->validiere($betreff, $bodyText, $bodyHtml ?? '');
        $this->repo->speichern($schluessel, $betreff, $bodyText, ($bodyHtml !== null && trim($bodyHtml) !== '') ? $bodyHtml : null, SystemVorlagen::istSystem($schluessel));
    }

    /**
     * Setzt eine Systemvorlage auf den Code-Default zurück bzw. löscht eine eigene Vorlage.
     */
    public function loeschen(string $schluessel): void
    {
        $this->repo->loeschen($schluessel);
    }

    /**
     * Alle Vorlagen (Systemvorlagen inkl. Override-Status + eigene).
     *
     * @return array<int,array<string,mixed>>
     */
    public function alle(): array
    {
        $overrides = [];
        foreach ($this->repo->alle() as $row) {
            $overrides[(string) $row['schluessel']] = $row;
        }

        $liste = [];
        foreach (SystemVorlagen::SCHLUESSEL as $schluessel) {
            $v = $this->hole($schluessel);
            $liste[] = ['schluessel' => $schluessel, 'betreff' => $v['betreff'], 'system' => true, 'ist_override' => $v['ist_override']];
            unset($overrides[$schluessel]);
        }
        foreach ($overrides as $schluessel => $row) {
            $liste[] = ['schluessel' => $schluessel, 'betreff' => (string) $row['betreff'], 'system' => false, 'ist_override' => true];
        }

        return $liste;
    }

    /**
     * Standard-Kontext aus einem Mitglied (+ zusätzliche Werte wie faelligkeitsdatum).
     *
     * @param array<string,mixed> $mitglied
     * @param array<string,string> $extra
     * @return array<string,string>
     */
    public function kontext(array $mitglied, array $extra = []): array
    {
        $basis = [
            'briefanrede'    => $this->anrede->briefanrede($mitglied),
            'adresszeile'    => $this->anrede->adresszeile($mitglied),
            'vorname'        => (string) ($mitglied['vorname'] ?? ''),
            'nachname'       => (string) ($mitglied['nachname'] ?? ''),
            'mitgliedsnummer' => !empty($mitglied['mitgliedsnummer']) ? sprintf('%04d', (int) $mitglied['mitgliedsnummer']) : '',
            'beitrag'        => isset($mitglied['jahresbeitrag']) ? number_format((float) $mitglied['jahresbeitrag'], 2, ',', '.') : '',
            'jahr'           => (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin')))->format('Y'),
            'verein_name'    => $this->einstellungen->hole('verein_name', 'Förderverein Gymnasium Herzogenrath'),
            'glaeubiger_id'  => $this->einstellungen->hole('glaeubiger_id', ''),
        ];

        return array_merge($basis, $extra);
    }
}
