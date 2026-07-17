<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Systemvorlagen (F6): feste Schlüssel mit deutschen Default-Texten (Sie-Form).
 * Die Defaults liegen im Code; in der DB (email_vorlage) gespeicherte Vorlagen
 * überschreiben sie. Systemvorlagen sind nicht löschbar — „Löschen" setzt nur
 * den Override zurück auf den Default.
 */
final class SystemVorlagen
{
    /** Alle erlaubten Platzhalter (Whitelist für die Validierung). */
    public const PLATZHALTER = [
        'briefanrede', 'adresszeile', 'vorname', 'nachname', 'mitgliedsnummer',
        'beitrag', 'jahr', 'iban_maskiert', 'mandatsreferenz', 'glaeubiger_id',
        'faelligkeitsdatum', 'kontoinhaber', 'verein_name', 'bestaetigungslink', 'code',
    ];

    /** Für Versandaktionen sinnvoll nutzbare (mitgliedsbezogene) Platzhalter. */
    public const PLATZHALTER_ALLGEMEIN = [
        'briefanrede', 'adresszeile', 'vorname', 'nachname', 'mitgliedsnummer', 'beitrag', 'jahr',
    ];

    public const SCHLUESSEL = [
        'begruessung', 'kuendigungsbestaetigung', 'prenotification', 'doi_bestaetigung', 'login_code',
    ];

    /**
     * @return array<string,array{betreff:string,body_text:string}>
     */
    public static function defaults(): array
    {
        return [
            'begruessung' => [
                'betreff'   => 'Willkommen im Förderverein Gymnasium Herzogenrath',
                'body_text' =>
                    "{{briefanrede}},\n\n"
                    . "herzlich willkommen im Förderverein Gymnasium Herzogenrath! Ihre Mitgliedschaft "
                    . "ist nun aktiv, Ihre Mitgliedsnummer lautet {{mitgliedsnummer}}.\n\n"
                    . "Mit freundlichen Grüßen\nDer Vorstand",
            ],
            'kuendigungsbestaetigung' => [
                'betreff'   => 'Kündigungsbestätigung',
                'body_text' =>
                    "{{briefanrede}},\n\n"
                    . "wir bestätigen den Eingang Ihrer Kündigung. Ihre Mitgliedschaft endet zum "
                    . "{{faelligkeitsdatum}}.\n\n"
                    . "Mit freundlichen Grüßen\nDer Vorstand",
            ],
            'prenotification' => [
                'betreff'   => 'Ankündigung SEPA-Lastschrift',
                'body_text' =>
                    "{{briefanrede}},\n\n"
                    . "wir kündigen den Einzug Ihres Mitgliedsbeitrags per SEPA-Lastschrift an:\n\n"
                    . "Betrag: {{beitrag}} EUR\n"
                    . "Fälligkeit: {{faelligkeitsdatum}}\n"
                    . "Mandatsreferenz: {{mandatsreferenz}}\n"
                    . "Gläubiger-Identifikationsnummer: {{glaeubiger_id}}\n"
                    . "IBAN: {{iban_maskiert}}\n\n"
                    . "Bitte sorgen Sie für ausreichende Deckung.\n\n"
                    . "Mit freundlichen Grüßen\nFörderverein Gymnasium Herzogenrath",
            ],
            'doi_bestaetigung' => [
                'betreff'   => 'Bitte bestätigen Sie Ihren Aufnahmeantrag',
                'body_text' =>
                    "Guten Tag,\n\n"
                    . "vielen Dank für Ihren Aufnahmeantrag beim Förderverein Gymnasium Herzogenrath.\n\n"
                    . "Bitte bestätigen Sie Ihre E-Mail-Adresse und Ihren Antrag, indem Sie die folgende "
                    . "Seite öffnen und dort auf »Jetzt bestätigen« klicken:\n\n"
                    . "{{bestaetigungslink}}\n\n"
                    . "SEPA-Lastschriftmandat\n"
                    . "----------------------\n"
                    . "Mit der Bestätigung ermächtigen Sie den {{verein_name}} "
                    . "(Gläubiger-Identifikationsnummer {{glaeubiger_id}}), den Mitgliedsbeitrag "
                    . "({{beitrag}} EUR) per SEPA-Lastschrift von Ihrem Konto einzuziehen "
                    . "(IBAN {{iban_maskiert}}, Kontoinhaber {{kontoinhaber}}). Zugleich weisen Sie Ihr "
                    . "Kreditinstitut an, die Lastschriften einzulösen. Sie können innerhalb von acht Wochen "
                    . "ab Belastung die Erstattung verlangen; es gelten die mit Ihrer Bank vereinbarten Bedingungen.\n\n"
                    . "Wenn Sie diesen Antrag nicht gestellt haben, ignorieren Sie diese E-Mail einfach.\n\n"
                    . "Mit freundlichen Grüßen\nFörderverein Gymnasium Herzogenrath",
            ],
            'login_code' => [
                'betreff'   => 'Ihr Anmeldecode',
                'body_text' =>
                    "Guten Tag,\n\n"
                    . "Ihr Anmeldecode für die Vereinsverwaltung lautet: {{code}}\n\n"
                    . "Der Code ist 10 Minuten gültig. Wenn Sie sich nicht anmelden wollten, "
                    . "ignorieren Sie diese E-Mail.",
            ],
        ];
    }

    public static function istSystem(string $schluessel): bool
    {
        return in_array($schluessel, self::SCHLUESSEL, true);
    }
}
