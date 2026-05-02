<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Panel/LogsController.php
 * Split panel logs controller for event-log routes.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Panel;

use Closure;
use Raven\Core\Logger;
use Raven\Lib\Auth\PanelAccess;
use Raven\Lib\Format\Csv;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\Transport\Redirect;

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
        if (!$this->context->requireRoutePermissionOrForbidden('logs', 'view')) {
            return;
        }

        $severity = $this->input->text($_GET['severity'] ?? null, 10) ?? '';
        $severity = in_array($severity, ['error', 'warn', 'info'], true) ? $severity : '';
        $search = $this->input->text($_GET['search'] ?? null, 200) ?? '';

        $filters = [];
        if ($severity !== '') {
            $filters['severity'] = $severity;
        }
        if ($search !== '') {
            $filters['search'] = $search;
        }

        $perPage = 50;
        $requestedPage = $this->input->int($_GET['page'] ?? null, 1) ?? 1;
        $totalItems = $this->logger()->count($filters);
        $pagination = $this->context->panelPaginationState($totalItems, $requestedPage, $perPage);
        if ($totalItems > 0 && $pagination['current'] !== $requestedPage) {
            $requestedPage = $pagination['current'];
        }

        $rows = $this->logger()->query($filters, $perPage, $pagination['offset']);
        $paginationQuery = [];
        if ($severity !== '') {
            $paginationQuery['severity'] = $severity;
        }
        if ($search !== '') {
            $paginationQuery['search'] = $search;
        }

        $this->context->renderPanel('panel/logs', [
            'rows' => $rows,
            'filters' => ['severity' => $severity, 'search' => $search],
            'pagination' => $this->context->panelPaginationViewData('/logs', $pagination, $paginationQuery),
            'totalItems' => $totalItems,
            'loggingEnabled' => $this->logger()->isEnabled('error') || $this->logger()->isEnabled('warn') || $this->logger()->isEnabled('info'),
            'csrfField' => $this->context->csrfField(),
            'flashSuccess' => $this->context->pullFlash('success'),
            'flashError' => $this->context->pullFlash('error'),
            'section' => 'logs',
            'pageTitle' => 'Event Log',
            'canClear' => $this->context->auth()->hasPanelPermissionBit(PanelAccess::CONFIGURATION_DELETE),
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
        if (!$this->context->requireRoutePermissionOrForbidden('logs', 'view')) {
            return;
        }

        $severity = $this->input->text($_GET['severity'] ?? null, 10) ?? '';
        $severity = in_array($severity, ['error', 'warn', 'info'], true) ? $severity : '';
        $search = $this->input->text($_GET['search'] ?? null, 200) ?? '';

        $filters = [];
        if ($severity !== '') {
            $filters['severity'] = $severity;
        }
        if ($search !== '') {
            $filters['search'] = $search;
        }

        $rows = $this->logger()->allForExport($filters);
        $filename = 'event-log-' . gmdate('Ymd-His') . '.csv';
        $this->csvHandler()->streamToOutput(
            $filename,
            (static function (array $rows): \Generator {
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
        if (!$this->context->requireRoutePermissionOrForbidden('logs', 'delete')) {
            return;
        }

        $post = $_POST;
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
        if ($this->logger instanceof Logger) {
            return $this->logger;
        }

        $logger = ($this->loggerResolver)();
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
        if (!$this->csvHandler instanceof Csv) {
            $this->csvHandler = new Csv();
        }

        return $this->csvHandler;
    }
}
