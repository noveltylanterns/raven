<?php

/**
 * RAVEN CMS
 * ~/private/src/Core/Support/CountryOptions.php
 * Shared country option catalog for signup forms and panel reporting.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Keep country labels centralized so public form options and panel labels always match.

declare(strict_types=1);

namespace Raven\Core\Support;

/**
 * Provides normalized country option labels for signup-related features.
 */
final class CountryOptions
{
    /**
     * CLDR region codes intentionally excluded because they are pseudo/global aggregates, not countries.
     *
     * @var array<int, string>
     */
    private const EXCLUDED_REGION_CODES = [
        'AC',
        'CP',
        'CQ',
        'DG',
        'EA',
        'EU',
        'EZ',
        'IC',
        'QO',
        'TA',
        'UN',
        'ZZ',
    ];

    /**
     * Returns country options keyed by lowercase alpha-2 code.
     *
     * @return array<string, string>
     */
    public static function list(bool $includeOther = true): array
    {
        $options = self::fromIntlResourceBundle();
        if ($options === []) {
            // Fallback keeps legacy behavior when ext-intl is unavailable.
            $options = self::fallbackOptions();
        }

        if ($includeOther) {
            $options['other'] = 'Other';
        }

        return $options;
    }

    /**
     * Loads country names from ICU region data when ext-intl is available.
     *
     * @return array<string, string>
     */
    private static function fromIntlResourceBundle(): array
    {
        if (!class_exists('ResourceBundle')) {
            return [];
        }

        /** @var mixed $bundle */
        $bundle = \ResourceBundle::create('en', 'ICUDATA-region');
        if (!$bundle instanceof \ResourceBundle) {
            return [];
        }

        /** @var mixed $countries */
        $countries = $bundle->get('Countries');
        if (!$countries instanceof \ResourceBundle) {
            return [];
        }

        $options = [];
        foreach ($countries as $rawCode => $rawName) {
            $code = strtoupper(trim((string) $rawCode));
            if (!preg_match('/^[A-Z]{2}$/', $code)) {
                continue;
            }

            if (in_array($code, self::EXCLUDED_REGION_CODES, true)) {
                continue;
            }

            $name = trim((string) $rawName);
            if ($name === '') {
                continue;
            }

            $options[strtolower($code)] = $name;
        }

        if ($options === []) {
            return [];
        }

        asort($options, SORT_NATURAL | SORT_FLAG_CASE);
        return $options;
    }

    /**
     * Legacy subset fallback when ICU country data is unavailable at runtime.
     *
     * @return array<string, string>
     */
    private static function fallbackOptions(): array
    {
        return [
            'us' => 'United States',
            'ca' => 'Canada',
            'gb' => 'United Kingdom',
            'au' => 'Australia',
            'de' => 'Germany',
            'fr' => 'France',
            'in' => 'India',
            'jp' => 'Japan',
            'mx' => 'Mexico',
            'br' => 'Brazil',
            'za' => 'South Africa',
        ];
    }
}
