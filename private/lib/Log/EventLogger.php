<?php

declare(strict_types=1);

namespace Raven\Lib\Log;

use PDO;

/**
 * Writes panel event log entries to the event_log database table.
 *
 * Severity levels (error/warn/info) are individually toggled in the debug tab of the
 * config editor under the logging.* key group. Errors are enabled by default; warnings
 * and info are opt-in. An optional auxiliary syslog output can be enabled independently.
 *
 * Call log(), error(), warn(), or info() from controllers, repositories, or the global
 * PHP error handler. Use query()/count() to page through entries for the panel log view.
 * Use pruneOlderThan() in a scheduled job to enforce retention. Use clear() for the
 * admin "Clear Log" action.
 */
final class EventLogger
{
    private PDO $db;
    private string $driver;
    /** Physical table name resolved from prefix + 'event_log'. */
    private string $table;
    /** Whether to write error-severity entries. */
    private bool $logErrors;
    /** Whether to write warn-severity entries. */
    private bool $logWarnings;
    /** Whether to write info-severity entries. */
    private bool $logInfo;
    /** Number of days to keep log entries (used by pruneOlderThan). */
    private int $retentionDays;
    /** Whether to mirror each accepted log entry to the system syslog. */
    private bool $syslogEnabled;

    /**
     * @param PDO    $db            Active Raven database connection.
     * @param string $driver        Database driver: sqlite, mysql, or pgsql.
     * @param string $prefix        Table name prefix from site configuration.
     * @param array<string, mixed>  $loggingConfig  Values from the logging.* config key group.
     */
    public function __construct(PDO $db, string $driver, string $prefix, array $loggingConfig)
    {
        $this->db = $db;
        $this->driver = $driver;
        $this->table = $prefix . 'event_log';
        // Errors default to true so a fresh install captures failures immediately.
        $this->logErrors = (bool) ($loggingConfig['errors'] ?? true);
        $this->logWarnings = (bool) ($loggingConfig['warnings'] ?? false);
        $this->logInfo = (bool) ($loggingConfig['info'] ?? false);
        $this->retentionDays = max(1, (int) ($loggingConfig['retention_days'] ?? 30));
        $this->syslogEnabled = (bool) ($loggingConfig['syslog'] ?? false);
    }

    /**
     * Writes an error-severity entry if error logging is enabled.
     *
     * @param string               $message Short human-readable description of the event.
     * @param string               $channel Freeform category label (auth/content/system/extension).
     * @param array<string, mixed> $context Optional structured metadata to store alongside the message.
     */
    public function error(string $message, string $channel = 'system', array $context = []): void
    {
        $this->log('error', $message, $channel, $context);
    }

    /**
     * Writes a warn-severity entry if warning logging is enabled.
     *
     * @param string               $message Short human-readable description of the event.
     * @param string               $channel Freeform category label.
     * @param array<string, mixed> $context Optional structured metadata.
     */
    public function warn(string $message, string $channel = 'system', array $context = []): void
    {
        $this->log('warn', $message, $channel, $context);
    }

    /**
     * Writes an info-severity entry if info logging is enabled.
     *
     * @param string               $message Short human-readable description of the event.
     * @param string               $channel Freeform category label.
     * @param array<string, mixed> $context Optional structured metadata.
     */
    public function info(string $message, string $channel = 'system', array $context = []): void
    {
        $this->log('info', $message, $channel, $context);
    }

    /**
     * Writes a log entry of the given severity if that level is enabled.
     *
     * Silently no-ops when the severity level is disabled in config. Any DB write failure
     * is suppressed so that a logging error never cascades into an application error.
     *
     * @param string               $severity 'error', 'warn', or 'info'.
     * @param string               $message  Short human-readable description.
     * @param string               $channel  Freeform category label.
     * @param array<string, mixed> $context  Optional structured metadata stored as JSON.
     */
    public function log(string $severity, string $message, string $channel = 'system', array $context = []): void
    {
        if (!$this->isEnabled($severity)) {
            return;
        }

        $contextJson = $context !== []
            ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;
        if ($contextJson === false) {
            $contextJson = null;
        }

        try {
            $stmt = $this->db->prepare(
                'INSERT INTO ' . $this->table . ' (logged_at, severity, channel, message, context)
                 VALUES (:logged_at, :severity, :channel, :message, :context)'
            );
            $stmt->execute([
                ':logged_at' => gmdate('Y-m-d H:i:s'),
                ':severity'  => $severity,
                ':channel'   => $channel,
                ':message'   => $message,
                ':context'   => $contextJson,
            ]);
        } catch (\Throwable) {
            // Logger failures must never propagate — the application continues normally.
            return;
        }

        if ($this->syslogEnabled) {
            $priority = match ($severity) {
                'error'  => LOG_ERR,
                'warn'   => LOG_WARNING,
                default  => LOG_INFO,
            };
            @openlog('raven', LOG_PID, LOG_USER);
            @syslog($priority, '[' . $channel . '] ' . $message);
        }
    }

    /**
     * Returns true when the given severity level is currently enabled in config.
     *
     * @param string $severity 'error', 'warn', or 'info'.
     * @return bool True when entries at this level will be persisted.
     */
    public function isEnabled(string $severity): bool
    {
        return match ($severity) {
            'error'  => $this->logErrors,
            'warn'   => $this->logWarnings,
            'info'   => $this->logInfo,
            default  => false,
        };
    }

    /**
     * Returns the configured retention period in days.
     *
     * @return int Days, always >= 1.
     */
    public function retentionDays(): int
    {
        return $this->retentionDays;
    }

    /**
     * Returns a page of log rows ordered newest-first, with optional filtering.
     *
     * Supported filter keys: severity (error/warn/info), search (substring match on
     * message and channel).
     *
     * @param array<string, string> $filters Key-value filter constraints.
     * @param int                   $limit   Maximum rows to return.
     * @param int                   $offset  Number of rows to skip for pagination.
     * @return array<int, array<string, mixed>> Rows with id/logged_at/severity/channel/message/context.
     */
    public function query(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $stmt = $this->db->prepare(
            'SELECT id, logged_at, severity, channel, message, context
             FROM ' . $this->table . $where . '
             ORDER BY id DESC
             LIMIT :limit OFFSET :offset'
        );
        $params[':limit']  = $limit;
        $params[':offset'] = $offset;
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Returns the total count of rows matching the given filters (used for pagination).
     *
     * @param array<string, string> $filters Same filter keys as query().
     * @return int Total matching row count.
     */
    public function count(array $filters = []): int
    {
        [$where, $params] = $this->buildWhere($filters);
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM ' . $this->table . $where);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Deletes all rows older than the given number of days and returns the deleted count.
     *
     * @param int $days Rows with logged_at older than this many days are removed.
     * @return int Number of rows deleted.
     */
    public function pruneOlderThan(int $days): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', strtotime('-' . $days . ' days') ?: 0);
        $stmt = $this->db->prepare('DELETE FROM ' . $this->table . ' WHERE logged_at < :cutoff');
        $stmt->execute([':cutoff' => $cutoff]);
        return (int) $stmt->rowCount();
    }

    /**
     * Returns all matching rows for CSV export (no pagination, ordered newest-first).
     *
     * @param array<string, string> $filters Same filter keys as query().
     * @return array<int, array<string, mixed>> Full result set.
     */
    public function allForExport(array $filters = []): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $stmt = $this->db->prepare(
            'SELECT id, logged_at, severity, channel, message, context
             FROM ' . $this->table . $where . '
             ORDER BY id DESC'
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Deletes all log entries and returns the number of rows removed.
     *
     * @return int Number of rows deleted.
     */
    public function clear(): int
    {
        // DELETE without WHERE is safe and faster than TRUNCATE on SQLite.
        $result = $this->db->exec('DELETE FROM ' . $this->table);
        return is_int($result) ? $result : 0;
    }

    /**
     * Builds a WHERE clause and parameter map from the given filter array.
     *
     * @param array<string, string> $filters
     * @return array{string, array<string, mixed>} [whereClause, params]
     */
    private function buildWhere(array $filters): array
    {
        $conditions = [];
        $params = [];

        $severity = strtolower(trim((string) ($filters['severity'] ?? '')));
        if (in_array($severity, ['error', 'warn', 'info'], true)) {
            $conditions[] = 'severity = :severity';
            $params[':severity'] = $severity;
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            // Substring search across message and channel.
            $conditions[] = '(message LIKE :search OR channel LIKE :search)';
            $params[':search'] = '%' . $search . '%';
        }

        $where = $conditions !== [] ? (' WHERE ' . implode(' AND ', $conditions)) : '';
        return [$where, $params];
    }
}
