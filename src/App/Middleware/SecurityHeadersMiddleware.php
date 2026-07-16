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
        $csp = "default-src 'self'; "
            . "img-src 'self' data:; "
            . "style-src 'self'; "
            . "script-src 'self'; "
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
