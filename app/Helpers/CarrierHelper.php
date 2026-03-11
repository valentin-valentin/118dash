<?php

namespace App\Helpers;

class CarrierHelper
{
    /**
     * Mapping des codes opérateurs vers les noms commerciaux
     */
    private static array $carrierMapping = [
        'BOUY' => 'Bouygues',
        'FREE' => 'Free',
        'FRMO' => 'Free Mobile',
        'FRTE' => 'Orange',
        'SFR0' => 'SFR',
    ];

    /**
     * Obtenir le nom commercial d'un opérateur
     */
    public static function getDisplayName(string $code): string
    {
        return self::$carrierMapping[$code] ?? $code;
    }

    /**
     * Vérifier si un opérateur est défini (prioritaire)
     */
    public static function isDefined(string $code): bool
    {
        return isset(self::$carrierMapping[$code]);
    }

    /**
     * Obtenir la liste des codes définis
     */
    public static function getDefinedCodes(): array
    {
        return array_keys(self::$carrierMapping);
    }

    /**
     * Obtenir tous les mappings
     */
    public static function getMapping(): array
    {
        return self::$carrierMapping;
    }
}
