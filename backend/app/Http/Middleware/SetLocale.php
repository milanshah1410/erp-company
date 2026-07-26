<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the application locale for the request from the X-Locale header
 * (sent by the frontend's language switcher) or a ?locale= query param.
 * Only whitelisted locales are honoured; anything else keeps the default.
 */
class SetLocale
{
    public const SUPPORTED = ['en', 'hi', 'gu'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->header('X-Locale', $request->query('locale'));

        if ($locale && in_array($locale, self::SUPPORTED, true)) {
            App::setLocale($locale);
        }

        return $next($request);
    }
}
