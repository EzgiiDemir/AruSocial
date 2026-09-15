<?php

namespace App\Services\Legal;

use Illuminate\Support\Facades\File;

/**
 * The legal texts, and which version of them is currently in force.
 *
 * The version is derived from the documents themselves — a short hash over
 * all three translations — rather than a constant someone has to remember
 * to bump. "Ask again when the policy is updated" is a legal obligation,
 * and an obligation discharged by remembering to edit a second place is one
 * that eventually is not discharged.
 *
 * The cost of that choice, stated plainly: fixing a typo changes the hash
 * and re-prompts everyone. That is the safe direction to fail in, and if it
 * becomes a nuisance the fix is to batch wording changes, not to make the
 * version manual.
 *
 * All three translations feed the hash. A change to the Russian text alone
 * still produces a new version, because a Russian-speaking student agreed
 * to the Russian wording.
 */
class PolicyDocuments
{
    public const PRIVACY = 'privacy';

    public const GUIDELINES = 'community-guidelines';

    /**
     * The documents the consent checkbox covers.
     *
     * Both, because the checkbox says "the Privacy Policy and Community
     * Guidelines" — so a change to either is a change to what was agreed
     * to, and has to put a new version in force.
     */
    public const CONSENTABLE = [self::PRIVACY, self::GUIDELINES];

    public const LOCALES = ['tr', 'en', 'ru'];

    private static ?string $cachedVersion = null;

    /**
     * The version now in force, e.g. `policy-8f2a1c7d`.
     *
     * Hashed over every consentable document in every language, so an edit
     * to the Russian Community Guidelines alone still produces a new
     * version — a Russian-speaking student agreed to that wording, not to
     * whatever replaced it.
     *
     * Cached per process: this is read on every consent check, and the
     * files cannot change inside one request.
     */
    public static function currentVersion(): string
    {
        if (self::$cachedVersion !== null) {
            return self::$cachedVersion;
        }

        $material = '';
        foreach (self::CONSENTABLE as $document) {
            foreach (self::LOCALES as $locale) {
                $path = self::path($document, $locale);
                $material .= $document.':'.$locale.':'
                    .(File::exists($path) ? File::get($path) : '')."\n";
            }
        }

        return self::$cachedVersion = 'policy-'.substr(sha1($material), 0, 12);
    }

    /**
     * Forgets the cached version.
     *
     * For tests that edit a document mid-run; nothing in the application
     * needs it, because the files are static within a request.
     */
    public static function forgetCachedVersion(): void
    {
        self::$cachedVersion = null;
    }

    public static function isKnownLocale(string $locale): bool
    {
        return in_array($locale, self::LOCALES, true);
    }

    /**
     * The translated file, falling back to the Turkish original.
     *
     * Turkish is the governing text — the one legal review approves — so a
     * missing translation serves it rather than an error.
     */
    public static function path(string $document, string $locale): string
    {
        if ($locale !== 'tr') {
            $translated = base_path("../docs/legal/{$document}.{$locale}.md");
            if (File::exists($translated)) {
                return $translated;
            }
        }

        return base_path("../docs/legal/{$document}.md");
    }
}
