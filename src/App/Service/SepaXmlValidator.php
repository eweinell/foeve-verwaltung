<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Validiert erzeugtes pain.008-XML gegen das lokal beigelegte XSD
 * (resources/schema/pain.008.001.02.xsd). Kein externer Zugriff.
 */
final class SepaXmlValidator
{
    private readonly string $xsdPfad;

    public function __construct(?string $xsdPfad = null)
    {
        $this->xsdPfad = $xsdPfad ?? dirname(__DIR__, 3) . '/resources/schema/pain.008.001.02.xsd';
    }

    /**
     * @return array<int,string> Fehlermeldungen (leer = valide)
     */
    public function fehler(string $xml): array
    {
        if (!is_file($this->xsdPfad)) {
            throw new \RuntimeException("XSD nicht gefunden: {$this->xsdPfad}");
        }

        $vorher = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $ok = $dom->schemaValidate($this->xsdPfad);

        $fehler = [];
        if (!$ok) {
            foreach (libxml_get_errors() as $e) {
                $fehler[] = trim($e->message);
            }
        }
        libxml_clear_errors();
        libxml_use_internal_errors($vorher);

        return $fehler;
    }

    public function istValide(string $xml): bool
    {
        return $this->fehler($xml) === [];
    }
}
