<?php

declare(strict_types=1);

namespace Raven\Lib\View;

/**
 * Resolves scoped template-tag paths (`foo:bar:baz`) to runtime values.
 */
final class TemplateTagPathResolver
{
    /**
     * @param array<int, array<string, mixed>> $scope
     */
    public function resolve(string $path, array $scope): mixed
    {
        $segments = array_values(array_filter(explode(':', trim($path)), static fn (string $value): bool => $value !== ''));
        if ($segments === []) {
            return null;
        }

        $first = array_shift($segments);
        if (!is_string($first) || $first === '') {
            return null;
        }

        $found = false;
        $value = $this->lookupFirstSegment($first, $scope, $found);
        if (!$found) {
            return null;
        }

        foreach ($segments as $segment) {
            if ($segment === '') {
                return null;
            }

            $value = $this->lookupNestedSegment($value, $segment, $found);
            if (!$found) {
                return null;
            }
        }

        return $value;
    }

    /**
     * @param array<int, array<string, mixed>> $scope
     */
    public function truthy(string $path, array $scope): bool
    {
        $value = $this->resolve($path, $scope);
        if ($value === null) {
            return false;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value != 0;
        }

        if (is_string($value)) {
            $normalized = trim($value);
            return $normalized !== '' && $normalized !== '0';
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }

    /**
     * Compares a resolved path value against a literal right-hand side using the given operator.
     *
     * @param string $path Colon-delimited tag path to resolve (e.g. 'page:id').
     * @param string $operator Comparison operator: ==, !=, <, >, <=, >=.
     * @param string $rhs Right-hand side literal from the template — a quoted string ('value'/"value") or a bare number.
     * @param array<int, array<string, mixed>> $scope Template scope stack.
     * @return bool True when the comparison holds.
     */
    public function compare(string $path, string $operator, string $rhs, array $scope): bool
    {
        $value = $this->resolve($path, $scope);

        // Detect quoted string literals and compare as strings after stripping the surrounding quotes.
        $isStringRhs = $rhs !== '' && ($rhs[0] === '\'' || $rhs[0] === '"');
        if ($isStringRhs) {
            $rhsUnquoted = substr($rhs, 1, -1);
            $lhs = $value !== null ? (string) $value : '';
            return match ($operator) {
                '=='    => $lhs === $rhsUnquoted,
                '!='    => $lhs !== $rhsUnquoted,
                '<'     => $lhs < $rhsUnquoted,
                '>'     => $lhs > $rhsUnquoted,
                '<='    => $lhs <= $rhsUnquoted,
                '>='    => $lhs >= $rhsUnquoted,
                default => false,
            };
        }

        // Numeric comparison: coerce both sides to float for consistent behaviour with integer IDs.
        $rhsFloat = is_numeric($rhs) ? (float) $rhs : 0.0;
        $lhsFloat = is_int($value) || is_float($value)
            ? (float) $value
            : (is_string($value) && is_numeric($value) ? (float) $value : 0.0);
        return match ($operator) {
            '=='    => $lhsFloat == $rhsFloat,
            '!='    => $lhsFloat != $rhsFloat,
            '<'     => $lhsFloat < $rhsFloat,
            '>'     => $lhsFloat > $rhsFloat,
            '<='    => $lhsFloat <= $rhsFloat,
            '>='    => $lhsFloat >= $rhsFloat,
            default => false,
        };
    }

    /**
     * @param array<int, array<string, mixed>> $scope
     * @return array<int|string, mixed>
     */
    public function iter(string $path, array $scope): array
    {
        $value = $this->resolve($path, $scope);
        return is_array($value) ? $value : [];
    }

    /**
     * @param array<int, array<string, mixed>> $scope
     */
    private function lookupFirstSegment(string $segment, array $scope, bool &$found): mixed
    {
        for ($index = count($scope) - 1; $index >= 0; $index--) {
            $layer = $scope[$index] ?? null;
            if (!is_array($layer)) {
                continue;
            }

            if (array_key_exists($segment, $layer)) {
                $found = true;
                return $layer[$segment];
            }

            if (ctype_digit($segment)) {
                $intKey = (int) $segment;
                if (array_key_exists($intKey, $layer)) {
                    $found = true;
                    return $layer[$intKey];
                }
            }
        }

        $found = false;
        return null;
    }

    private function lookupNestedSegment(mixed $value, string $segment, bool &$found): mixed
    {
        if (is_array($value)) {
            if (array_key_exists($segment, $value)) {
                $found = true;
                return $value[$segment];
            }

            if (ctype_digit($segment)) {
                $intKey = (int) $segment;
                if (array_key_exists($intKey, $value)) {
                    $found = true;
                    return $value[$intKey];
                }
            }

            $found = false;
            return null;
        }

        if (is_object($value)) {
            $properties = get_object_vars($value);
            if (array_key_exists($segment, $properties)) {
                $found = true;
                return $properties[$segment];
            }
        }

        $found = false;
        return null;
    }
}

