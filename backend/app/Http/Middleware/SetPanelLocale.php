<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders the Admin and Trainer panels in the language the member of staff
 * chose in the app.
 *
 * Both panels were English-only: every label, every confirmation, and all of
 * Filament's own chrome. The people running them are the same Turkish- and
 * Russian-speaking staff the app is translated for, and a delete
 * confirmation nobody can read is the one place a language gap does real
 * damage.
 *
 * Filament ships its own translations for tr and ru, so setting the locale
 * here covers its buttons, filters, pagination and validation messages too.
 * Only this project's own labels need `lang/{locale}/panel.php`.
 *
 * `users.preferred_language` is the same column the app writes from its
 * language picker, so someone who switches language on their phone finds
 * the panel has followed.
 */
class SetPanelLocale
{
    /** The languages the product supports. */
    private const SUPPORTED = ['tr', 'en', 'ru'];

    private const FALLBACK = 'tr';

    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale($this->localeFor($request->user()));

        return $next($request);
    }

    private function localeFor(?object $user): string
    {
        if (! $user instanceof User) {
            return self::FALLBACK;
        }

        // Stored uppercase ('TR') by the app's settings endpoint; Laravel
        // locales are lowercase.
        $locale = strtolower((string) $user->preferred_language);

        return in_array($locale, self::SUPPORTED, true) ? $locale : self::FALLBACK;
    }
}
