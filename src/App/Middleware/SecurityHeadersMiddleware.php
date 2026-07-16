<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * Security-Header (CLAUDE.md Regel 6 / F9). CSP ohne externe Quellen
 * (alle Assets lokal), Clickjacking-/MIME-Schutz, restriktive Referrer-Policy.
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function process(Request $request, Handler $handler): Response
    {
        $response = $handler->handle($request);

        // 'self' überall — keine CDNs im internen Bereich. data: nur für Bilder
        // (TOTP-QR wird als data:-URI eingebunden).
        // Einzige Ausnahme (KONZEPT §F2): das TrustCaptcha-Skript auf der
        // öffentlichen Antragsseite (/antrag …).
        $istAntrag = str_starts_with($request->getUri()->getPath(), '/antrag');
        $tc = 'https://cdn.trustcaptcha.com';
        $tcApi = 'https://api.trustcaptcha.com';

        $scriptSrc = $istAntrag ? "script-src 'self' {$tc}; " : "script-src 'self'; ";
        $connectSrc = $istAntrag ? "connect-src 'self' {$tcApi}; " : "connect-src 'self'; ";

        $csp = "default-src 'self'; "
            . "img-src 'self' data:; "
            . "style-src 'self'; "
            . $scriptSrc
            . $connectSrc
            . "font-src 'self'; "
            . "form-action 'self'; "
            . "base-uri 'self'; "
            . "frame-ancestors 'none'";

        return $response
            ->withHeader('Content-Security-Policy', $csp)
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'same-origin')
            ->withHeader('X-Permitted-Cross-Domain-Policies', 'none');
    }
}
