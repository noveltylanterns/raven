<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/FormCountries.php
 * Shared country option catalog for forms and panel reporting.
 * Docs: https://lanterns.io/raven
 */

// Inline note: Keep country labels centralized so public form options and panel labels always match.

declare(strict_types=1);

namespace Raven\Lib\View;

use RuntimeException;

/**
 * Provides normalized country option labels for form-related features.
 */
final class FormCountries
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
        // Country list depends on ext-intl region data; fail loudly when unavailable.
        if ($options === []) {
            throw new RuntimeException('Country options require ext-intl with ICU region data.');
        }

        // Optional synthetic bucket for users whose location is not listed.
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
        // ext-intl classes gate ICU-backed country list loading.
        if (!class_exists('ResourceBundle')) {
            return [];
        }

        /** @var mixed $bundle */
        $bundle = \ResourceBundle::create('en', 'ICUDATA-region');
        // Bundle creation can fail when ICU region data is missing.
        if (!$bundle instanceof \ResourceBundle) {
            return [];
        }

        /** @var mixed $countries */
        $countries = $bundle->get('Countries');
        // Countries table must resolve to a traversable ResourceBundle.
        if (!$countries instanceof \ResourceBundle) {
            return [];
        }

        $options = [];
        // Normalize each ICU region row into Raven country option shape.
        foreach ($countries as $rawCode => $rawName) {
            $code = strtoupper(trim((string) $rawCode));
            // Keep only two-letter alpha country codes.
            if (!preg_match('/^[A-Z]{2}$/', $code)) {
                continue;
            }

            // Skip pseudo/global aggregate codes.
            if (in_array($code, self::EXCLUDED_REGION_CODES, true)) {
                continue;
            }

            $name = trim((string) $rawName);
            // Ignore blank display names.
            if ($name === '') {
                continue;
            }

            $options[strtolower($code)] = $name;
        }

        // Empty option sets signal an unusable ICU source table.
        if ($options === []) {
            return [];
        }

        asort($options, SORT_NATURAL | SORT_FLAG_CASE);
        return $options;
    }

}
