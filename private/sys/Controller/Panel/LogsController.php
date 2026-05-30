<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Panel/LogsController.php
 * Split panel logs controller for event-log routes.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Panel;

use Closure;
use Raven\Core\Logger;
use Raven\Lib\Auth\Panel\PermissionBase as PanelAccess;
use Raven\Lib\Format\Csv;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\Transport\Redirect;
use Raven\Lib\View\Pagination;

/**
 * Handles split panel event-log routes.
 *
 * Owns the `/logs*` route family so log viewing/export/clear flows no longer
 * ride through the broader system-management controller.
 */
final class LogsController
{
    private SharedController $context;
    private InputSanitizer $input;
    /** @var Closure(): Logger */
    private Closure $loggerResolver;
    private ?Logger $logger = null;
    private ?Csv $csvHandler = null;

    /**
     * @param SharedController $context Shared panel request context.
     * @param InputSanitizer $input Shared request input sanitizer.
     * @param callable(): Logger $loggerResolver Lazy event logger resolver.
     * @return void
     */
    public function __construct(
        SharedController $context,
        InputSanitizer $input,
        callable $loggerResolver
    ) {
        $this->context = $context;
        $this->input = $input;
        $this->loggerResolver = Closure::fromCallable($loggerResolver);
    }

    /**
     * Renders the event log viewer.
     *
     * @return void
     */
    public function logs(): void
    {
        $this->context->requirePanelLogin();
        // Viewing log entries requires logs:view permission.
        if (!$this->context->requireRoutePermissionOrForbidden('logs', 'view')) {
            return;
        }

        $severity = $this->input->text($_GET['severity'] ?? null, 10) ?? '';
        $severity = in_array($severity, ['error', 'warn', 'info'], true) ? $severity : '';
        $search = $this->input->text($_GET['search'] ?? null, 200) ?? '';

        $filters = [];
        // Apply severity filter only when explicitly selected.
        if ($severity !== '') {
            $filters['severity'] = $severity;
        }
        // Apply free-text search filter only when user supplied input.
        if ($search !== '') {
            $filters['search'] = $search;
        }

        $perPage = 50;
        $requestedPage = $this->input->int($_GET['page'] ?? null, 1) ?? 1;
        $totalItems = $this->logger()->count($filters);
        $pagination = Pagination::state($totalItems, $requestedPage, $perPage);
        // Clamp out-of-range page requests to a valid pagination state.
        if ($totalItems > 0 && $pagination['current'] !== $requestedPage) {
            $requestedPage = $pagination['current'];
        }

        $rows = $this->logger()->query($filters, $perPage, $pagination['offset']);
        $paginationQuery = [];
        // Preserve active severity filter across pagination links.
        if ($severity !== '') {
            $paginationQuery['severity'] = $severity;
        }
        // Preserve active search filter across pagination links.
        if ($search !== '') {
            $paginationQuery['search'] = $search;
        }

        $this->context->renderPanel('panel/logs', [
            'rows' => $rows,
            'filters' => ['severity' => $severity, 'search' => $search],
            'pagination' => Pagination::panelViewData($this->context->panelUrl('/logs'), $pagination, $paginationQuery),
            'totalItems' => $totalItems,
            'loggingEnabled' => $this->logger()->isEnabled('error') || $this->logger()->isEnabled('warn') || $this->logger()->isEnabled('info'),
            'csrfField' => $this->context->csrf()->field(),
            'flashSuccess' => $this->context->pullFlash('success'),
            'flashError' => $this->context->pullFlash('error'),
            'section' => 'logs',
            'pageTitle' => 'Event Log',
            'canClear' => $this->context->auth()->panelService()->hasPanelPermissionBit(PanelAccess::CONFIGURATION_DELETE),
        ]);
    }

    /**
     * Exports the event log as CSV.
     *
     * @return void
     */
    public function logsExport(): void
    {
        $this->context->requirePanelLogin();
        // Export uses same view permission as the log browser.
        if (!$this->context->requireRoutePermissionOrForbidden('logs', 'view')) {
            return;
        }

        $severity = $this->input->text($_GET['severity'] ?? null, 10) ?? '';
        $severity = in_array($severity, ['error', 'warn', 'info'], true) ? $severity : '';
        $search = $this->input->text($_GET['search'] ?? null, 200) ?? '';

        $filters = [];
        // Apply severity filter only when explicitly selected.
        if ($severity !== '') {
            $filters['severity'] = $severity;
        }
        // Apply free-text search filter only when user supplied input.
        if ($search !== '') {
            $filters['search'] = $search;
        }

        $rows = $this->logger()->allForExport($filters);
        $filename = 'event-log-' . gmdate('Ymd-His') . '.csv';
        $this->csvHandler()->streamToOutput(
            $filename,
            (static function (array $rows): \Generator {
                // Emit one CSV row per log entry in stable column order.
                foreach ($rows as $row) {
                    yield [
                        (string) ($row['id'] ?? ''),
                        (string) ($row['logged_at'] ?? ''),
                        (string) ($row['severity'] ?? ''),
                        (string) ($row['channel'] ?? ''),
                        (string) ($row['message'] ?? ''),
                        (string) ($row['context'] ?? ''),
                    ];
                }
            })($rows),
            ['ID', 'Logged At', 'Severity', 'Channel', 'Message', 'Context']
        );
    }

    /**
     * Clears all event log entries.
     *
     * @return void
     */
    public function logsClear(): void
    {
        $this->context->requirePanelLogin();
        // Clearing logs is delete-permission gated.
        if (!$this->context->requireRoutePermissionOrForbidden('logs', 'delete')) {
            return;
        }

        $post = $_POST;
        // CSRF validation protects destructive log-clear actions.
        if (!$this->context->csrf()->validate($post['_csrf'] ?? null)) {
            $this->context->flash('error', 'Invalid CSRF token.');
            Redirect::redirect($this->context->panelUrl('/logs'));
        }

        $deleted = $this->logger()->clear();
        $this->context->flash('success', 'Event log cleared (' . $deleted . ' ' . ($deleted === 1 ? 'entry' : 'entries') . ' removed).');
        Redirect::redirect($this->context->panelUrl('/logs'));
    }

    /**
     * Returns the event logger on first use.
     *
     * @return Logger Event logger service.
     */
    private function logger(): Logger
    {
        // Reuse cached logger instance for all controller actions in this request.
        if ($this->logger instanceof Logger) {
            return $this->logger;
        }

        $logger = ($this->loggerResolver)();
        // Resolver contract must return the concrete logger service.
        if (!$logger instanceof Logger) {
            throw new \RuntimeException('Panel event logger resolver returned an invalid value.');
        }

        $this->logger = $logger;
        return $this->logger;
    }

    /**
     * Returns the CSV archive handler on first use.
     *
     * @return Csv Canonical CSV import/export helper.
     */
    private function csvHandler(): Csv
    {
        // Instantiate CSV helper lazily for export requests.
        if (!$this->csvHandler instanceof Csv) {
            $this->csvHandler = new Csv();
        }

        return $this->csvHandler;
    }
}
