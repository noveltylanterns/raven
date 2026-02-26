<?php

/**
 * RAVEN CMS
 * ~/private/src/Repository/SignupFormRepository.php
 * Repository for Signup Sheets extension form definition persistence.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Repository;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Data access for Signup Sheets extension form definitions.
 */
final class SignupFormRepository
{
    private PDO $db;
    private string $driver;
    private string $prefix;

    public function __construct(PDO $db, string $driver, string $prefix)
    {
        $this->db = $db;
        $this->driver = $driver;
        // Prefix is ignored for SQLite because attached database aliases are used instead.
        $this->prefix = $driver === 'sqlite' ? '' : (preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '');
    }

    /**
     * Returns all configured signup sheet forms sorted by name.
     *
     * @return array<int, array{
     *   name: string,
     *   slug: string,
     *   enabled: bool,
     *   additional_fields: array<int, array{
     *     label: string,
     *     name: string,
     *     type: string,
     *     required: bool
     *   }>
     * }>
     */
    public function listAll(): array
    {
        $table = $this->table('ext_signups');

        try {
            $stmt = $this->db->prepare(
                'SELECT name, slug, enabled, additional_fields_json
                 FROM ' . $table . '
                 ORDER BY name ASC, id ASC'
            );
            $stmt->execute();
        } catch (PDOException $exception) {
            throw new RuntimeException('Failed to load signup sheet form definitions.');
        }

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll() ?: [];
        $forms = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            $slug = trim((string) ($row['slug'] ?? ''));
            if ($name === '' || $slug === '') {
                continue;
            }

            $forms[] = [
                'name' => $name,
                'slug' => $slug,
                'enabled' => (int) ($row['enabled'] ?? 0) === 1,
                'additional_fields' => $this->decodeAdditionalFields((string) ($row['additional_fields_json'] ?? '[]')),
            ];
        }

        return $forms;
    }

    /**
     * Replaces all configured signup sheet forms in one transaction.
     *
     * @param array<int, array<string, mixed>> $forms
     */
    public function replaceAll(array $forms): void
    {
        $table = $this->table('ext_signups');
        $normalized = $this->normalizeForms($forms);

        usort($normalized, static function (array $left, array $right): int {
            return strcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
        });

        $seenSlugs = [];
        foreach ($normalized as $form) {
            $slug = (string) ($form['slug'] ?? '');
            if (isset($seenSlugs[$slug])) {
                throw new RuntimeException('A signup sheet form with that slug already exists.');
            }

            $seenSlugs[$slug] = true;
        }

        $now = gmdate('Y-m-d H:i:s');

        $this->db->beginTransaction();
        try {
            $insert = $this->db->prepare(
                'INSERT INTO ' . $table . '
                 (name, slug, enabled, additional_fields_json, created_at, updated_at)
                 VALUES
                 (:name, :slug, :enabled, :additional_fields_json, :created_at, :updated_at)'
            );

            $this->db->exec('DELETE FROM ' . $table);

            foreach ($normalized as $form) {
                $insert->execute([
                    ':name' => (string) ($form['name'] ?? ''),
                    ':slug' => (string) ($form['slug'] ?? ''),
                    ':enabled' => !empty($form['enabled']) ? 1 : 0,
                    ':additional_fields_json' => $this->encodeAdditionalFields((array) ($form['additional_fields'] ?? [])),
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]);
            }

            $this->db->commit();
        } catch (PDOException $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            if ($this->isUniqueConstraintError($exception)) {
                throw new RuntimeException('A signup sheet form with that slug already exists.');
            }

            throw new RuntimeException('Failed to save signup sheet form definitions.');
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $forms
     * @return array<int, array{
     *   name: string,
     *   slug: string,
     *   enabled: bool,
     *   additional_fields: array<int, array{
     *     label: string,
     *     name: string,
     *     type: string,
     *     required: bool
     *   }>
     * }>
     */
    private function normalizeForms(array $forms): array
    {
        $normalized = [];
        foreach ($forms as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $name = trim((string) ($entry['name'] ?? ''));
            $slug = strtolower(trim((string) ($entry['slug'] ?? '')));
            if ($name === '' || $slug === '' || preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug) !== 1) {
                continue;
            }

            $normalized[] = [
                'name' => $name,
                'slug' => $slug,
                'enabled' => (bool) ($entry['enabled'] ?? false),
                'additional_fields' => $this->normalizeAdditionalFields((array) ($entry['additional_fields'] ?? [])),
            ];
        }

        return $normalized;
    }

    /**
     * @param array<int, mixed> $rawFields
     * @return array<int, array{label: string, name: string, type: string, required: bool}>
     */
    private function normalizeAdditionalFields(array $rawFields): array
    {
        $fields = [];
        foreach ($rawFields as $rawField) {
            if (!is_array($rawField)) {
                continue;
            }

            $fieldLabel = trim((string) ($rawField['label'] ?? ''));
            $fieldName = strtolower(trim((string) ($rawField['name'] ?? '')));
            $fieldName = preg_replace('/[^a-z0-9_]+/', '_', $fieldName) ?? '';
            $fieldName = trim($fieldName, '_');
            $fieldType = strtolower(trim((string) ($rawField['type'] ?? 'text')));
            if (!in_array($fieldType, ['text', 'email', 'textarea'], true)) {
                $fieldType = 'text';
            }

            if ($fieldLabel === '' || $fieldName === '') {
                continue;
            }

            $fields[] = [
                'label' => $fieldLabel,
                'name' => $fieldName,
                'type' => $fieldType,
                'required' => (bool) ($rawField['required'] ?? false),
            ];
        }

        return $fields;
    }

    /**
     * @param array<int, array{label: string, name: string, type: string, required: bool}> $fields
     */
    private function encodeAdditionalFields(array $fields): string
    {
        $encoded = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || $encoded === '') {
            return '[]';
        }

        return $encoded;
    }

    /**
     * @return array<int, array{label: string, name: string, type: string, required: bool}>
     */
    private function decodeAdditionalFields(string $rawJson): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($rawJson, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $this->normalizeAdditionalFields($decoded);
    }

    /**
     * Maps logical table names into backend-specific physical names.
     */
    private function table(string $table): string
    {
        if ($this->driver !== 'sqlite') {
            return $this->prefix . $table;
        }

        return match ($table) {
            'ext_signups' => 'extensions.ext_signups',
            default => 'main.' . $table,
        };
    }

    /**
     * Detects duplicate-key SQL errors across supported PDO drivers.
     */
    private function isUniqueConstraintError(PDOException $exception): bool
    {
        $sqlState = (string) $exception->getCode();
        return in_array($sqlState, ['23000', '23505'], true);
    }
}
