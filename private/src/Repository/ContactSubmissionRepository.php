<?php

/**
 * RAVEN CMS
 * ~/private/src/Repository/ContactSubmissionRepository.php
 * Repository for contact submission persistence and panel management queries.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Keep contact-submission storage logic centralized so public/panel flows stay consistent.

declare(strict_types=1);

namespace Raven\Repository;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Data access for Contact extension submissions.
 */
final class ContactSubmissionRepository
{
    private PDO $db;
    private string $driver;
    private string $prefix;

    public function __construct(PDO $db, string $driver, string $prefix)
    {
        $this->db = $db;
        $this->driver = $driver;
        // Prefix is ignored in SQLite mode because attached aliases are used.
        $this->prefix = $driver === 'sqlite' ? '' : (preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? '');
    }

    /**
     * Persists one contact submission and returns its id.
     *
     * @param array{
     *   form_slug: string,
     *   form_target?: string,
     *   sender_name: string,
     *   sender_email: string,
     *   message_text: string,
     *   additional_fields_json?: string,
     *   source_url: string,
     *   ip_address: string|null,
     *   hostname?: string|null,
     *   user_agent: string|null,
     *   created_at?: string
     * } $data
     */
    public function create(array $data): int
    {
        $table = $this->table('ext_contact_submissions');

        $formSlug = trim((string) ($data['form_slug'] ?? ''));
        $formTarget = trim((string) ($data['form_target'] ?? $formSlug));
        $senderName = trim((string) ($data['sender_name'] ?? ''));
        $senderEmail = strtolower(trim((string) ($data['sender_email'] ?? '')));
        $messageText = trim((string) ($data['message_text'] ?? ''));
        $additionalFieldsJson = trim((string) ($data['additional_fields_json'] ?? ''));
        $sourceUrl = trim((string) ($data['source_url'] ?? ''));
        $ipAddress = isset($data['ip_address']) ? trim((string) $data['ip_address']) : '';
        $hostname = isset($data['hostname']) ? strtolower(trim((string) $data['hostname'])) : '';
        $userAgent = isset($data['user_agent']) ? trim((string) $data['user_agent']) : '';

        $ipAddress = $ipAddress !== '' ? substr($ipAddress, 0, 45) : null;
        $hostname = $hostname !== '' ? substr($hostname, 0, 255) : null;
        $userAgent = $userAgent !== '' ? substr($userAgent, 0, 500) : null;

        if ($formSlug === '' || $senderName === '' || $senderEmail === '' || $messageText === '') {
            throw new RuntimeException('Contact submission is missing required values.');
        }

        if ($additionalFieldsJson === '') {
            $additionalFieldsJson = '[]';
        }

        $createdAt = $this->normalizeCreatedAt((string) ($data['created_at'] ?? ''));

        try {
            $stmt = $this->db->prepare(
                'INSERT INTO ' . $table . '
                 (form_slug, form_target, sender_name, sender_email, message_text, additional_fields_json, source_url, ip_address, hostname, user_agent, created_at)
                 VALUES
                 (:form_slug, :form_target, :sender_name, :sender_email, :message_text, :additional_fields_json, :source_url, :ip_address, :hostname, :user_agent, :created_at)'
            );
            $stmt->execute([
                ':form_slug' => $formSlug,
                ':form_target' => $formTarget,
                ':sender_name' => $senderName,
                ':sender_email' => $senderEmail,
                ':message_text' => $messageText,
                ':additional_fields_json' => $additionalFieldsJson,
                ':source_url' => $sourceUrl,
                ':ip_address' => $ipAddress,
                ':hostname' => $hostname,
                ':user_agent' => $userAgent,
                ':created_at' => $createdAt,
            ]);
        } catch (PDOException) {
            throw new RuntimeException('Failed to store contact submission.');
        }

        return (int) $this->db->lastInsertId();
    }

    /**
     * Returns total contact submission count for one form slug with optional search term.
     */
    public function countByFormSlug(string $formSlug, string $search = ''): int
    {
        $table = $this->table('ext_contact_submissions');

        $sql = 'SELECT COUNT(*)
                FROM ' . $table . '
                WHERE form_slug = :form_slug';
        $params = [
            ':form_slug' => $formSlug,
        ];

        $sql .= $this->searchClause($search, $params);

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

            return (int) ($stmt->fetchColumn() ?: 0);
        } catch (PDOException) {
            throw new RuntimeException('Failed to count contact submissions.');
        }
    }

    /**
     * Returns paginated contact submissions for one form slug.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listByFormSlug(string $formSlug, int $limit, int $offset, string $search = ''): array
    {
        $table = $this->table('ext_contact_submissions');

        $sql = 'SELECT id, form_slug, form_target, sender_name, sender_email, message_text, additional_fields_json, source_url, ip_address, hostname, user_agent, created_at
                FROM ' . $table . '
                WHERE form_slug = :form_slug';
        $params = [
            ':form_slug' => $formSlug,
        ];

        $sql .= $this->searchClause($search, $params);
        $sql .= ' ORDER BY created_at DESC, id DESC
                  LIMIT :limit OFFSET :offset';

        try {
            $stmt = $this->db->prepare($sql);

            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }

            $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
            $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);

            $stmt->execute();

            return $stmt->fetchAll() ?: [];
        } catch (PDOException) {
            throw new RuntimeException('Failed to load contact submissions.');
        }
    }

    /**
     * Returns all matching contact submissions for CSV export.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForExportByFormSlug(string $formSlug, string $search = ''): array
    {
        $table = $this->table('ext_contact_submissions');

        $sql = 'SELECT id, form_slug, form_target, sender_name, sender_email, message_text, additional_fields_json, source_url, ip_address, hostname, user_agent, created_at
                FROM ' . $table . '
                WHERE form_slug = :form_slug';
        $params = [
            ':form_slug' => $formSlug,
        ];

        $sql .= $this->searchClause($search, $params);
        $sql .= ' ORDER BY created_at DESC, id DESC';

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

            return $stmt->fetchAll() ?: [];
        } catch (PDOException) {
            throw new RuntimeException('Failed to load contact submissions export data.');
        }
    }

    /**
     * Deletes one contact submission row scoped to one form slug.
     */
    public function deleteById(string $formSlug, int $id): bool
    {
        $table = $this->table('ext_contact_submissions');

        try {
            $stmt = $this->db->prepare(
                'DELETE FROM ' . $table . '
                 WHERE form_slug = :form_slug
                   AND id = :id'
            );
            $stmt->bindValue(':form_slug', $formSlug);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->rowCount() > 0;
        } catch (PDOException) {
            throw new RuntimeException('Failed to delete contact submission.');
        }
    }

    /**
     * Deletes all contact submissions for one form slug and returns deleted row count.
     */
    public function deleteAllByFormSlug(string $formSlug): int
    {
        $table = $this->table('ext_contact_submissions');

        try {
            $stmt = $this->db->prepare(
                'DELETE FROM ' . $table . '
                 WHERE form_slug = :form_slug'
            );
            $stmt->execute([':form_slug' => $formSlug]);

            return $stmt->rowCount();
        } catch (PDOException) {
            throw new RuntimeException('Failed to clear contact submissions.');
        }
    }

    /**
     * Synchronizes stored submission metadata after a contact-form slug edit.
     */
    public function syncFormIdentity(string $fromSlug, string $toSlug): void
    {
        $table = $this->table('ext_contact_submissions');

        try {
            $stmt = $this->db->prepare(
                'UPDATE ' . $table . '
                 SET form_slug = :to_slug,
                     form_target = :to_slug
                 WHERE form_slug = :from_slug'
            );
            $stmt->execute([
                ':to_slug' => $toSlug,
                ':from_slug' => $fromSlug,
            ]);
        } catch (PDOException) {
            throw new RuntimeException('Failed to synchronize contact submission metadata for the edited contact form.');
        }
    }

    /**
     * Builds optional case-insensitive search clause and binds values.
     *
     * @param array<string, mixed> $params
     */
    private function searchClause(string $search, array &$params): string
    {
        $search = strtolower(trim($search));
        if ($search === '') {
            return '';
        }

        $params[':search'] = '%' . $search . '%';

        return ' AND (
                    LOWER(sender_name) LIKE :search
                    OR LOWER(sender_email) LIKE :search
                    OR LOWER(message_text) LIKE :search
                    OR LOWER(COALESCE(additional_fields_json, \'\')) LIKE :search
                    OR LOWER(COALESCE(source_url, \'\')) LIKE :search
                    OR LOWER(COALESCE(ip_address, \'\')) LIKE :search
                    OR LOWER(COALESCE(hostname, \'\')) LIKE :search
                    OR LOWER(COALESCE(user_agent, \'\')) LIKE :search
                 )';
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
            'ext_contact_submissions' => 'extensions.ext_contact_submissions',
            default => 'main.' . $table,
        };
    }

    /**
     * Accepts common timestamp formats and normalizes to UTC SQL datetime.
     */
    private function normalizeCreatedAt(string $rawValue): string
    {
        $rawValue = trim($rawValue);
        if ($rawValue === '') {
            return gmdate('Y-m-d H:i:s');
        }

        // Keep canonical SQL DATETIME values as-is to avoid timezone shifting.
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $rawValue) === 1) {
            return $rawValue;
        }

        // Accept unix epoch seconds or milliseconds.
        if (preg_match('/^\d{10,13}$/', $rawValue) === 1) {
            $timestamp = (int) $rawValue;
            if (strlen($rawValue) === 13) {
                $timestamp = (int) floor($timestamp / 1000);
            }

            return gmdate('Y-m-d H:i:s', $timestamp);
        }

        try {
            return (new DateTimeImmutable($rawValue))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            // Fall through to `now` if value is not parseable.
        }

        return gmdate('Y-m-d H:i:s');
    }
}
