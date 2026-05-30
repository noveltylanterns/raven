<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/Panel/ListFilter.php
 * Generic SQL filter-clause helper for panel list queries.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\View\Panel;

/**
 * Shared SQL-clause helper for panel list queries that filter by normalized ids.
 */
final class ListFilter
{
    /**
     * Appends one many-to-many EXISTS clause when the filter id is present.
     *
     * `EXISTS` keeps the caller's outer rowset stable, which avoids duplicate rows
     * and keeps paired count/list queries aligned without adding JOIN-specific dedupe.
     *
     * @param array<int, string> $where Mutable WHERE-clause fragment list.
     * @param array<string, int|string> $params Mutable prepared-statement parameter map.
     * @param string $table Resolved junction-table name.
     * @param string $alias SQL alias used inside the EXISTS subquery.
     * @param string $matchColumn Junction-table column that links back to the outer row.
     * @param string $matchExpression Outer SQL expression the match column should equal.
     * @param string $filterColumn Junction-table column that should match the filter id.
     * @param int|null $value Normalized integer filter id from controller/parser input.
     * @param string $placeholderPrefix Prefix used to namespace generated placeholders.
     * @param string $filterKey Stable key name appended to the placeholder.
     * @return void
     */
    public function appendExistsIntMatch(
        array &$where,
        array &$params,
        string $table,
        string $alias,
        string $matchColumn,
        string $matchExpression,
        string $filterColumn,
        ?int $value,
        string $placeholderPrefix,
        string $filterKey
    ): void {
        $normalizedValue = $this->normalizePositiveId($value);
        // Skip clause generation when the caller did not supply a usable positive id.
        if ($normalizedValue === null) {
            return;
        }

        $placeholder = $this->placeholder($placeholderPrefix, $filterKey);
        $where[] = 'EXISTS (
                SELECT 1
                FROM ' . $table . ' ' . $alias . '
                WHERE ' . $matchColumn . ' = ' . $matchExpression . '
                  AND ' . $filterColumn . ' = ' . $placeholder . '
            )';
        $params[$placeholder] = $normalizedValue;
    }

    /**
     * Appends one integer equality clause when the filter id is present.
     *
     * @param array<int, string> $where Mutable WHERE-clause fragment list.
     * @param array<string, int|string> $params Mutable prepared-statement parameter map.
     * @param string $column SQL column/expression that should equal the filter id.
     * @param int|null $value Normalized integer filter id from controller/parser input.
     * @param string $placeholderPrefix Prefix used to namespace generated placeholders.
     * @param string $filterKey Stable key name appended to the placeholder.
     * @return void
     */
    public function appendIntEquals(
        array &$where,
        array &$params,
        string $column,
        ?int $value,
        string $placeholderPrefix,
        string $filterKey
    ): void {
        $normalizedValue = $this->normalizePositiveId($value);
        // Skip clause generation when the caller did not supply a usable positive id.
        if ($normalizedValue === null) {
            return;
        }

        $placeholder = $this->placeholder($placeholderPrefix, $filterKey);
        $where[] = $column . ' = ' . $placeholder;
        $params[$placeholder] = $normalizedValue;
    }

    /**
     * Normalizes one placeholder name to the stable `:prefix_key` format.
     *
     * @param string $placeholderPrefix Prefix used to namespace generated placeholders.
     * @param string $filterKey Stable key name appended to the placeholder.
     * @return string SQL placeholder token beginning with `:`.
     */
    private function placeholder(string $placeholderPrefix, string $filterKey): string
    {
        $placeholderPrefix = trim($placeholderPrefix);
        // Fall back to a stable default namespace when callers pass blank prefixes.
        if ($placeholderPrefix === '') {
            $placeholderPrefix = 'filter';
        }

        $filterKey = trim($filterKey);
        // Fall back to a stable key name when callers pass blank filter names.
        if ($filterKey === '') {
            $filterKey = 'value';
        }

        return ':' . $placeholderPrefix . '_' . $filterKey . '_id';
    }

    /**
     * Normalizes one filter id to a positive integer or null.
     *
     * @param int|null $value Candidate filter id from the caller.
     * @return int|null Positive integer id, or null when empty/invalid.
     */
    private function normalizePositiveId(?int $value): ?int
    {
        return $value !== null && $value > 0 ? $value : null;
    }
}
