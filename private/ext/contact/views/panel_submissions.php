<?php

/**
 * RAVEN CMS
 * ~/private/ext/contact/views/panel_submissions.php
 * Contact Forms extension per-form submissions page template.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

/** @var array{name: string, slug: string, enabled: bool} $formData */
/** @var array<int, array{id: int, sender_name: string, sender_email: string, message_text: string, additional_fields_json: string, source_url: string, ip_address: string|null, hostname: string|null, user_agent: string|null, created_at: string}> $submissions */
/** @var string $searchQuery */
/** @var array{current: int, total_pages: int, total_items: int, base_path: string} $pagination */
/** @var string $indexPath */
/** @var string $editPath */
/** @var string $deleteSubmissionPath */
/** @var string $clearSubmissionsPath */
/** @var string $searchAction */
/** @var string $exportPath */
/** @var string|null $flashSuccess */
/** @var string|null $flashError */
/** @var string $csrfField */

use function Raven\Core\Support\e;

$formName = (string) ($formData['name'] ?? '');
$formSlug = (string) ($formData['slug'] ?? '');
$searchQuery = isset($searchQuery) ? (string) $searchQuery : '';
$currentPage = max(1, (int) ($pagination['current'] ?? 1));
$totalPages = max(1, (int) ($pagination['total_pages'] ?? 1));
$totalItems = max(0, (int) ($pagination['total_items'] ?? 0));

$basePaginationPath = (string) ($pagination['base_path'] ?? '');
if ($basePaginationPath === '') {
    $basePaginationPath = $searchAction;
}
$baseHasQuery = str_contains($basePaginationPath, '?');
$baseSeparator = $baseHasQuery ? '&' : '?';
$submissionsSearchId = 'contact-submissions-filter-search';
$submissionsTableId = 'contact-submissions-table';
$submissionsBodyId = $submissionsTableId . '-body';
$submissionsCountId = 'contact-submissions-filter-count';
$submissionsEmptyId = 'contact-submissions-filter-empty';
?>
<header class="card">
    <div class="card-body">
        <div class="d-flex align-items-start justify-content-between gap-2">
            <h1>Submissions for: "<?= e($formName !== '' ? $formName : $formSlug) ?>"</h1>
            <div class="d-flex flex-wrap gap-2">
                <a href="<?= e($exportPath) ?>" class="btn btn-primary btn-sm"><i class="bi bi-download me-2" aria-hidden="true"></i>Export CSV</a>
            </div>
        </div>
        <p class="text-muted mb-0">Slug <code><?= e($formSlug) ?></code> | Total <strong><?= (int) $totalItems ?></strong></p>
    </div>
</header>

<?php if ($flashSuccess !== null): ?>
<div class="alert alert-success" role="alert"><?= e($flashSuccess) ?></div>
<?php endif; ?>

<?php if ($flashError !== null): ?>
<div class="alert alert-danger" role="alert"><?= e($flashError) ?></div>
<?php endif; ?>

<nav>
    <a href="<?= e($editPath) ?>" class="btn btn-primary"><i class="bi bi-pencil me-2" aria-hidden="true"></i>Edit Form</a>
    <a href="<?= e($indexPath) ?>" class="btn btn-secondary"><i class="bi bi-box-arrow-left me-2" aria-hidden="true"></i>Back to Contact Forms</a>
    <form method="post" action="<?= e($clearSubmissionsPath) ?>" class="m-0" onsubmit="return confirm('Clear all submissions for this contact form?');">
        <?= $csrfField ?>
        <input type="hidden" name="slug" value="<?= e($formSlug) ?>">
        <input type="hidden" name="return_q" value="<?= e($searchQuery) ?>">
        <button type="submit" class="btn btn-danger"<?= $totalItems === 0 ? ' disabled' : '' ?>><i class="bi bi-trash3 me-2" aria-hidden="true"></i>Clear All</button>
    </form>
</nav>

<div class="card mb-3">
    <div class="card-body">
        <div class="row g-2 mb-3">
            <div class="col-12 col-lg-8">
                <label class="form-label h6 mb-1" for="<?= e($submissionsSearchId) ?>">Search</label>
                <input
                    id="<?= e($submissionsSearchId) ?>"
                    type="search"
                    class="form-control form-control-sm"
                    placeholder="Filter by sender, email, message, source URL, or IP..."
                    value="<?= e($searchQuery) ?>"
                >
            </div>
        </div>
        <div class="small text-muted mb-2" id="<?= e($submissionsCountId) ?>"></div>

        <?php if ($submissions === []): ?>
            <p class="text-muted mb-0">No submissions found for this contact form.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table id="<?= e($submissionsTableId) ?>" class="table table-sm align-middle mb-0">
                    <thead>
                    <tr>
                        <th scope="col" class="text-center" style="width: 1%;"><span class="visually-hidden">State</span></th>
                        <th scope="col">Sender</th>
                        <th scope="col">Email</th>
                        <th scope="col">Submitted</th>
                        <th scope="col" class="text-center">Actions</th>
                    </tr>
                    </thead>
                    <tbody id="<?= e($submissionsBodyId) ?>">
                    <?php foreach ($submissions as $submission): ?>
                        <?php
                        $submissionId = (int) ($submission['id'] ?? 0);
                        $detailsId = 'contact-submission-details-' . $submissionId;
                        $ipAddress = trim((string) ($submission['ip_address'] ?? ''));
                        $hostname = trim((string) ($submission['hostname'] ?? ''));
                        $sourceUrl = trim((string) ($submission['source_url'] ?? ''));
                        $senderName = trim((string) ($submission['sender_name'] ?? ''));
                        $senderEmail = trim((string) ($submission['sender_email'] ?? ''));
                        $messageText = trim((string) ($submission['message_text'] ?? ''));

                        $additionalFieldsList = [];
                        $additionalSearchValues = [];
                        $rawAdditional = (string) ($submission['additional_fields_json'] ?? '');
                        if ($rawAdditional !== '') {
                            /** @var mixed $decodedAdditional */
                            $decodedAdditional = json_decode($rawAdditional, true);
                            if (is_array($decodedAdditional)) {
                                foreach ($decodedAdditional as $field) {
                                    if (!is_array($field)) {
                                        continue;
                                    }

                                    $fieldLabel = trim((string) ($field['label'] ?? $field['name'] ?? ''));
                                    $fieldValue = trim((string) ($field['value'] ?? ''));
                                    if ($fieldLabel === '' || $fieldValue === '') {
                                        continue;
                                    }

                                    $additionalFieldsList[] = [
                                        'label' => $fieldLabel,
                                        'value' => $fieldValue,
                                    ];
                                    $additionalSearchValues[] = $fieldLabel . ' ' . $fieldValue;
                                }
                            }
                        }

                        $searchableText = implode(' ', [
                            $senderName,
                            $senderEmail,
                            $messageText,
                            $sourceUrl,
                            $ipAddress,
                            $hostname,
                            trim((string) ($submission['user_agent'] ?? '')),
                            implode(' ', $additionalSearchValues),
                            (string) ($submission['created_at'] ?? ''),
                        ]);
                        ?>
                        <tr
                            data-summary-row="1"
                            data-details-id="<?= e($detailsId) ?>"
                            data-filter-search="<?= e($searchableText) ?>"
                            tabindex="0"
                            role="button"
                            aria-expanded="false"
                            aria-controls="<?= e($detailsId) ?>"
                            style="cursor: pointer;"
                        >
                            <td class="text-center">
                                <i class="bi bi-chevron-down js-row-state-icon" aria-hidden="true"></i>
                            </td>
                            <td>
                                <strong><?= e($senderName !== '' ? $senderName : '-') ?></strong>
                            </td>
                            <td>
                                <?php if ($senderEmail === ''): ?>
                                    <span class="text-muted">-</span>
                                <?php else: ?>
                                    <code><?= e($senderEmail) ?></code>
                                <?php endif; ?>
                            </td>
                            <td><?= e((string) ($submission['created_at'] ?? '')) ?></td>
                            <td class="text-center">
                                <form method="post" action="<?= e($deleteSubmissionPath) ?>" class="d-inline m-0" onsubmit="return confirm('Delete this submission?');">
                                    <?= $csrfField ?>
                                    <input type="hidden" name="slug" value="<?= e($formSlug) ?>">
                                    <input type="hidden" name="submission_id" value="<?= (int) $submissionId ?>">
                                    <input type="hidden" name="return_q" value="<?= e($searchQuery) ?>">
                                    <input type="hidden" name="return_page" value="<?= (int) $currentPage ?>">
                                    <button type="submit" class="btn btn-danger btn-sm" title="Delete" aria-label="Delete">
                                        <i class="bi bi-trash3" aria-hidden="true"></i>
                                        <span class="visually-hidden">Delete</span>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <tr data-details-row-for="<?= e($detailsId) ?>">
                            <td colspan="5" class="p-0 border-0">
                                <div
                                    id="<?= e($detailsId) ?>"
                                    class="collapse js-contact-submission-details"
                                    data-bs-parent="#<?= e($submissionsBodyId) ?>"
                                >
                                    <div class="p-3 mb-0 border-bottom">
                                        <div class="row g-3">
                                            <div class="col-12 col-xl-6">
                                                <h6 class="mb-2">Full Message</h6>
                                                <?php if ($messageText === ''): ?>
                                                    <p class="text-muted mb-0">No message body provided.</p>
                                                <?php else: ?>
                                                    <div class="small mb-0" style="white-space: pre-wrap;"><?= e($messageText) ?></div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="col-12 col-xl-6">
                                                <h6 class="mb-2">Submission Details</h6>
                                                <div class="small mb-1">
                                                    <strong>Source:</strong>
                                                    <?php if ($sourceUrl === ''): ?>
                                                        <span class="text-muted">-</span>
                                                    <?php else: ?>
                                                        <a href="<?= e($sourceUrl) ?>" target="_blank" rel="noreferrer noopener"><?= e($sourceUrl) ?></a>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="small mb-1"><strong>IP Address:</strong> <?= e($ipAddress !== '' ? $ipAddress : '-') ?></div>
                                                <div class="small mb-1"><strong>Hostname:</strong> <?= e($hostname !== '' ? $hostname : '-') ?></div>
                                                <div class="small mb-0" style="word-break: break-word;"><strong>User Agent:</strong> <?= e(trim((string) ($submission['user_agent'] ?? '')) !== '' ? (string) $submission['user_agent'] : '-') ?></div>
                                            </div>
                                            <?php if ($additionalFieldsList !== []): ?>
                                                <div class="col-12">
                                                    <h6 class="mb-2">Additional Fields</h6>
                                                    <?php foreach ($additionalFieldsList as $field): ?>
                                                        <div class="small"><strong><?= e((string) $field['label']) ?>:</strong> <?= e((string) $field['value']) ?></div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p id="<?= e($submissionsEmptyId) ?>" class="text-muted mb-0 mt-2 d-none">No submissions match the current filter.</p>

            <?php if ($totalPages > 1): ?>
                <nav class="mt-3" aria-label="Contact submissions pagination">
                    <ul class="pagination pagination-sm mb-0 justify-content-end">
                        <?php for ($page = 1; $page <= $totalPages; $page++): ?>
                            <?php
                            $isActive = $page === $currentPage;
                            $pageUrl = $basePaginationPath;
                            if ($page > 1) {
                                $pageUrl .= $baseSeparator . 'page=' . $page;
                            }
                            ?>
                            <li class="page-item<?= $isActive ? ' active' : '' ?>">
                                <a class="page-link" href="<?= e($pageUrl) ?>"><?= (int) $page ?></a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<nav>
    <a href="<?= e($editPath) ?>" class="btn btn-primary"><i class="bi bi-pencil me-2" aria-hidden="true"></i>Edit Form</a>
    <a href="<?= e($indexPath) ?>" class="btn btn-secondary"><i class="bi bi-box-arrow-left me-2" aria-hidden="true"></i>Back to Contact Forms</a>
    <form method="post" action="<?= e($clearSubmissionsPath) ?>" class="m-0" onsubmit="return confirm('Clear all submissions for this contact form?');">
        <?= $csrfField ?>
        <input type="hidden" name="slug" value="<?= e($formSlug) ?>">
        <input type="hidden" name="return_q" value="<?= e($searchQuery) ?>">
        <button type="submit" class="btn btn-danger"<?= $totalItems === 0 ? ' disabled' : '' ?>><i class="bi bi-trash3 me-2" aria-hidden="true"></i>Clear All</button>
    </form>
</nav>

<style>
  #<?= e($submissionsTableId) ?> tbody tr[data-details-row-for]:hover > td {
    background-color: transparent !important;
  }
</style>

<script>
  (function () {
    var table = document.getElementById('<?= e($submissionsTableId) ?>');
    var searchInput = document.getElementById('<?= e($submissionsSearchId) ?>');
    var countLabel = document.getElementById('<?= e($submissionsCountId) ?>');
    var emptyMessage = document.getElementById('<?= e($submissionsEmptyId) ?>');
    var summaryRows = [];

    function hasBootstrapCollapse() {
      return !!(window.bootstrap && typeof window.bootstrap.Collapse === 'function');
    }

    function setRowState(row, isExpanded) {
      row.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
      var stateIcon = row.querySelector('.js-row-state-icon');
      if (stateIcon instanceof HTMLElement) {
        stateIcon.classList.toggle('bi-chevron-up', isExpanded);
        stateIcon.classList.toggle('bi-chevron-down', !isExpanded);
      }
    }

    function closeDetailsPanel(panel) {
      if (!(panel instanceof HTMLElement)) {
        return;
      }

      if (hasBootstrapCollapse()) {
        window.bootstrap.Collapse.getOrCreateInstance(panel, { toggle: false }).hide();
        return;
      }

      panel.classList.remove('show');
    }

    function normalize(value) {
      return String(value || '').toLowerCase().replace(/\s+/g, ' ').trim();
    }

    if (table instanceof HTMLTableElement && searchInput instanceof HTMLInputElement) {
      var rows = table.tBodies.length > 0 ? Array.prototype.slice.call(table.tBodies[0].rows) : [];
      summaryRows = rows.filter(function (row) {
        return row.getAttribute('data-summary-row') === '1';
      });
      var totalRows = summaryRows.length;

      var applyFilters = function () {
        var query = normalize(searchInput.value);
        var visibleRows = 0;

        summaryRows.forEach(function (row) {
          var haystack = normalize(row.getAttribute('data-filter-search') || row.textContent || '');
          var visible = query === '' || haystack.indexOf(query) !== -1;
          var detailsId = String(row.getAttribute('data-details-id') || '');
          var detailsRow = detailsId === '' ? null : table.querySelector('tr[data-details-row-for="' + detailsId + '"]');

          row.classList.toggle('d-none', !visible);
          if (detailsRow) {
            detailsRow.classList.toggle('d-none', !visible);
          }

          if (!visible && detailsRow) {
            var detailsPanel = detailsRow.querySelector('.js-contact-submission-details');
            closeDetailsPanel(detailsPanel);
            setRowState(row, false);
          }

          if (visible) {
            visibleRows += 1;
          }
        });

        if (countLabel) {
          countLabel.textContent = 'Showing ' + visibleRows + ' of ' + totalRows + ' submissions on this page.';
        }

        if (emptyMessage) {
          emptyMessage.classList.toggle('d-none', visibleRows > 0);
        }
      };

      searchInput.addEventListener('input', applyFilters);
      applyFilters();
    } else if (countLabel) {
      countLabel.textContent = 'Showing <?= (int) count($submissions) ?> of <?= (int) count($submissions) ?> submissions on this page.';
    }

    function bindBootstrapCollapseStateSync() {
      if (!hasBootstrapCollapse()) {
        return;
      }

      summaryRows.forEach(function (row) {
        var detailsId = String(row.getAttribute('data-details-id') || '');
        if (detailsId === '') {
          return;
        }

        var detailsPanel = document.getElementById(detailsId);
        if (!(detailsPanel instanceof HTMLElement)) {
          return;
        }

        if (detailsPanel.getAttribute('data-raven-collapse-sync') === '1') {
          return;
        }

        detailsPanel.setAttribute('data-raven-collapse-sync', '1');
        detailsPanel.addEventListener('show.bs.collapse', function () {
          setRowState(row, true);
        });
        detailsPanel.addEventListener('hide.bs.collapse', function () {
          setRowState(row, false);
        });
      });
    }

    summaryRows.forEach(function (row) {
      var detailsId = String(row.getAttribute('data-details-id') || '');
      if (detailsId === '') {
        return;
      }

      var detailsPanel = document.getElementById(detailsId);
      if (!(detailsPanel instanceof HTMLElement)) {
        return;
      }

      var togglePanel = function () {
        if (hasBootstrapCollapse()) {
          bindBootstrapCollapseStateSync();
          window.bootstrap.Collapse.getOrCreateInstance(detailsPanel, { toggle: false }).toggle();
          return;
        }

        detailsPanel.classList.toggle('show');
        setRowState(row, detailsPanel.classList.contains('show'));
      };

      row.addEventListener('click', function (event) {
        var target = event.target;
        if (target instanceof Element && target.closest('a, button, input, select, textarea, label, form')) {
          return;
        }
        togglePanel();
      });

      row.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' && event.key !== ' ') {
          return;
        }
        event.preventDefault();
        togglePanel();
      });

      setRowState(row, detailsPanel.classList.contains('show'));
    });

    bindBootstrapCollapseStateSync();
    window.addEventListener('load', bindBootstrapCollapseStateSync);
  })();
</script>
