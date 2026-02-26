<?php

/**
 * RAVEN CMS
 * ~/private/src/Repository/WaitlistSignupRepository.php
 * Repository for signup submission persistence and panel management queries.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Keep signup submission storage logic centralized so panel/public flows stay consistent.

declare(strict_types=1);

namespace Raven\Repository;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Data access for Signup Sheets extension submissions.
 */
final class WaitlistSignupRepository
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
     * Persists one signup submission and returns its id.
     *
     * @param array{
     *   form_slug: string,
     *   form_target?: string,
     *   email: string,
     *   display_name: string,
     *   country: string,
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
        $table = $this->table('ext_signups_submissions');

        $formSlug = trim((string) ($data['form_slug'] ?? ''));
        $formTarget = trim((string) ($data['form_target'] ?? $formSlug));
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $displayName = trim((string) ($data['display_name'] ?? ''));
        $country = strtolower(trim((string) ($data['country'] ?? '')));
        $additionalFieldsJson = trim((string) ($data['additional_fields_json'] ?? ''));
        $sourceUrl = trim((string) ($data['source_url'] ?? ''));
        $ipAddress = isset($data['ip_address']) ? trim((string) $data['ip_address']) : '';
        $hostname = isset($data['hostname']) ? strtolower(trim((string) $data['hostname'])) : '';
        $userAgent = isset($data['user_agent']) ? trim((string) $data['user_agent']) : '';

        $ipAddress = $ipAddress !== '' ? substr($ipAddress, 0, 45) : null;
        $hostname = $hostname !== '' ? substr($hostname, 0, 255) : null;
        $userAgent = $userAgent !== '' ? substr($userAgent, 0, 500) : null;

        if ($formSlug === '' || $email === '' || $displayName === '' || $country === '') {
            throw new RuntimeException('Signup submission is missing required values.');
        }
        if ($additionalFieldsJson === '') {
            $additionalFieldsJson = '[]';
        }

        if ($this->existsByFormAndEmail($formSlug, $email)) {
            throw new RuntimeException('That email is already signed up for this signup sheet.');
        }

        $createdAt = trim((string) ($data['created_at'] ?? ''));
        if ($createdAt !== '' && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $createdAt) !== 1) {
            $createdAt = '';
        }
        if ($createdAt === '' || strtotime($createdAt . ' UTC') === false) {
            $createdAt = gmdate('Y-m-d H:i:s');
        }

        try {
            $stmt = $this->db->prepare(
                'INSERT INTO ' . $table . '
                 (form_slug, form_target, email, display_name, country, additional_fields_json, source_url, ip_address, hostname, user_agent, created_at)
                 VALUES
                 (:form_slug, :form_target, :email, :display_name, :country, :additional_fields_json, :source_url, :ip_address, :hostname, :user_agent, :created_at)'
            );
            $stmt->execute([
                ':form_slug' => $formSlug,
                ':form_target' => $formTarget,
                ':email' => $email,
                ':display_name' => $displayName,
                ':country' => $country,
                ':additional_fields_json' => $additionalFieldsJson,
                ':source_url' => $sourceUrl,
                ':ip_address' => $ipAddress,
                ':hostname' => $hostname,
                ':user_agent' => $userAgent,
                ':created_at' => $createdAt,
            ]);
        } catch (PDOException $exception) {
            if ($this->isUniqueConstraintError($exception)) {
                throw new RuntimeException('That email is already signed up for this signup sheet.');
            }

            throw new RuntimeException('Failed to store signup submission.');
        }

        return (int) $this->db->lastInsertId();
    }

    /**
     * Returns total signup count for one signup-sheet form with optional search term.
     */
    public function countByFormSlug(string $formSlug, string $search = ''): int
    {
        $table = $this->table('ext_signups_submissions');

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
        } catch (PDOException $exception) {
            throw new RuntimeException('Failed to count signup submissions.');
        }
    }

    /**
     * Returns paginated signup submissions for one form slug.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listByFormSlug(string $formSlug, int $limit, int $offset, string $search = ''): array
    {
        $table = $this->table('ext_signups_submissions');

        $sql = 'SELECT id, form_slug, form_target, email, display_name, country, additional_fields_json, source_url, ip_address, hostname, user_agent, created_at
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
        } catch (PDOException $exception) {
            throw new RuntimeException('Failed to load signup submissions.');
        }
    }

    /**
     * Returns all matching signups for CSV export.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForExportByFormSlug(string $formSlug, string $search = ''): array
    {
        $table = $this->table('ext_signups_submissions');

        $sql = 'SELECT id, form_slug, form_target, email, display_name, country, additional_fields_json, source_url, ip_address, hostname, user_agent, created_at
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
        } catch (PDOException $exception) {
            throw new RuntimeException('Failed to load signup submissions export data.');
        }
    }

    /**
     * Deletes one signup row scoped to a form slug.
     */
    public function deleteById(string $formSlug, int $id): bool
    {
        $table = $this->table('ext_signups_submissions');

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
        } catch (PDOException $exception) {
            throw new RuntimeException('Failed to delete signup submission.');
        }
    }

    /**
     * Deletes all signups for one signup-sheet form and returns deleted row count.
     */
    public function deleteAllByFormSlug(string $formSlug): int
    {
        $table = $this->table('ext_signups_submissions');

        try {
            $stmt = $this->db->prepare(
                'DELETE FROM ' . $table . '
                 WHERE form_slug = :form_slug'
            );
            $stmt->execute([':form_slug' => $formSlug]);

            return $stmt->rowCount();
        } catch (PDOException $exception) {
            throw new RuntimeException('Failed to clear signup submissions.');
        }
    }

    /**
     * Synchronizes stored submission metadata after a signup-sheet form slug edit.
     */
    public function syncFormIdentity(string $fromSlug, string $toSlug): void
    {
        $table = $this->table('ext_signups_submissions');

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
        } catch (PDOException $exception) {
            if ($this->isUniqueConstraintError($exception)) {
                throw new RuntimeException('Cannot rename this signup sheet slug because matching email signups already exist on the destination slug.');
            }

            throw new RuntimeException('Failed to synchronize signup metadata for the edited signup sheet form.');
        }
    }

    /**
     * Returns true when one form already has a signup with this email.
     */
    private function existsByFormAndEmail(string $formSlug, string $email): bool
    {
        $table = $this->table('ext_signups_submissions');

        try {
            $stmt = $this->db->prepare(
                'SELECT 1
                 FROM ' . $table . '
                 WHERE form_slug = :form_slug
                   AND email = :email
                 LIMIT 1'
            );
            $stmt->execute([
                ':form_slug' => $formSlug,
                ':email' => $email,
            ]);

            return $stmt->fetchColumn() !== false;
        } catch (PDOException $exception) {
            throw new RuntimeException('Failed to validate existing signup submissions.');
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
                    LOWER(email) LIKE :search
                    OR LOWER(display_name) LIKE :search
                    OR LOWER(country) LIKE :search
                    OR LOWER(COALESCE(additional_fields_json, \'\')) LIKE :search
                    OR LOWER(COALESCE(source_url, \'\')) LIKE :search
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
            'ext_signups_submissions' => 'extensions.ext_signups_submissions',
            default => 'main.' . $table,
        };
    }

    /**
     * Detects duplicate-key SQL errors across supported PDO drivers.
     */
    private function isUniqueConstraintError(PDOException $exception): bool
    {
        $sqlState = (string) $exception->getCode();

        // 23000 (MySQL/SQLite) and 23505 (PostgreSQL) represent unique-constraint failures.
        return in_array($sqlState, ['23000', '23505'], true);
    }
}
