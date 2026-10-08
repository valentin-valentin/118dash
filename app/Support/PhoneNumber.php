<?php

namespace App\Support;

class PhoneNumber
{
    /**
     * Normalise un numéro français au format E.164 (+33XXXXXXXXX).
     * Tolère espaces, points, tirets, parenthèses et les préfixes
     * +33, 0033, 33, +33(0) ou 0. Retourne null si le numéro est invalide.
     */
    public static function normalizeFr(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $value = trim($input);
        // "+33 (0)6..." → "+336..."
        $value = preg_replace('/^(\+|00)?33\s*\(0\)/', '+33', $value);
        $hasPlus = str_starts_with($value, '+');
        $digits = preg_replace('/\D/', '', $value);

        if ($hasPlus) {
            $national = str_starts_with($digits, '33') ? substr($digits, 2) : null;
        } elseif (str_starts_with($digits, '0033')) {
            $national = substr($digits, 4);
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '33')) {
            $national = substr($digits, 2);
        } elseif (strlen($digits) === 10 && str_starts_with($digits, '0')) {
            $national = substr($digits, 1);
        } elseif (strlen($digits) === 9) {
            $national = $digits;
        } else {
            $national = null;
        }

        // "+330612345678" → on retire le 0 national en trop
        if ($national !== null && strlen($national) === 10 && str_starts_with($national, '0')) {
            $national = substr($national, 1);
        }

        if ($national === null || !preg_match('/^[1-9]\d{8}$/', $national)) {
            return null;
        }

        return '+33' . $national;
    }
}
