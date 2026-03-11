<?php

/**
 * RAVEN CMS
 * ~/private/ext/signups/vis/panel_signups.php
 * Signup Sheets extension per-form submissions page template.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

/** @var array{name: string, slug: string, enabled: bool} $formData */
/** @var array<int, array{id: int, email: string, display_name: string, country: string, additional_fields_json: string, source_url: string, ip_address: string|null, hostname: string|null, user_agent: string|null, created_at: string}> $signups */
/** @var string $searchQuery */
/** @var array{current: int, total_pages: int, total_items: int, base_path: string} $pagination */
/** @var string $indexPath */
/** @var string $editPath */
/** @var string $deleteSignupPath */
/** @var string $clearSignupsPath */
/** @var string $importPath */
/** @var string $searchAction */
/** @var string $exportPath */
/** @var string|null $flashSuccess */
/** @var string|null $flashError */
/** @var string $csrfField */

use Raven\Core\Support\CountryOptions;
use function Raven\Core\Support\e;

$formName = (string) ($formData['name'] ?? '');
$formSlug = (string) ($formData['slug'] ?? '');
$searchQuery = isset($searchQuery) ? (string) $searchQuery : '';
$currentPage = max(1, (int) ($pagination['current'] ?? 1));
$totalPages = max(1, (int) ($pagination['total_pages'] ?? 1));
$totalItems = max(0, (int) ($pagination['total_items'] ?? 0));

$countryLabels = CountryOptions::list(true);

$basePaginationPath = (string) ($pagination['base_path'] ?? '');
if ($basePaginationPath === '') {
    $basePaginationPath = $searchAction;
}
$baseHasQuery = str_contains($basePaginationPath, '?');
$baseSeparator = $baseHasQuery ? '&' : '?';
$importModalId = 'signups-import-csv-modal';
$signupsSearchId = 'signups-filter-search';
$signupsTableId = 'signups-table';
$signupsBodyId = $signupsTableId . '-body';
$signupsCountId = 'signups-filter-count';
$signupsEmptyId = 'signups-filter-empty';
?>
<header class="card">
    <div class="card-body">
        <div class="d-flex align-items-start justify-content-between gap-2">
            <h1>Submissions for: <span class="text-primary">'<?= e($formName !== '' ? $formName : $formSlug) ?>'</span></h1>
            <div class="d-flex flex-wrap gap-2">
                <a href="<?= e($exportPath) ?>" class="btn btn-primary btn-sm"><i class="bi bi-download me-2" aria-hidden="true"></i>Export CSV</a>
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#<?= e($importModalId) ?>"><i class="bi bi-upload me-2" aria-hidden="true"></i>Import CSV</button>
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
    <a href="<?= e($indexPath) ?>" class="btn btn-secondary"><i class="bi bi-box-arrow-left me-2" aria-hidden="true"></i>Back to Signup Sheets</a>
    <form method="post" action="<?= e($clearSignupsPath) ?>" class="m-0" onsubmit="return confirm('Clear all submissions for this signup sheet?');">
        <?= $csrfField ?>
        <input type="hidden" name="slug" value="<?= e($formSlug) ?>">
        <input type="hidden" name="return_q" value="<?= e($searchQuery) ?>">
        <button type="submit" class="btn btn-danger"<?= $totalItems === 0 ? ' disabled' : '' ?>><i class="bi bi-trash3 me-2" aria-hidden="true"></i>Clear All</button>
    </form>
</nav>

<section class="card">
    <div class="card-body">
        <div class="row g-2 mb-3">
            <div class="col-12 col-lg-8">
                <label class="form-label mb-1" for="<?= e($signupsSearchId) ?>">Search</label>
                <input
                    id="<?= e($signupsSearchId) ?>"
                    type="search"
                    class="form-control form-control-sm"
                    placeholder="Filter by email, display name, country, or source URL..."
                    value="<?= e($searchQuery) ?>"
                >
            </div>
        </div>
        <div class="small text-muted mb-2" id="<?= e($signupsCountId) ?>"></div>

        <?php if ($signups === []): ?>
            <p class="text-muted mb-0">No submissions found for this signup sheet.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table id="<?= e($signupsTableId) ?>" class="table table-sm align-middle mb-0">
                    <thead>
                    <tr>
                        <th scope="col" class="text-center" style="width: 1%;"><span class="visually-hidden">State</span></th>
                        <th scope="col">Email</th>
                        <th scope="col">Display Name</th>
                        <th scope="col">Country</th>
                        <th scope="col">Submitted</th>
                        <th scope="col" class="text-center">Actions</th>
                    </tr>
                    </thead>
                    <tbody id="<?= e($signupsBodyId) ?>">
                    <?php foreach ($signups as $signup): ?>
                        <?php
                        $signupId = (int) ($signup['id'] ?? 0);
                        $detailsId = 'signups-submission-details-' . $signupId;
                        $countryCode = strtolower((string) ($signup['country'] ?? ''));
                        $countryLabel = $countryLabels[$countryCode] ?? strtoupper($countryCode);
                        $sourceUrl = trim((string) ($signup['source_url'] ?? ''));
                        $ipAddress = trim((string) ($signup['ip_address'] ?? ''));
                        $hostname = trim((string) ($signup['hostname'] ?? ''));
                        $hostnameTooltip = $hostname !== '' ? $hostname : 'Hostname not available.';
                        $signupEmail = trim((string) ($signup['email'] ?? ''));
                        $displayName = trim((string) ($signup['display_name'] ?? ''));

                        $additionalFieldsList = [];
                        $additionalSearchValues = [];
                        $rawAdditional = (string) ($signup['additional_fields_json'] ?? '');
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
                            $signupEmail,
                            $displayName,
                            $countryLabel,
                            strtoupper($countryCode),
                            $sourceUrl,
                            $ipAddress,
                            $hostname,
                            implode(' ', $additionalSearchValues),
                            (string) ($signup['created_at'] ?? ''),
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
                                <code
                                    role="button"
                                    tabindex="0"
                                    class="text-decoration-none"
                                    data-rvn-copy-email="1"
                                    data-copy-value="<?= e($signupEmail) ?>"
                                    data-bs-toggle="tooltip"
                                    data-bs-placement="top"
                                    title="Click to copy email"
                                    style="cursor: pointer;"
                                ><?= e($signupEmail) ?></code>
                            </td>
                            <td><?= e($displayName !== '' ? $displayName : '-') ?></td>
                            <td><?= e($countryLabel) ?></td>
                            <td><?= e((string) ($signup['created_at'] ?? '')) ?></td>
                            <td class="text-center">
                                <form method="post" action="<?= e($deleteSignupPath) ?>" class="d-inline m-0" onsubmit="return confirm('Delete this submission?');">
                                    <?= $csrfField ?>
                                    <input type="hidden" name="slug" value="<?= e($formSlug) ?>">
                                    <input type="hidden" name="signup_id" value="<?= (int) $signupId ?>">
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
                            <td colspan="6" class="p-0 border-0">
                                <div
                                    id="<?= e($detailsId) ?>"
                                    class="collapse js-signups-submission-details"
                                    data-bs-parent="#<?= e($signupsBodyId) ?>"
                                >
                                    <div class="p-3 mb-0 border-bottom">
                                        <div class="row g-3">
                                            <div class="col-12 col-xl-6">
                                                <h5>Signup Details</h5>
                                                <div class="small mb-1"><strong>Email:</strong> <?= e($signupEmail !== '' ? $signupEmail : '-') ?></div>
                                                <div class="small mb-1"><strong>Display Name:</strong> <?= e($displayName !== '' ? $displayName : '-') ?></div>
                                                <div class="small mb-3"><strong>Country:</strong> <?= e($countryLabel) ?></div>
                                                <?php if ($additionalFieldsList !== []): ?>
                                                    <h5>Additional Fields</h5>
                                                    <?php foreach ($additionalFieldsList as $field): ?>
                                                        <div class="small"><strong><?= e((string) $field['label']) ?>:</strong> <?= e((string) $field['value']) ?></div>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </div>
                                            <div class="col-12 col-xl-6">
                                                <h5>Submission Details</h5>
                                                <div class="small mb-1">
                                                    <strong>Source:</strong>
                                                    <?php if ($sourceUrl === ''): ?>
                                                        <span class="text-muted">-</span>
                                                    <?php else: ?>
                                                        <a href="<?= e($sourceUrl) ?>" target="_blank" rel="noreferrer noopener"><?= e($sourceUrl) ?></a>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="small mb-1">
                                                    <strong>IP Address:</strong>
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
                                                </div>
                                                <div class="small mb-1"><strong>Hostname:</strong> <?= e($hostname !== '' ? $hostname : '-') ?></div>
                                                <div class="small mb-0" style="word-break: break-word;"><strong>User Agent:</strong> <?= e(trim((string) ($signup['user_agent'] ?? '')) !== '' ? (string) $signup['user_agent'] : '-') ?></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p id="<?= e($signupsEmptyId) ?>" class="text-muted mb-0 mt-2 d-none">No submissions match the current filter.</p>

            <?php if ($totalPages > 1): ?>
                <nav class="mt-3" aria-label="Signup submissions pagination">
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
</section>

<nav>
    <a href="<?= e($editPath) ?>" class="btn btn-primary"><i class="bi bi-pencil me-2" aria-hidden="true"></i>Edit Form</a>
    <a href="<?= e($indexPath) ?>" class="btn btn-secondary"><i class="bi bi-box-arrow-left me-2" aria-hidden="true"></i>Back to Signup Sheets</a>
    <form method="post" action="<?= e($clearSignupsPath) ?>" class="m-0" onsubmit="return confirm('Clear all submissions for this signup sheet?');">
        <?= $csrfField ?>
        <input type="hidden" name="slug" value="<?= e($formSlug) ?>">
        <input type="hidden" name="return_q" value="<?= e($searchQuery) ?>">
        <button type="submit" class="btn btn-danger"<?= $totalItems === 0 ? ' disabled' : '' ?>><i class="bi bi-trash3 me-2" aria-hidden="true"></i>Clear All</button>
    </form>
</nav>

<style>
  #<?= e($signupsTableId) ?> tbody tr[data-details-row-for]:hover > td {
    background-color: transparent !important;
  }
</style>

<div class="modal fade" id="<?= e($importModalId) ?>" tabindex="-1" aria-labelledby="<?= e($importModalId) ?>-label" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <form method="post" action="<?= e($importPath) ?>" enctype="multipart/form-data">
                <div class="modal-header">
                    <h3 class="modal-title mb-0" id="<?= e($importModalId) ?>-label">Import CSV</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?= $csrfField ?>
                    <input type="hidden" name="slug" value="<?= e($formSlug) ?>">
                    <input type="hidden" name="return_q" value="<?= e($searchQuery) ?>">
                    <label for="import_csv_modal" class="form-label">CSV File</label>
                    <input id="import_csv_modal" type="file" name="import_csv" accept=".csv,text/csv" class="form-control" required>
                    <p class="small text-muted mt-2 mb-0">
                        Import supports Raven export CSV format and header-based CSV files with columns like
                        <code>email</code>, <code>display_name</code>, and <code>country</code>.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Import CSV</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
  (function () {
    var table = document.getElementById('<?= e($signupsTableId) ?>');
    var searchInput = document.getElementById('<?= e($signupsSearchId) ?>');
    var countLabel = document.getElementById('<?= e($signupsCountId) ?>');
    var emptyMessage = document.getElementById('<?= e($signupsEmptyId) ?>');
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
            var detailsPanel = detailsRow.querySelector('.js-signups-submission-details');
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
      countLabel.textContent = 'Showing <?= (int) count($signups) ?> of <?= (int) count($signups) ?> submissions on this page.';
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

        if (detailsPanel.getAttribute('data-rvn-collapse-sync') === '1') {
          return;
        }

        detailsPanel.setAttribute('data-rvn-collapse-sync', '1');
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
        if (
          target instanceof Element
          && (
            target.closest('a, button, input, select, textarea, label, form')
            || target.closest('[data-rvn-copy-email="1"]')
          )
        ) {
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

    function fallbackCopy(text) {
      var temporaryInput = document.createElement('textarea');
      temporaryInput.value = text;
      temporaryInput.setAttribute('readonly', 'readonly');
      temporaryInput.style.position = 'absolute';
      temporaryInput.style.left = '-9999px';
      document.body.appendChild(temporaryInput);
      temporaryInput.select();
      var copied = document.execCommand('copy');
      document.body.removeChild(temporaryInput);
      return copied;
    }

    function copyText(text) {
      if (!navigator.clipboard || typeof navigator.clipboard.writeText !== 'function') {
        return Promise.resolve(fallbackCopy(text));
      }

      return navigator.clipboard.writeText(text).then(function () {
        return true;
      }).catch(function () {
        return fallbackCopy(text);
      });
    }

    function bootstrapTooltipAvailable() {
      return !!(window.bootstrap && typeof window.bootstrap.Tooltip === 'function');
    }

    function showCopyFeedback(target, success) {
      if (!(target instanceof HTMLElement) || !bootstrapTooltipAvailable()) {
        return;
      }

      var tooltip = window.bootstrap.Tooltip.getOrCreateInstance(target, { trigger: 'manual' });
      var message = success ? 'Copied!' : 'Copy failed';
      target.setAttribute('data-bs-original-title', message);
      tooltip.show();
      window.setTimeout(function () {
        target.setAttribute('data-bs-original-title', 'Click to copy email');
        tooltip.hide();
      }, success ? 900 : 1400);
    }

    document.querySelectorAll('[data-rvn-copy-email="1"]').forEach(function (element) {
      if (!(element instanceof HTMLElement)) {
        return;
      }

      function triggerCopy() {
        var value = String(element.getAttribute('data-copy-value') || '').trim();
        if (value === '') {
          return;
        }

        copyText(value).then(function (copied) {
          showCopyFeedback(element, copied);
        });
      }

      element.addEventListener('click', function (event) {
        event.preventDefault();
        triggerCopy();
      });

      element.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' && event.key !== ' ') {
          return;
        }

        event.preventDefault();
        triggerCopy();
      });
    });

    if (bootstrapTooltipAvailable()) {
      var elements = document.querySelectorAll('[data-bs-toggle="tooltip"]');
      elements.forEach(function (element) {
        window.bootstrap.Tooltip.getOrCreateInstance(element);
      });
    }
  })();
</script>
