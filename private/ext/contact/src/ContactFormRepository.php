<?php

/**
 * RAVEN CMS
 * ~/private/ext/contact/src/ContactFormRepository.php
 * Repository for Contact extension form definition persistence.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Repository;

use RuntimeException;

/**
 * Data access for Contact extension form definitions.
 */
final class ContactFormRepository
{
    private string $formsFilePath;

    public function __construct(?string $formsFilePath = null)
    {
        $this->formsFilePath = $formsFilePath !== null && trim($formsFilePath) !== ''
            ? trim($formsFilePath)
            : dirname(__DIR__, 4) . '/private/dat/ext/contact/forms.php';
    }

    /**
     * Returns all configured contact forms sorted by name.
     *
     * @return array<int, array{
     *   name: string,
     *   slug: string,
     *   enabled: bool,
     *   save_mail_locally: bool,
     *   destination: string,
     *   cc: string,
     *   bcc: string,
     *   additional_fields: array<int, array{
     *     label: string,
     *     name: string,
     *     type: string,
     *     required: bool,
     *     options: array<int, string>,
     *     options_input: string
     *   }>
     * }>
     */
    public function listAll(): array
    {
        $rows = $this->loadStoredForms();
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
                'save_mail_locally' => !array_key_exists('save_mail_locally', $row) || (int) ($row['save_mail_locally'] ?? 1) === 1,
                'destination' => trim((string) ($row['destination'] ?? '')),
                'cc' => trim((string) ($row['cc'] ?? '')),
                'bcc' => trim((string) ($row['bcc'] ?? '')),
                'additional_fields' => $this->decodeAdditionalFields((string) ($row['additional_fields_json'] ?? '[]')),
            ];
        }

        return $forms;
    }

    /**
     * Replaces all configured contact forms in one transaction.
     *
     * @param array<int, array<string, mixed>> $forms
     */
    public function replaceAll(array $forms): void
    {
        $normalized = $this->normalizeForms($forms);

        usort($normalized, static function (array $left, array $right): int {
            return strcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
        });

        $seenSlugs = [];
        foreach ($normalized as $form) {
            $slug = (string) ($form['slug'] ?? '');
            if (isset($seenSlugs[$slug])) {
                throw new RuntimeException('A contact form with that slug already exists.');
            }

            $seenSlugs[$slug] = true;
        }

        $this->persistForms($normalized);
    }

    /**
     * @param array<int, array<string, mixed>> $forms
     * @return array<int, array{
     *   name: string,
     *   slug: string,
     *   enabled: bool,
     *   save_mail_locally: bool,
     *   destination: string,
     *   cc: string,
     *   bcc: string,
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
                'save_mail_locally' => !array_key_exists('save_mail_locally', $entry) || (bool) ($entry['save_mail_locally'] ?? true),
                'destination' => trim((string) ($entry['destination'] ?? '')),
                'cc' => trim((string) ($entry['cc'] ?? '')),
                'bcc' => trim((string) ($entry['bcc'] ?? '')),
                'additional_fields' => $this->normalizeAdditionalFields((array) ($entry['additional_fields'] ?? [])),
            ];
        }

        return $normalized;
    }

    /**
     * @param array<int, mixed> $rawFields
     * @return array<int, array{label: string, name: string, type: string, required: bool, options: array<int, string>, options_input: string}>
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
            if ($fieldType === 'dropdown') {
                $fieldType = 'select';
            }
            if (!in_array($fieldType, ['text', 'email', 'textarea', 'radio', 'checkbox', 'select'], true)) {
                $fieldType = 'text';
            }
            $fieldOptions = $this->normalizeFieldOptions($rawField['options'] ?? []);
            $fieldOptionsInput = $this->normalizeFieldOptionsInput(
                $rawField['options_input'] ?? null,
                $rawField['options'] ?? null,
                $fieldOptions
            );

            if ($fieldLabel === '' || $fieldName === '') {
                continue;
            }

            $fields[] = [
                'label' => $fieldLabel,
                'name' => $fieldName,
                'type' => $fieldType,
                'required' => (bool) ($rawField['required'] ?? false),
                'options' => $fieldOptions,
                'options_input' => $fieldOptionsInput,
            ];
        }

        return $fields;
    }

    /**
     * @param array<int, array{label: string, name: string, type: string, required: bool, options: array<int, string>}> $fields
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
     * @param mixed $rawOptions
     * @return array<int, string>
     */
    private function normalizeFieldOptions(mixed $rawOptions): array
    {
        $optionCandidates = [];

        if (is_array($rawOptions)) {
            $optionCandidates = $rawOptions;
        } elseif (is_string($rawOptions)) {
            $optionCandidates = preg_split('/[\r\n,]+/', $rawOptions) ?: [];
        }

        $options = [];
        foreach ($optionCandidates as $candidate) {
            if (!is_scalar($candidate)) {
                continue;
            }

            $option = trim((string) $candidate);
            if ($option === '') {
                continue;
            }

            $option = substr($option, 0, 120);
            if ($option === '' || isset($options[$option])) {
                continue;
            }

            $options[$option] = $option;
            if (count($options) >= 100) {
                break;
            }
        }

        return array_values($options);
    }

    /**
     * Builds one stable editor-facing options input string.
     *
     * @param mixed $rawOptionsInput
     * @param mixed $rawOptions
     * @param array<int, string> $normalizedOptions
     */
    private function normalizeFieldOptionsInput(mixed $rawOptionsInput, mixed $rawOptions, array $normalizedOptions): string
    {
        $rawInput = '';
        if (is_scalar($rawOptionsInput)) {
            $rawInput = (string) $rawOptionsInput;
        } elseif (is_scalar($rawOptions)) {
            $rawInput = (string) $rawOptions;
        }

        if ($rawInput !== '') {
            $rawInput = str_replace("\0", '', $rawInput);
            $rawInput = preg_replace('/[\x01-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $rawInput) ?? '';
            $rawInput = str_replace(["\r\n", "\r"], "\n", $rawInput);
            $rawInput = trim($rawInput);
        }

        if ($rawInput === '' && $normalizedOptions !== []) {
            return implode("\n", $normalizedOptions);
        }

        return $rawInput;
    }

    private function formsFilePath(): string
    {
        return $this->formsFilePath;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadStoredForms(): array
    {
        $path = $this->formsFilePath();
        if (!is_file($path)) {
            return [];
        }

        /** @var mixed $data */
        $data = require $path;
        return is_array($data) ? $data : [];
    }

    /**
     * @param array<int, array<string, mixed>> $forms
     */
    private function persistForms(array $forms): void
    {
        $path = $this->formsFilePath();
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Failed to prepare contact form storage directory.');
        }

        $payload = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export(array_values($forms), true) . ";\n";
        if (@file_put_contents($path, $payload, LOCK_EX) === false) {
            throw new RuntimeException('Failed to save contact form definitions.');
        }
    }
}
