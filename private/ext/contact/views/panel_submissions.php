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
$submissionsCountId = 'contact-submissions-filter-count';
$submissionsEmptyId = 'contact-submissions-filter-empty';
?>
<div class="card mb-3">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div>
                <h1 class="mb-1">Submissions for: "<?= e($formName !== '' ? $formName : $formSlug) ?>"</h1>
                <p class="text-muted mb-0">
                    Slug <code><?= e($formSlug) ?></code>
                    | Total <strong><?= (int) $totalItems ?></strong>
                </p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="<?= e($exportPath) ?>" class="btn btn-primary btn-sm"><i class="bi bi-download me-2" aria-hidden="true"></i>Export CSV</a>
            </div>
        </div>
    </div>
</div>

<?php if ($flashSuccess !== null): ?>
    <div class="alert alert-success" role="alert"><?= e($flashSuccess) ?></div>
<?php endif; ?>

<?php if ($flashError !== null): ?>
    <div class="alert alert-danger" role="alert"><?= e($flashError) ?></div>
<?php endif; ?>

<div class="d-flex justify-content-end flex-wrap gap-2 mb-3">
    <a href="<?= e($editPath) ?>" class="btn btn-primary"><i class="bi bi-pencil me-2" aria-hidden="true"></i>Edit Form</a>
    <a href="<?= e($indexPath) ?>" class="btn btn-secondary"><i class="bi bi-box-arrow-left me-2" aria-hidden="true"></i>Back to Contact Forms</a>
    <form method="post" action="<?= e($clearSubmissionsPath) ?>" class="m-0" onsubmit="return confirm('Clear all submissions for this contact form?');">
        <?= $csrfField ?>
        <input type="hidden" name="slug" value="<?= e($formSlug) ?>">
        <input type="hidden" name="return_q" value="<?= e($searchQuery) ?>">
        <button type="submit" class="btn btn-danger"<?= $totalItems === 0 ? ' disabled' : '' ?>><i class="bi bi-trash3 me-2" aria-hidden="true"></i>Clear All</button>
    </form>
</div>

<div class="card">
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
                        <th scope="col">Sender</th>
                        <th scope="col">Message</th>
                        <th scope="col">Additional</th>
                        <th scope="col">Source</th>
                        <th scope="col">IP Address</th>
                        <th scope="col">Submitted</th>
                        <th scope="col" class="text-center">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($submissions as $submission): ?>
                        <?php
                        $submissionId = (int) ($submission['id'] ?? 0);
                        $ipAddress = trim((string) ($submission['ip_address'] ?? ''));
                        $hostname = trim((string) ($submission['hostname'] ?? ''));
                        $hostnameTooltip = $hostname !== '' ? $hostname : 'Hostname not available.';
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
                            implode(' ', $additionalSearchValues),
                            (string) ($submission['created_at'] ?? ''),
                        ]);
                        ?>
                        <tr data-filter-search="<?= e($searchableText) ?>">
                            <td>
                                <div><strong><?= e($senderName) ?></strong></div>
                                <div><code><?= e($senderEmail) ?></code></div>
                            </td>
                            <td>
                                <?php if ($messageText === ''): ?>
                                    <span class="text-muted">-</span>
                                <?php else: ?>
                                    <div class="small" style="max-width: 320px; white-space: pre-wrap;"><?= e($messageText) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($additionalFieldsList === []): ?>
                                    <span class="text-muted">-</span>
                                <?php else: ?>
                                    <?php foreach ($additionalFieldsList as $field): ?>
                                        <div class="small"><strong><?= e((string) $field['label']) ?>:</strong> <?= e((string) $field['value']) ?></div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($sourceUrl === ''): ?>
                                    <span class="text-muted">-</span>
                                <?php else: ?>
                                    <a href="<?= e($sourceUrl) ?>" target="_blank" rel="noreferrer noopener">
                                        <?= e($sourceUrl) ?>
                                    </a>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($ipAddress === ''): ?>
                                    <span class="text-muted">-</span>
                                <?php else: ?>
                                    <span
                                        data-bs-toggle="tooltip"
                                        data-bs-placement="top"
                                        title="<?= e($hostnameTooltip) ?>"
                                        style="cursor: help;"
                                    ><?= e($ipAddress) ?></span>
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

<div class="d-flex justify-content-end flex-wrap gap-2 mt-3">
    <a href="<?= e($editPath) ?>" class="btn btn-primary"><i class="bi bi-pencil me-2" aria-hidden="true"></i>Edit Form</a>
    <a href="<?= e($indexPath) ?>" class="btn btn-secondary"><i class="bi bi-box-arrow-left me-2" aria-hidden="true"></i>Back to Contact Forms</a>
    <form method="post" action="<?= e($clearSubmissionsPath) ?>" class="m-0" onsubmit="return confirm('Clear all submissions for this contact form?');">
        <?= $csrfField ?>
        <input type="hidden" name="slug" value="<?= e($formSlug) ?>">
        <input type="hidden" name="return_q" value="<?= e($searchQuery) ?>">
        <button type="submit" class="btn btn-danger"<?= $totalItems === 0 ? ' disabled' : '' ?>><i class="bi bi-trash3 me-2" aria-hidden="true"></i>Clear All</button>
    </form>
</div>

<script>
  (function () {
    var table = document.getElementById('<?= e($submissionsTableId) ?>');
    var searchInput = document.getElementById('<?= e($submissionsSearchId) ?>');
    var countLabel = document.getElementById('<?= e($submissionsCountId) ?>');
    var emptyMessage = document.getElementById('<?= e($submissionsEmptyId) ?>');

    function normalize(value) {
      return String(value || '').toLowerCase().replace(/\s+/g, ' ').trim();
    }

    if (table instanceof HTMLTableElement && searchInput instanceof HTMLInputElement) {
      var rows = table.tBodies.length > 0 ? Array.prototype.slice.call(table.tBodies[0].rows) : [];
      var totalRows = rows.length;

      var applyFilters = function () {
        var query = normalize(searchInput.value);
        var visibleRows = 0;

        rows.forEach(function (row) {
          var haystack = normalize(row.getAttribute('data-filter-search') || row.textContent || '');
          var visible = query === '' || haystack.indexOf(query) !== -1;
          row.classList.toggle('d-none', !visible);
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

    if (!window.bootstrap || typeof window.bootstrap.Tooltip !== 'function') {
      return;
    }

    var elements = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    elements.forEach(function (element) {
      window.bootstrap.Tooltip.getOrCreateInstance(element);
    });
  })();
</script>
