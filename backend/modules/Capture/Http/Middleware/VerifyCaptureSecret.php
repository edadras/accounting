<?php

declare(strict_types=1);

namespace Modules\Capture\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Capture\Exceptions\CaptureException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The only credential the inbound-mail webhook has.
 *
 * Every other capture route sits behind a session and a workspace membership
 * check. This one cannot: a mail provider posting a forwarded receipt has no
 * user to be. So the shared secret is the entire boundary between "a receipt
 * arrived in my books" and "anyone on the internet can write into a stranger's
 * ledger", and it is checked before the request reaches any code that reads the
 * body.
 *
 * With no secret configured the endpoint refuses everything. An unconfigured
 * deployment failing open would be the same bug with extra steps.
 */
final class VerifyCaptureSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('capture.webhook.secret', '');

        if ($secret === '') {
            throw CaptureException::webhookNotConfigured();
        }

        $signature = (string) $request->header((string) config('capture.webhook.signature_header'), '');

        if ($signature !== '') {
            $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

            // hash_equals, not ===: a comparison that returns early leaks how
            // much of a guess was right, one byte at a time.
            if (! hash_equals($expected, $signature)) {
                throw CaptureException::webhookUnauthorized();
            }

            return $next($request);
        }

        $presented = (string) $request->header((string) config('capture.webhook.secret_header'), '');

        if ($presented === '' || ! hash_equals($secret, $presented)) {
            throw CaptureException::webhookUnauthorized();
        }

        return $next($request);
    }
}
