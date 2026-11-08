<?php

namespace App\Support;

/**
 * How brand names are compared (softphone search, brand requests, 118dash's brands page): the SAME rules everywhere.
 * The softphone has the same function in JavaScript (Phone.vue, brandKey); 118dash copies this class as is.
 *
 *   key("La Poste")              = "laposte"     lower case, no accents, "&" → "et", web endings (.fr .com…)
 *   key("Société Générale.fr")   = "societegenerale"   removed, then letters and digits only
 *   words("Service client SFR")  = ["sfr"]       the significant words (common words left out)
 */
final class BrandName
{
    // Words that do not tell brands apart ("mobile", "assurance"… do: Free / Free Mobile are two brands)
    public const STOP_WORDS = ['la', 'le', 'les', 'l', 'de', 'du', 'des', 'd', 'et', 'service', 'services', 'client', 'clients',
        'france', 'fr', 'sa', 'sas', 'groupe'];

    /** Lower case, accents removed, "&" → " et ", web endings removed; words separated by single spaces. */
    public static function normalize(?string $name): string
    {
        $s = mb_strtolower(trim((string) $name), 'UTF-8');
        $s = strtr($s, ['œ' => 'oe', 'æ' => 'ae', 'ß' => 'ss']);
        $s = \Normalizer::normalize($s, \Normalizer::FORM_D) ?: $s;
        $s = preg_replace('/\p{Mn}+/u', '', $s);                     // accents
        $s = str_replace('&', ' et ', $s);
        $s = preg_replace('/\.(fr|com|be|de|net|org|eu|io)\b/', ' ', $s); // web endings
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);

        return trim(preg_replace('/\s+/', ' ', $s));
    }

    /** Key to compare two names: letters and digits only. */
    public static function key(?string $name): string
    {
        return str_replace(' ', '', self::normalize($name));
    }

    /** Significant words (common words left out). */
    public static function words(?string $name): array
    {
        return array_values(array_diff(array_filter(explode(' ', self::normalize($name))), self::STOP_WORDS));
    }

    /** A brand's aliases (brands.aliases: one per line), trimmed, empty ones left out. */
    public static function aliases(?string $aliases): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $aliases))));
    }
}
