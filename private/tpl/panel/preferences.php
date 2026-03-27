<?php

/**
 * RAVEN CMS
 * ~/private/tpl/panel/preferences.php
 * Admin panel view template for this screen.
 * Docs: https://raven.lanterns.io
 */

// Inline note: Template expects controller-provided data and keeps business logic out of views.

/** @var array<string, string> $site */
/** @var string $csrfField */
/** @var string|null $flashSuccess */
/** @var string|null $flashError */
/** @var array<string, mixed> $preferences */
/** @var string|null $loginIdentifierMode */
/** @var array<int, string> $themeOptions */
/** @var array<string, array{label: string, url_prefix: string}> $profileContactOptions */
/** @var array<string, string> $twoFactorTypeOptions */
/** @var string $avatarUploadLimitsNote */
/** @var int $bioMaxLength */

use function Raven\Core\Support\e;

$panelBase = '/' . trim($site['panel_path'], '/');
$loginIdentifierMode = strtolower(trim((string) ($loginIdentifierMode ?? 'email')));
if (!in_array($loginIdentifierMode, ['email', 'username'], true)) {
    $loginIdentifierMode = 'email';
}
$usernameRequiredForAuth = $loginIdentifierMode === 'username';
$avatarPath = isset($preferences['avatar_path']) && is_string($preferences['avatar_path'])
    ? $preferences['avatar_path']
    : null;
$coverImage = trim((string) ($preferences['cover_image'] ?? ''));
$avatarFilename = is_string($avatarPath) ? basename($avatarPath) : '';
$avatarBase = (string) pathinfo($avatarFilename, PATHINFO_FILENAME);
$avatarThumbFilename = $avatarBase !== '' ? $avatarBase . '_thumb.jpg' : $avatarFilename;
$avatarUrl = '/uploads/avatars/' . rawurlencode($avatarFilename);
$avatarThumbUrl = '/uploads/avatars/' . rawurlencode($avatarThumbFilename);
$profileContactOptions = is_array($profileContactOptions ?? null) ? $profileContactOptions : [];
$twoFactorTypeOptions = is_array($twoFactorTypeOptions ?? null) ? $twoFactorTypeOptions : [];
$contactProfilesRaw = is_array($preferences['contact_profiles'] ?? null) ? $preferences['contact_profiles'] : [];
$contactProfiles = [];
foreach ($contactProfilesRaw as $entry) {
    if (!is_array($entry)) {
        continue;
    }

    $type = strtolower(trim((string) ($entry['type'] ?? '')));
    $value = trim((string) ($entry['value'] ?? ''));
    if ($type === '' || $value === '') {
        continue;
    }

    if (!array_key_exists($type, $profileContactOptions)) {
        continue;
    }

    $contactProfiles[] = [
        'type' => $type,
        'value' => $value,
    ];
}
$requestedTab = strtolower((string) ($_GET['tab'] ?? ''));
$activeTab = in_array($requestedTab, ['account', 'profile', 'security'], true) ? $requestedTab : 'account';
$twoFactorMethodsRaw = is_array($preferences['two_factor_methods'] ?? null) ? $preferences['two_factor_methods'] : [];
$twoFactorMethods = [];
foreach ($twoFactorMethodsRaw as $methodRow) {
    if (!is_array($methodRow)) {
        continue;
    }

    $methodType = strtolower(trim((string) ($methodRow['type'] ?? '')));
    if (!in_array($methodType, ['totp', 'recovery', 'webauthn', 'email'], true)) {
        continue;
    }

    $twoFactorMethods[] = [
        'type' => $methodType,
        'label' => trim((string) ($methodRow['label'] ?? '')),
        'status' => strtolower(trim((string) ($methodRow['status'] ?? ''))),
        'secret' => trim((string) ($methodRow['secret'] ?? '')),
        'recovery_hash' => trim((string) ($methodRow['recovery_hash'] ?? '')),
        'reusable' => (bool) ($methodRow['reusable'] ?? false),
        'credential_id' => trim((string) ($methodRow['credential_id'] ?? '')),
        'credential_public_key' => trim((string) ($methodRow['credential_public_key'] ?? '')),
        'signature_counter' => (int) ($methodRow['signature_counter'] ?? 0),
        'require_uv' => (bool) ($methodRow['require_uv'] ?? false),
        'email' => trim((string) ($methodRow['email'] ?? '')),
        'provisioning_uri' => trim((string) ($methodRow['provisioning_uri'] ?? '')),
        'qr_data_uri' => trim((string) ($methodRow['qr_data_uri'] ?? '')),
    ];
}
usort($twoFactorMethods, static function (array $left, array $right): int {
    $leftLabel = strtolower(trim((string) ($left['label'] ?? '')));
    if ($leftLabel === '') {
        $leftLabel = strtolower(trim((string) ($left['type'] ?? '')));
    }

    $rightLabel = strtolower(trim((string) ($right['label'] ?? '')));
    if ($rightLabel === '') {
        $rightLabel = strtolower(trim((string) ($right['type'] ?? '')));
    }

    if ($leftLabel !== $rightLabel) {
        return $leftLabel <=> $rightLabel;
    }

    return strtolower((string) ($left['type'] ?? '')) <=> strtolower((string) ($right['type'] ?? ''));
});
$selectedTheme = strtolower(trim((string) ($preferences['theme'] ?? 'default')));
if (in_array($selectedTheme, ['light', 'raven'], true)) {
    $selectedTheme = 'corp';
} elseif ($selectedTheme === 'dark') {
    $selectedTheme = 'midnight';
}
if (!in_array($selectedTheme, ['default', 'corp', 'ice', 'midnight'], true)) {
    $selectedTheme = 'default';
}
$themeLabels = [
    'default' => '<Default>',
    'corp' => 'Corporate',
    'ice' => 'Ice',
    'midnight' => 'Midnight',
];
?>

<header class="card">
    <div class="card-body">
        <h1>Preferences</h1>
        <p class="text-muted mb-0">Manage your account details, panel theme, and avatar.</p>
    </div>
</header>

<?php if ($flashSuccess !== null): ?>
<div class="alert alert-success" role="alert"><?= e($flashSuccess) ?></div>
<?php endif; ?>

<template id="preferences-two-factor-template">
    <div
        class="border rounded p-2 mb-2"
        data-preferences-two-factor-row="1"
        data-preferences-two-factor-status=""
        data-preferences-totp-provisioning-uri=""
        data-preferences-totp-qr-data-uri=""
    >
        <div class="row g-2 align-items-end pb-4">
            <div class="col-md-2">
                <label class="form-label">Type</label>
                <select class="form-select form-select-sm" data-preferences-two-factor-key="type">
                    <?php foreach ($twoFactorTypeOptions as $typeValue => $typeLabel): ?>
                        <?php if ((string) $typeValue === 'none') { continue; } ?>
                        <option value="<?= e((string) $typeValue) ?>"><?= e((string) $typeLabel) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" data-preferences-two-factor-key="status" value="pending">
            </div>
            <div class="col-md-3" data-preferences-two-factor-section="label">
                <label class="form-label">Label</label>
                <input type="text" class="form-control form-control-sm" data-preferences-two-factor-key="label" placeholder="My Authenticator / Office Key">
            </div>
            <div class="col-md position-relative" data-preferences-two-factor-section="totp" style="display:none;">
                <label class="form-label" data-preferences-two-factor-totp-label="1">TOTP Secret / Confirm Code</label>
                <div class="input-group input-group-sm">
                    <input
                        type="text"
                        class="form-control form-control-sm"
                        data-preferences-two-factor-key="secret"
                        data-preferences-two-factor-secret-copy="1"
                        placeholder="Click 'Setup App'"
                        title="Click to copy"
                        autocomplete="off"
                        style="caret-color: transparent; cursor: pointer;"
                        readonly
                    >
                    <input type="text" class="form-control form-control-sm" data-preferences-two-factor-key="verification_code" placeholder="8-digit code">
                    <button type="button" class="btn btn-primary btn-sm" data-preferences-two-factor-totp-setup="1">Setup App</button>
                </div>
                <div class="small text-muted d-none position-absolute start-0 end-0" style="top:calc(100% + 0.2rem);" data-preferences-two-factor-totp-feedback="1"></div>
            </div>
            <div class="col-md position-relative" data-preferences-two-factor-section="webauthn" style="display:none;">
                <label class="form-label">Credential ID</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text">
                        <input class="form-check-input mt-0 me-2" type="checkbox" data-preferences-two-factor-key="require_uv" value="1" aria-label="Require PIN/Biometric?">
                        <span class="small">PIN/Bio</span>
                    </span>
                    <input type="text" class="form-control form-control-sm" data-preferences-two-factor-key="credential_id" placeholder="Pair a security key to populate this">
                    <button type="button" class="btn btn-primary btn-sm" data-preferences-two-factor-webauthn-register="1">Pair Security Key</button>
                </div>
                <div class="small d-none position-absolute start-0 end-0" style="top:calc(100% + 0.2rem);" data-preferences-two-factor-webauthn-feedback="1"></div>
                <input type="hidden" data-preferences-two-factor-key="credential_public_key" value="">
                <input type="hidden" data-preferences-two-factor-key="signature_counter" value="0">
            </div>
            <div class="col-md" data-preferences-two-factor-section="email" style="display:none;">
                <label class="form-label">Email Address</label>
                <input type="email" class="form-control form-control-sm" data-preferences-two-factor-key="target_email" placeholder="Defaults to account email if blank">
            </div>
                <div class="col-md position-relative" data-preferences-two-factor-section="recovery" style="display:none;">
                    <label class="form-label">Recovery Phrase</label>
                    <div class="input-group input-group-sm">
                    <span class="input-group-text">
                        <input
                            class="form-check-input mt-0 me-2"
                            type="checkbox"
                            data-preferences-two-factor-key="reusable"
                            value="1"
                            aria-label="Reusable"
                        >
                            <span class="small">Reusable</span>
                        </span>
                    <input
                        type="hidden"
                        data-preferences-two-factor-key="recovery_code"
                        value=""
                    >
                    <input
                        type="hidden"
                        data-preferences-two-factor-key="recovery_hash"
                        value=""
                    >
                    <input
                        type="text"
                        class="form-control form-control-sm"
                        data-preferences-two-factor-recovery-display="1"
                        data-preferences-two-factor-recovery-copy="1"
                        placeholder="Generate 12-word recovery phrase"
                        title="Click to copy"
                        autocomplete="off"
                        style="caret-color: transparent; cursor: pointer;"
                        readonly
                    >
                    <button
                        type="button"
                        class="btn btn-outline-secondary btn-sm"
                        data-preferences-two-factor-recovery-visibility="1"
                        title="Show recovery phrase"
                        aria-label="Show recovery phrase"
                    ><i class="bi bi-eye" aria-hidden="true" data-preferences-two-factor-recovery-visibility-icon="1"></i></button>
                    <button type="button" class="btn btn-primary btn-sm" data-preferences-two-factor-recovery-generate="1">Generate</button>
                </div>
                <div
                    class="small text-muted position-absolute start-0 end-0"
                    style="top:calc(100% + 0.2rem);"
                    data-preferences-two-factor-recovery-copy-hint="1"
                >Generate a phrase and click it to copy.</div>
            </div>
            <div class="col-auto ps-md-0 d-flex align-items-end">
                <button type="button" class="btn btn-danger btn-sm" data-preferences-two-factor-remove="1"><i class="bi bi-x-circle-fill" aria-hidden="true"></i></button>
            </div>
        </div>
    </div>
</template>
<div class="modal fade" id="preferences-totp-setup-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5">Authenticator App Setup</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <ol class="mb-3 ps-3">
                    <li>Scan the QR code with your authenticator app.</li>
                    <li>If scan is unavailable, enter the manual key.</li>
                    <li>Enter the app's 8-digit code below, then click Finish Setup.</li>
                </ol>

                <div class="text-center mb-3">
                    <img
                        src=""
                        alt="TOTP QR Code"
                        class="img-fluid border rounded p-2"
                        style="max-width: 220px;"
                        data-preferences-totp-modal-qr="1"
                    >
                    <div class="small text-muted mt-2" data-preferences-totp-modal-qr-empty="1" style="display:none;">
                        QR image is unavailable in this environment. Use the manual key below.
                    </div>
                </div>

                <div class="mb-2">
                    <label class="form-label">Manual Key</label>
                    <code
                        class="d-block small border rounded p-2 text-break"
                        style="cursor: copy;"
                        title="Click to copy"
                        role="button"
                        tabindex="0"
                        data-preferences-totp-modal-secret="1"
                    ></code>
                    <div class="small text-muted mt-1" data-preferences-totp-modal-secret-copy="1">Click to copy.</div>
                </div>

                <div class="mb-0">
                    <label class="form-label">Provisioning URI</label>
                    <code
                        class="d-block small border rounded p-2 text-break"
                        style="cursor: copy; max-height: 6.5rem; overflow: auto;"
                        title="Click to copy"
                        role="button"
                        tabindex="0"
                        data-preferences-totp-modal-uri="1"
                    ></code>
                    <div class="small text-muted mt-1" data-preferences-totp-modal-uri-copy="1">Click to copy.</div>
                </div>

                <form class="mt-3" data-preferences-totp-modal-verify-form="1" novalidate>
                    <label class="form-label" for="preferences_totp_modal_code">Authenticator Code</label>
                    <div class="input-group">
                        <input
                            id="preferences_totp_modal_code"
                            type="text"
                            class="form-control"
                            data-preferences-totp-modal-code="1"
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            pattern="[0-9]{8}"
                            placeholder="12345678"
                            required
                        >
                        <button type="submit" class="btn btn-primary">Finish Setup</button>
                    </div>
                    <div class="small d-none mt-2" data-preferences-totp-modal-feedback="1"></div>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    var list = document.getElementById('preferences-two-factor-methods-list');
    var addButton = document.getElementById('preferences-two-factor-add');
    var template = document.getElementById('preferences-two-factor-template');

    if (!(list instanceof HTMLElement) || !(addButton instanceof HTMLButtonElement) || !(template instanceof HTMLTemplateElement)) {
      return;
    }

    var panelBase = <?= json_encode($panelBase, JSON_UNESCAPED_SLASHES) ?>;
    var totpModalEl = document.getElementById('preferences-totp-setup-modal');
    var totpModalQr = totpModalEl instanceof HTMLElement ? totpModalEl.querySelector('[data-preferences-totp-modal-qr="1"]') : null;
    var totpModalQrEmpty = totpModalEl instanceof HTMLElement ? totpModalEl.querySelector('[data-preferences-totp-modal-qr-empty="1"]') : null;
    var totpModalSecret = totpModalEl instanceof HTMLElement ? totpModalEl.querySelector('[data-preferences-totp-modal-secret="1"]') : null;
    var totpModalUri = totpModalEl instanceof HTMLElement ? totpModalEl.querySelector('[data-preferences-totp-modal-uri="1"]') : null;
    var totpModalSecretCopy = totpModalEl instanceof HTMLElement ? totpModalEl.querySelector('[data-preferences-totp-modal-secret-copy="1"]') : null;
    var totpModalUriCopy = totpModalEl instanceof HTMLElement ? totpModalEl.querySelector('[data-preferences-totp-modal-uri-copy="1"]') : null;
    var totpModalVerifyForm = totpModalEl instanceof HTMLElement ? totpModalEl.querySelector('[data-preferences-totp-modal-verify-form="1"]') : null;
    var totpModalCode = totpModalEl instanceof HTMLElement ? totpModalEl.querySelector('[data-preferences-totp-modal-code="1"]') : null;
    var totpModalFeedback = totpModalEl instanceof HTMLElement ? totpModalEl.querySelector('[data-preferences-totp-modal-feedback="1"]') : null;
    var totpModalActiveRow = null;
    var totpModal = null;

    if (
      totpModalEl instanceof HTMLElement
      && typeof window.bootstrap !== 'undefined'
      && window.bootstrap
      && typeof window.bootstrap.Modal === 'function'
    ) {
      totpModal = new window.bootstrap.Modal(totpModalEl);
    }

    if (totpModalEl instanceof HTMLElement) {
      totpModalEl.addEventListener('hidden.bs.modal', function () {
        totpModalActiveRow = null;
        if (totpModalCode instanceof HTMLInputElement) {
          totpModalCode.value = '';
        }
        setTotpModalFeedback('', 'muted');
      });
    }

    function sectionVisible(row, sectionType, visible) {
      var section = row.querySelector('[data-preferences-two-factor-section="' + sectionType + '"]');
      if (!(section instanceof HTMLElement)) {
        return;
      }
      section.style.display = visible ? '' : 'none';
    }

    function setRowStatus(row, status) {
      if (!(row instanceof HTMLElement)) {
        return;
      }

      var normalized = String(status || '').trim().toLowerCase();
      if (normalized === '') {
        normalized = 'pending';
      }
      row.setAttribute('data-preferences-two-factor-status', normalized);

      var statusField = row.querySelector('[data-preferences-two-factor-key="status"]');
      if (statusField instanceof HTMLInputElement) {
        statusField.value = normalized;
      }
    }

    function labelPlaceholderForType(methodType) {
      if (methodType === 'totp') {
        return 'Authenticator App';
      }
      if (methodType === 'recovery') {
        return '';
      }
      if (methodType === 'webauthn') {
        return 'My Key';
      }
      if (methodType === 'email') {
        return 'My Email';
      }
      return '2FA Method Label';
    }

    function syncLabelPlaceholder(row, methodType) {
      var labelField = row.querySelector('[data-preferences-two-factor-key="label"]');
      if (!(labelField instanceof HTMLInputElement)) {
        return;
      }

      labelField.placeholder = labelPlaceholderForType(methodType);
    }

    function syncLabelVisibility(row, methodType) {
      var labelSection = row.querySelector('[data-preferences-two-factor-section="label"]');
      if (!(labelSection instanceof HTMLElement)) {
        return;
      }

      labelSection.style.display = methodType === 'recovery' ? 'none' : '';
    }

    function syncTotpSetupButton(row) {
      if (!(row instanceof HTMLElement)) {
        return;
      }

      var typeField = row.querySelector('[data-preferences-two-factor-key="type"]');
      var secretField = row.querySelector('[data-preferences-two-factor-key="secret"]');
      var setupButton = row.querySelector('[data-preferences-two-factor-totp-setup="1"]');
      if (!(typeField instanceof HTMLSelectElement) || !(setupButton instanceof HTMLButtonElement)) {
        return;
      }

      var methodType = String(typeField.value || '').trim().toLowerCase();
      var hasSecret = secretField instanceof HTMLInputElement
        ? String(secretField.value || '').trim() !== ''
        : false;
      var methodStatus = String(row.getAttribute('data-preferences-two-factor-status') || '').trim().toLowerCase();
      if (methodType !== 'totp') {
        setupButton.textContent = 'Setup App';
        return;
      }

      if (methodStatus === 'confirmed') {
        setupButton.textContent = 'Reset';
        return;
      }

      if (!hasSecret) {
        setupButton.textContent = 'Setup App';
        return;
      }

      setupButton.textContent = 'Finish Setup';
    }

    function syncTotpFields(row) {
      if (!(row instanceof HTMLElement)) {
        return;
      }

      var typeField = row.querySelector('[data-preferences-two-factor-key="type"]');
      var labelField = row.querySelector('[data-preferences-two-factor-totp-label="1"]');
      var secretField = row.querySelector('[data-preferences-two-factor-key="secret"]');
      var preservedSecretField = row.querySelector('[data-preferences-two-factor-secret-preserved="1"]');
      var verificationField = row.querySelector('[data-preferences-two-factor-key="verification_code"]');
      if (!(typeField instanceof HTMLSelectElement)) {
        return;
      }

      var methodType = String(typeField.value || '').trim().toLowerCase();
      var methodStatus = String(row.getAttribute('data-preferences-two-factor-status') || '').trim().toLowerCase();
      var hasSecret = secretField instanceof HTMLInputElement
        ? String(secretField.value || '').trim() !== ''
        : false;
      var isTotpConfirmed = methodType === 'totp' && methodStatus === 'confirmed';

      if (labelField instanceof HTMLElement) {
        labelField.textContent = isTotpConfirmed ? 'TOTP Secret' : 'TOTP Secret / Confirm Code';
      }

      if (verificationField instanceof HTMLInputElement) {
        verificationField.style.display = isTotpConfirmed ? 'none' : '';
        verificationField.disabled = isTotpConfirmed;
        if (isTotpConfirmed) {
          verificationField.value = '';
        }
      }

      if (secretField instanceof HTMLInputElement) {
        var secretName = String(secretField.getAttribute('name') || '').trim();
        if (secretName === '' && preservedSecretField instanceof HTMLInputElement) {
          secretName = String(preservedSecretField.getAttribute('name') || '').trim();
        }
        if (secretName === '') {
          var typeName = String(typeField.getAttribute('name') || '');
          if (typeName !== '') {
            secretName = typeName.replace(/\[type\]$/, '[secret]');
          }
        }

        if (isTotpConfirmed) {
          secretField.value = 'Stored securely on server';
          secretField.removeAttribute('name');
          secretField.removeAttribute('data-preferences-two-factor-secret-copy');
          secretField.removeAttribute('title');
          secretField.style.cursor = 'default';
          secretField.style.caretColor = 'auto';
          if (preservedSecretField instanceof HTMLInputElement && secretName !== '') {
            preservedSecretField.setAttribute('name', secretName);
          }
        } else {
          if (secretName !== '') {
            secretField.setAttribute('name', secretName);
          }
          secretField.setAttribute('data-preferences-two-factor-secret-copy', '1');
          secretField.setAttribute('title', 'Click to copy');
          secretField.style.cursor = 'pointer';
          secretField.style.caretColor = 'transparent';
          if (preservedSecretField instanceof HTMLInputElement) {
            preservedSecretField.removeAttribute('name');
          }
          if (!hasSecret) {
            secretField.value = '';
          }
        }
      }
    }

    function syncRowSections(row) {
      if (!(row instanceof HTMLElement)) {
        return;
      }

      var typeField = row.querySelector('[data-preferences-two-factor-key="type"]');
      if (!(typeField instanceof HTMLSelectElement)) {
        return;
      }

      var methodType = String(typeField.value || '').trim().toLowerCase();
      var methodStatus = String(row.getAttribute('data-preferences-two-factor-status') || '').trim().toLowerCase();
      if (methodType === 'totp') {
        methodStatus = methodStatus === 'confirmed' ? 'confirmed' : 'pending';
      } else if (methodType === 'webauthn') {
        methodStatus = methodStatus === 'confirmed' ? 'confirmed' : 'stub';
      } else if (methodType === 'recovery' || methodType === 'email') {
        methodStatus = 'confirmed';
      } else {
        methodStatus = 'pending';
      }
      setRowStatus(row, methodStatus);

      syncLabelPlaceholder(row, methodType);
      syncLabelVisibility(row, methodType);
      sectionVisible(row, 'totp', methodType === 'totp');
      sectionVisible(row, 'recovery', methodType === 'recovery');
      sectionVisible(row, 'webauthn', methodType === 'webauthn');
      sectionVisible(row, 'email', methodType === 'email');
      syncTotpSetupButton(row);
      syncTotpFields(row);
    }

    function reindexRows() {
      var rows = list.querySelectorAll('[data-preferences-two-factor-row="1"]');
      rows.forEach(function (row, index) {
        if (!(row instanceof HTMLElement)) {
          return;
        }

        var fields = row.querySelectorAll('[data-preferences-two-factor-key]');
        fields.forEach(function (field) {
          if (!(field instanceof HTMLInputElement) && !(field instanceof HTMLSelectElement)) {
            return;
          }
          var key = String(field.getAttribute('data-preferences-two-factor-key') || '').trim();
          if (key === '') {
            return;
          }
          field.name = 'two_factor_methods[' + index + '][' + key + ']';
        });

        syncRowSections(row);
        setRecoveryVisibility(row, false);
      });
    }

    function appendRow() {
      var fragment = template.content.cloneNode(true);
      list.appendChild(fragment);
      reindexRows();
    }

    function setTotpFeedback(row, message, level) {
      var feedback = row.querySelector('[data-preferences-two-factor-totp-feedback="1"]');
      if (!(feedback instanceof HTMLElement)) {
        return;
      }

      var text = String(message || '').trim();
      feedback.classList.remove('d-none', 'text-danger', 'text-success', 'text-muted');
      if (text === '') {
        feedback.textContent = '';
        feedback.classList.add('d-none');
        return;
      }

      if (level === 'error') {
        feedback.classList.add('text-danger');
      } else if (level === 'success') {
        feedback.classList.add('text-success');
      } else {
        feedback.classList.add('text-muted');
      }
      feedback.textContent = text;
    }

    function setTotpModalFeedback(message, level) {
      if (!(totpModalFeedback instanceof HTMLElement)) {
        return;
      }

      var text = String(message || '').trim();
      totpModalFeedback.classList.remove('d-none', 'text-danger', 'text-success', 'text-muted');
      if (text === '') {
        totpModalFeedback.textContent = '';
        totpModalFeedback.classList.add('d-none');
        return;
      }

      if (level === 'error') {
        totpModalFeedback.classList.add('text-danger');
      } else if (level === 'success') {
        totpModalFeedback.classList.add('text-success');
      } else {
        totpModalFeedback.classList.add('text-muted');
      }
      totpModalFeedback.textContent = text;
    }

    function showTotpSetupModal(payload, row) {
      var secret = String(payload && payload.secret ? payload.secret : '').trim();
      var provisioningUri = String(payload && payload.provisioning_uri ? payload.provisioning_uri : '').trim();
      var qrDataUri = String(payload && payload.qr_data_uri ? payload.qr_data_uri : '').trim();
      totpModalActiveRow = row instanceof HTMLElement ? row : null;

      if (totpModalCode instanceof HTMLInputElement) {
        totpModalCode.value = '';
      }
      setTotpModalFeedback('', 'muted');

      if (totpModalSecret instanceof HTMLElement) {
        totpModalSecret.textContent = secret;
      }
      if (totpModalUri instanceof HTMLElement) {
        totpModalUri.textContent = provisioningUri;
      }
      if (totpModalSecretCopy instanceof HTMLElement) {
        totpModalSecretCopy.textContent = 'Click to copy.';
      }
      if (totpModalUriCopy instanceof HTMLElement) {
        totpModalUriCopy.textContent = 'Click to copy.';
      }

      if (totpModalQr instanceof HTMLImageElement) {
        if (qrDataUri !== '') {
          totpModalQr.src = qrDataUri;
          totpModalQr.style.display = '';
        } else {
          totpModalQr.removeAttribute('src');
          totpModalQr.style.display = 'none';
        }
      }

      if (totpModalQrEmpty instanceof HTMLElement) {
        totpModalQrEmpty.style.display = qrDataUri === '' ? '' : 'none';
      }

      if (totpModal !== null) {
        totpModal.show();
        return;
      }

      window.alert(
        'Manual setup key: ' + secret + '\n\nProvisioning URI:\n' + provisioningUri
      );
    }

    async function copyTextValue(value) {
      var text = String(value || '').trim();
      if (text === '') {
        return false;
      }

      if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
        try {
          await navigator.clipboard.writeText(text);
          return true;
        } catch (error) {
          // Fall through to legacy copy fallback when clipboard API is unavailable/blocked.
        }
      }

      var fallback = document.createElement('textarea');
      fallback.value = text;
      fallback.setAttribute('readonly', 'readonly');
      fallback.style.position = 'absolute';
      fallback.style.left = '-9999px';
      document.body.appendChild(fallback);
      fallback.select();
      var copied = false;
      try {
        copied = document.execCommand('copy');
      } finally {
        document.body.removeChild(fallback);
      }
      return copied;
    }

    function showCopyTooltip(element, copied) {
      if (!(element instanceof HTMLElement)) {
        return;
      }

      var originalTitle = String(element.getAttribute('data-copy-title') || element.getAttribute('title') || 'Click to copy');
      element.setAttribute('data-copy-title', originalTitle);
      var message = copied ? 'Copied!' : 'Copy failed';

      if (!window.bootstrap || typeof window.bootstrap.Tooltip !== 'function') {
        return;
      }

      var tooltip = window.bootstrap.Tooltip.getOrCreateInstance(element, {
        trigger: 'manual',
        placement: 'top',
        title: originalTitle
      });
      if (typeof tooltip.setContent === 'function') {
        tooltip.setContent({ '.tooltip-inner': message });
      } else {
        element.setAttribute('data-bs-original-title', message);
      }
      tooltip.show();

      window.setTimeout(function () {
        if (typeof tooltip.setContent === 'function') {
          tooltip.setContent({ '.tooltip-inner': originalTitle });
        } else {
          element.setAttribute('data-bs-original-title', originalTitle);
        }
        tooltip.hide();
      }, 900);
    }

    async function copyTotpModalField(key) {
      var source = null;
      if (key === 'secret') {
        source = totpModalSecret instanceof HTMLElement ? totpModalSecret : null;
      } else if (key === 'uri') {
        source = totpModalUri instanceof HTMLElement ? totpModalUri : null;
      }

      if (!(source instanceof HTMLElement)) {
        return;
      }

      var copied = false;
      try {
        copied = await copyTextValue(source.textContent || '');
      } catch (error) {
        copied = false;
      }

      showCopyTooltip(source, copied);
    }

    function recursiveBinaryStringToArrayBuffer(obj) {
      var prefix = '=?BINARY?B?';
      var suffix = '?=';
      if (!obj || typeof obj !== 'object') {
        return;
      }

      Object.keys(obj).forEach(function (key) {
        var value = obj[key];
        if (typeof value === 'string') {
          if (value.slice(0, prefix.length) !== prefix || value.slice(-suffix.length) !== suffix) {
            return;
          }

          var base64Value = value.slice(prefix.length, value.length - suffix.length);
          var binaryString = window.atob(base64Value);
          var bytes = new Uint8Array(binaryString.length);
          for (var index = 0; index < binaryString.length; index += 1) {
            bytes[index] = binaryString.charCodeAt(index);
          }
          obj[key] = bytes.buffer;
          return;
        }

        recursiveBinaryStringToArrayBuffer(value);
      });
    }

    function arrayBufferToBase64(buffer) {
      var bytes = new Uint8Array(buffer);
      var binary = '';
      for (var index = 0; index < bytes.length; index += 1) {
        binary += String.fromCharCode(bytes[index]);
      }
      return window.btoa(binary);
    }

    function csrfTokenValue() {
      var csrfInput = document.querySelector('input[name="_csrf"]');
      return csrfInput instanceof HTMLInputElement ? String(csrfInput.value || '') : '';
    }

    async function setupTotpForRow(row, button) {
      if (!(row instanceof HTMLElement) || !(button instanceof HTMLButtonElement)) {
        return;
      }

      var csrf = csrfTokenValue();
      if (csrf === '') {
        setTotpFeedback(row, 'Security token is missing. Refresh and try again.', 'error');
        return;
      }

      var secretField = row.querySelector('[data-preferences-two-factor-key="secret"]');
      var secret = secretField instanceof HTMLInputElement
        ? String(secretField.value || '').toUpperCase().replace(/[^A-Z2-7]/g, '')
        : '';
      var methodStatus = String(row.getAttribute('data-preferences-two-factor-status') || '').trim().toLowerCase();
      var hasSecret = secret !== '';
      var isReset = hasSecret && methodStatus === 'confirmed';

      button.disabled = true;
      setTotpFeedback(
        row,
        isReset
          ? 'Resetting secret...'
          : (hasSecret ? 'Loading setup details...' : 'Generating setup details...'),
        'muted'
      );

      try {
        var setupForm = new URLSearchParams();
        setupForm.append('_csrf', csrf);
        if (secret !== '' && !isReset) {
          setupForm.append('secret', secret);
        }

        var setupResponse = await window.fetch(panelBase + '/preferences/2fa/totp/setup', {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: setupForm.toString()
        });
        var setupPayload = await setupResponse.json();
        if (!setupResponse.ok || !setupPayload || setupPayload.ok !== true) {
          throw new Error(setupPayload && setupPayload.message ? setupPayload.message : 'Unable to prepare TOTP setup.');
        }

        var resolvedSecret = String(setupPayload.secret || '').trim();
        if (secretField instanceof HTMLInputElement && resolvedSecret !== '') {
          secretField.value = resolvedSecret;
        }
        if (resolvedSecret !== '') {
          setRowStatus(row, 'pending');
        }
        syncTotpSetupButton(row);
        syncTotpFields(row);

        row.setAttribute('data-preferences-totp-provisioning-uri', String(setupPayload.provisioning_uri || ''));
        row.setAttribute('data-preferences-totp-qr-data-uri', String(setupPayload.qr_data_uri || ''));
        showTotpSetupModal(setupPayload, row);
        setTotpFeedback(
          row,
          isReset
            ? 'Secret reset. Enter the app code and click Finish Setup.'
            : 'Setup ready. Enter the app code and click Finish Setup.',
          'success'
        );
      } catch (error) {
        setTotpFeedback(
          row,
          error && typeof error.message === 'string' ? error.message : 'Unable to prepare TOTP setup.',
          'error'
        );
      } finally {
        button.disabled = false;
      }
    }

    function finishTotpSetupForRow(row, button) {
      if (!(row instanceof HTMLElement)) {
        return;
      }

      var actionButton = button instanceof HTMLButtonElement ? button : null;
      var typeField = row.querySelector('[data-preferences-two-factor-key="type"]');
      if (!(typeField instanceof HTMLSelectElement) || String(typeField.value || '').trim().toLowerCase() !== 'totp') {
        return;
      }

      var verificationField = row.querySelector('[data-preferences-two-factor-key="verification_code"]');
      if (!(verificationField instanceof HTMLInputElement)) {
        setTotpFeedback(row, 'TOTP verification code field is missing.', 'error');
        return;
      }

      var verificationCode = String(verificationField.value || '').replace(/\D+/g, '');
      if (verificationCode.length !== 8) {
        setTotpFeedback(row, 'Enter the 8-digit app code to finish setup.', 'error');
        return;
      }

      verificationField.value = verificationCode;
      setTotpFeedback(row, 'Finishing setup...', 'muted');
      if (actionButton instanceof HTMLButtonElement) {
        actionButton.disabled = true;
      }

      var form = row.closest('form');
      if (!(form instanceof HTMLFormElement)) {
        if (actionButton instanceof HTMLButtonElement) {
          actionButton.disabled = false;
        }
        setTotpFeedback(row, 'Unable to submit preferences form.', 'error');
        return;
      }

      if (typeof form.requestSubmit === 'function') {
        form.requestSubmit();
        return;
      }

      form.submit();
    }

    if (totpModalVerifyForm instanceof HTMLFormElement) {
      totpModalVerifyForm.addEventListener('submit', function (event) {
        event.preventDefault();

        if (!(totpModalActiveRow instanceof HTMLElement)) {
          setTotpModalFeedback('Setup row is unavailable. Close this dialog and try Setup App again.', 'error');
          return;
        }

        if (!(totpModalCode instanceof HTMLInputElement)) {
          setTotpModalFeedback('Authenticator code field is unavailable.', 'error');
          return;
        }

        var code = String(totpModalCode.value || '').replace(/\D+/g, '');
        if (code.length !== 8) {
          setTotpModalFeedback('Enter the 8-digit app code to finish setup.', 'error');
          return;
        }

        var rowVerificationField = totpModalActiveRow.querySelector('[data-preferences-two-factor-key="verification_code"]');
        if (!(rowVerificationField instanceof HTMLInputElement)) {
          setTotpModalFeedback('Verification field is unavailable for this method row.', 'error');
          return;
        }

        rowVerificationField.value = code;
        setTotpModalFeedback('Finishing setup...', 'muted');
        var activeRow = totpModalActiveRow;
        if (totpModal !== null) {
          totpModal.hide();
        }
        finishTotpSetupForRow(activeRow, null);
      });
    }

    function recoveryCopyHintElement(row) {
      var hint = row.querySelector('[data-preferences-two-factor-recovery-copy-hint="1"]');
      return hint instanceof HTMLElement ? hint : null;
    }

    function setRecoveryCopyHint(row, message) {
      var hint = recoveryCopyHintElement(row);
      if (!(hint instanceof HTMLElement)) {
        return;
      }
      hint.textContent = String(message || '').trim() || 'Generate a phrase and click it to copy.';
    }

    function recoveryMaskedValue(actualValue) {
      var actual = String(actualValue || '');
      if (actual === '') {
        return '';
      }

      return actual.replace(/\S/g, '*');
    }

    function recoveryHiddenField(row) {
      var field = row.querySelector('[data-preferences-two-factor-key="recovery_code"]');
      return field instanceof HTMLInputElement ? field : null;
    }

    function recoveryHashField(row) {
      var field = row.querySelector('[data-preferences-two-factor-key="recovery_hash"]');
      return field instanceof HTMLInputElement ? field : null;
    }

    function recoveryDisplayField(row) {
      var field = row.querySelector('[data-preferences-two-factor-recovery-display="1"]');
      return field instanceof HTMLInputElement ? field : null;
    }

    function setRecoveryVisibility(row, visible) {
      if (!(row instanceof HTMLElement)) {
        return;
      }

      var hiddenField = recoveryHiddenField(row);
      var hashField = recoveryHashField(row);
      var displayField = recoveryDisplayField(row);
      var button = row.querySelector('[data-preferences-two-factor-recovery-visibility="1"]');
      if (
        !(hiddenField instanceof HTMLInputElement)
        || !(hashField instanceof HTMLInputElement)
        || !(displayField instanceof HTMLInputElement)
        || !(button instanceof HTMLButtonElement)
      ) {
        return;
      }

      var shouldShow = visible === true;
      var actualValue = String(hiddenField.value || '');
      var storedHash = String(hashField.value || '').trim();
      var hasStoredHash = storedHash !== '' && actualValue.trim() === '';
      if (hasStoredHash) {
        displayField.value = 'Stored securely on server';
        displayField.title = 'Stored securely';
        displayField.style.caretColor = 'auto';
        displayField.style.cursor = 'default';
        button.style.display = 'none';
        setRecoveryCopyHint(row, 'Phrase is stored as a one-way hash. Generate a new phrase to replace it.');
        row.setAttribute('data-preferences-two-factor-recovery-visible', '0');
        return;
      }

      button.style.display = '';
      displayField.style.caretColor = 'transparent';
      displayField.style.cursor = 'pointer';
      displayField.title = actualValue !== '' ? 'Click to copy' : 'Generate a recovery phrase first';
      displayField.value = shouldShow ? actualValue : recoveryMaskedValue(actualValue);
      row.setAttribute('data-preferences-two-factor-recovery-visible', shouldShow ? '1' : '0');
      button.title = shouldShow ? 'Hide recovery phrase' : 'Show recovery phrase';
      button.setAttribute('aria-label', shouldShow ? 'Hide recovery phrase' : 'Show recovery phrase');
      setRecoveryCopyHint(row, actualValue !== '' ? 'Click phrase to copy.' : 'Generate a phrase and click it to copy.');

      var icon = button.querySelector('[data-preferences-two-factor-recovery-visibility-icon="1"]');
      if (icon instanceof HTMLElement) {
        icon.classList.remove('bi-eye', 'bi-eye-slash');
        icon.classList.add(shouldShow ? 'bi-eye-slash' : 'bi-eye');
      }
    }

    function toggleRecoveryVisibility(row) {
      if (!(row instanceof HTMLElement)) {
        return;
      }

      var currentlyVisible = String(row.getAttribute('data-preferences-two-factor-recovery-visible') || '') === '1';
      var hiddenField = recoveryHiddenField(row);
      var hashField = recoveryHashField(row);
      if (!(hiddenField instanceof HTMLInputElement) || !(hashField instanceof HTMLInputElement)) {
        return;
      }
      if (String(hashField.value || '').trim() !== '' && String(hiddenField.value || '').trim() === '') {
        return;
      }

      setRecoveryVisibility(row, !currentlyVisible);
    }

    async function generateRecoveryForRow(row, button) {
      if (!(row instanceof HTMLElement) || !(button instanceof HTMLButtonElement)) {
        return;
      }

      var csrf = csrfTokenValue();
      if (csrf === '') {
        setRecoveryCopyHint(row, 'Security token missing.');
        return;
      }

      var hiddenField = recoveryHiddenField(row);
      var hashField = recoveryHashField(row);
      if (!(hiddenField instanceof HTMLInputElement) || !(hashField instanceof HTMLInputElement)) {
        return;
      }

      button.disabled = true;
      setRecoveryCopyHint(row, 'Generating recovery phrase...');
      try {
        var response = await window.fetch(panelBase + '/preferences/2fa/recovery/generate', {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: new URLSearchParams({ _csrf: csrf }).toString()
        });
        var payload = await response.json();
        if (!response.ok || !payload || payload.ok !== true) {
          throw new Error(payload && payload.message ? payload.message : 'Unable to generate recovery phrase.');
        }

        hiddenField.value = String(payload.recovery_code || '');
        hashField.value = '';
        setRecoveryVisibility(row, false);
        setRecoveryCopyHint(row, 'Generated. Click phrase to copy.');
      } catch (error) {
        setRecoveryCopyHint(
          row,
          error && typeof error.message === 'string' ? error.message : 'Unable to generate recovery phrase.'
        );
      } finally {
        button.disabled = false;
      }
    }

    function collectCredentialIds() {
      var ids = [];
      var rows = list.querySelectorAll('[data-preferences-two-factor-row="1"]');
      rows.forEach(function (row) {
        if (!(row instanceof HTMLElement)) {
          return;
        }

        var typeField = row.querySelector('[data-preferences-two-factor-key="type"]');
        if (!(typeField instanceof HTMLSelectElement) || String(typeField.value || '').trim().toLowerCase() !== 'webauthn') {
          return;
        }

        var credentialIdField = row.querySelector('[data-preferences-two-factor-key="credential_id"]');
        if (!(credentialIdField instanceof HTMLInputElement)) {
          return;
        }

        var credentialId = String(credentialIdField.value || '').trim();
        if (credentialId !== '') {
          ids.push(credentialId);
        }
      });

      return ids;
    }

    function setWebauthnFeedback(row, message, level) {
      var feedback = row.querySelector('[data-preferences-two-factor-webauthn-feedback="1"]');
      if (!(feedback instanceof HTMLElement)) {
        return;
      }

      var text = String(message || '').trim();
      if (text === '') {
        feedback.textContent = '';
        feedback.classList.add('d-none');
        feedback.classList.remove('text-success', 'text-danger', 'text-muted');
        return;
      }

      feedback.classList.remove('d-none', 'text-success', 'text-danger', 'text-muted');
      if (level === 'error') {
        feedback.classList.add('text-danger');
      } else if (level === 'success') {
        feedback.classList.add('text-success');
      } else {
        feedback.classList.add('text-muted');
      }
      feedback.textContent = text;
    }

    async function pairWebauthnForRow(row, button) {
      if (!(row instanceof HTMLElement) || !(button instanceof HTMLButtonElement)) {
        return;
      }

      if (
        typeof window.PublicKeyCredential === 'undefined'
        || !navigator.credentials
        || typeof navigator.credentials.create !== 'function'
      ) {
        setWebauthnFeedback(row, 'This browser does not support WebAuthn registration.', 'error');
        return;
      }

      var csrf = csrfTokenValue();
      if (csrf === '') {
        setWebauthnFeedback(row, 'Security token is missing. Refresh and try again.', 'error');
        return;
      }

      button.disabled = true;
      setWebauthnFeedback(row, 'Waiting for security key registration...', 'muted');

      try {
        var optionsForm = new URLSearchParams();
        optionsForm.append('_csrf', csrf);
        var requireUvField = row.querySelector('[data-preferences-two-factor-key="require_uv"]');
        var requireUv = requireUvField instanceof HTMLInputElement && requireUvField.checked;
        optionsForm.append('require_user_verification', requireUv ? '1' : '0');
        collectCredentialIds().forEach(function (credentialId) {
          optionsForm.append('exclude_credential_ids[]', credentialId);
        });

        var optionsResponse = await window.fetch(panelBase + '/preferences/2fa/webauthn/options', {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: optionsForm.toString()
        });
        var optionsPayload = await optionsResponse.json();
        if (!optionsResponse.ok || !optionsPayload || optionsPayload.ok !== true || !optionsPayload.options) {
          throw new Error(optionsPayload && optionsPayload.message ? optionsPayload.message : 'Unable to start security key registration.');
        }

        recursiveBinaryStringToArrayBuffer(optionsPayload);
        var credential = await navigator.credentials.create(optionsPayload.options);
        if (!(credential instanceof PublicKeyCredential)) {
          throw new Error('Security key registration response was invalid.');
        }

        var registerForm = new URLSearchParams();
        registerForm.append('_csrf', csrf);
        registerForm.append(
          'clientDataJSON',
          credential.response && credential.response.clientDataJSON
            ? arrayBufferToBase64(credential.response.clientDataJSON)
            : ''
        );
        registerForm.append(
          'attestationObject',
          credential.response && credential.response.attestationObject
            ? arrayBufferToBase64(credential.response.attestationObject)
            : ''
        );

        var registerResponse = await window.fetch(panelBase + '/preferences/2fa/webauthn/register', {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: registerForm.toString()
        });
        var registerPayload = await registerResponse.json();
        if (!registerResponse.ok || !registerPayload || registerPayload.ok !== true) {
          throw new Error(registerPayload && registerPayload.message ? registerPayload.message : 'Security key registration failed.');
        }

        var credentialIdField = row.querySelector('[data-preferences-two-factor-key="credential_id"]');
        var credentialPublicKeyField = row.querySelector('[data-preferences-two-factor-key="credential_public_key"]');
        var signatureCounterField = row.querySelector('[data-preferences-two-factor-key="signature_counter"]');

        if (credentialIdField instanceof HTMLInputElement) {
          credentialIdField.value = String(registerPayload.credential_id || '');
        }
        if (credentialPublicKeyField instanceof HTMLInputElement) {
          credentialPublicKeyField.value = String(registerPayload.credential_public_key || '');
        }
        if (signatureCounterField instanceof HTMLInputElement) {
          signatureCounterField.value = String(registerPayload.signature_counter || 0);
        }

        setWebauthnFeedback(row, 'Security key paired. Save preferences to persist it.', 'success');
      } catch (error) {
        setWebauthnFeedback(
          row,
          error && typeof error.message === 'string' ? error.message : 'Security key registration failed.',
          'error'
        );
      } finally {
        button.disabled = false;
      }
    }

    addButton.addEventListener('click', function () {
      appendRow();
    });

    if (totpModalEl instanceof HTMLElement) {
      totpModalEl.addEventListener('click', function (event) {
        var target = event.target;
        if (!(target instanceof Element)) {
          return;
        }

        var secretBlock = target.closest('[data-preferences-totp-modal-secret="1"]');
        if (secretBlock instanceof HTMLElement) {
          void copyTotpModalField('secret');
          return;
        }

        var uriBlock = target.closest('[data-preferences-totp-modal-uri="1"]');
        if (uriBlock instanceof HTMLElement) {
          void copyTotpModalField('uri');
        }
      });

      totpModalEl.addEventListener('keydown', function (event) {
        var target = event.target;
        if (!(target instanceof Element)) {
          return;
        }

        if (event.key !== 'Enter' && event.key !== ' ') {
          return;
        }

        var secretBlock = target.closest('[data-preferences-totp-modal-secret="1"]');
        if (secretBlock instanceof HTMLElement) {
          event.preventDefault();
          void copyTotpModalField('secret');
          return;
        }

        var uriBlock = target.closest('[data-preferences-totp-modal-uri="1"]');
        if (uriBlock instanceof HTMLElement) {
          event.preventDefault();
          void copyTotpModalField('uri');
        }
      });
    }

    list.addEventListener('change', function (event) {
      var target = event.target;
      if (!(target instanceof HTMLElement)) {
        return;
      }

      var row = target.closest('[data-preferences-two-factor-row="1"]');
      if (!(row instanceof HTMLElement)) {
        return;
      }

      if (target instanceof HTMLSelectElement && target.getAttribute('data-preferences-two-factor-key') === 'type') {
        syncRowSections(row);
        var selectedType = String(target.value || '').trim().toLowerCase();
        if (selectedType === 'recovery') {
          var recoveryField = recoveryHiddenField(row);
          var recoveryHash = recoveryHashField(row);
          var generateButton = row.querySelector('[data-preferences-two-factor-recovery-generate="1"]');
          if (
            recoveryField instanceof HTMLInputElement
            && recoveryHash instanceof HTMLInputElement
            && generateButton instanceof HTMLButtonElement
            && String(recoveryField.value || '').trim() === ''
            && String(recoveryHash.value || '').trim() === ''
          ) {
            void generateRecoveryForRow(row, generateButton);
          }
        }
      }

    });

    list.addEventListener('click', function (event) {
      var target = event.target;
      if (!(target instanceof Element)) {
        return;
      }

      var totpSetupButton = target.closest('[data-preferences-two-factor-totp-setup="1"]');
      if (totpSetupButton instanceof HTMLButtonElement) {
        var totpRow = totpSetupButton.closest('[data-preferences-two-factor-row="1"]');
        if (totpRow instanceof HTMLElement) {
          var typeField = totpRow.querySelector('[data-preferences-two-factor-key="type"]');
          var secretField = totpRow.querySelector('[data-preferences-two-factor-key="secret"]');
          var methodType = typeField instanceof HTMLSelectElement
            ? String(typeField.value || '').trim().toLowerCase()
            : '';
          var hasSecret = secretField instanceof HTMLInputElement
            ? String(secretField.value || '').trim() !== ''
            : false;
          var methodStatus = String(totpRow.getAttribute('data-preferences-two-factor-status') || '').trim().toLowerCase();

          if (methodType === 'totp' && hasSecret && methodStatus !== 'confirmed') {
            finishTotpSetupForRow(totpRow, totpSetupButton);
          } else {
            void setupTotpForRow(totpRow, totpSetupButton);
          }
        }
        return;
      }

      var recoveryGenerateButton = target.closest('[data-preferences-two-factor-recovery-generate="1"]');
      if (recoveryGenerateButton instanceof HTMLButtonElement) {
        var recoveryGenerateRow = recoveryGenerateButton.closest('[data-preferences-two-factor-row="1"]');
        if (recoveryGenerateRow instanceof HTMLElement) {
          void generateRecoveryForRow(recoveryGenerateRow, recoveryGenerateButton);
        }
        return;
      }

      var recoveryVisibilityButton = target.closest('[data-preferences-two-factor-recovery-visibility="1"]');
      if (recoveryVisibilityButton instanceof HTMLButtonElement) {
        var recoveryVisibilityRow = recoveryVisibilityButton.closest('[data-preferences-two-factor-row="1"]');
        if (recoveryVisibilityRow instanceof HTMLElement) {
          toggleRecoveryVisibility(recoveryVisibilityRow);
        }
        return;
      }

      var recoveryCodeField = target.closest('[data-preferences-two-factor-recovery-copy="1"]');
      if (recoveryCodeField instanceof HTMLInputElement) {
        var recoveryRowForCopy = recoveryCodeField.closest('[data-preferences-two-factor-row="1"]');
        if (!(recoveryRowForCopy instanceof HTMLElement)) {
          return;
        }
        var recoveryValue = '';
        var recoveryHash = '';
        var recoveryHiddenForCopy = recoveryHiddenField(recoveryRowForCopy);
        var recoveryHashForCopy = recoveryHashField(recoveryRowForCopy);
        if (recoveryHiddenForCopy instanceof HTMLInputElement) {
          recoveryValue = String(recoveryHiddenForCopy.value || '');
        }
        if (recoveryHashForCopy instanceof HTMLInputElement) {
          recoveryHash = String(recoveryHashForCopy.value || '').trim();
        }

        if (recoveryValue.trim() === '' || recoveryHash !== '') {
          setRecoveryCopyHint(recoveryRowForCopy, recoveryHash !== '' ? 'Stored phrase cannot be copied.' : 'Generate a phrase first.');
          return;
        }

        void copyTextValue(recoveryValue).then(function (copied) {
          showCopyTooltip(recoveryCodeField, copied);
        });
        return;
      }

      var totpSecretField = target.closest('[data-preferences-two-factor-secret-copy="1"]');
      if (totpSecretField instanceof HTMLInputElement) {
        void copyTextValue(totpSecretField.value).then(function (copied) {
          showCopyTooltip(totpSecretField, copied);
          totpSecretField.blur();
        });
        return;
      }

      var removeButton = target.closest('[data-preferences-two-factor-remove="1"]');
      if (removeButton instanceof HTMLElement) {
        var removeRow = removeButton.closest('[data-preferences-two-factor-row="1"]');
        if (removeRow instanceof HTMLElement) {
          removeRow.remove();
          reindexRows();
        }
        return;
      }

      var registerButton = target.closest('[data-preferences-two-factor-webauthn-register="1"]');
      if (!(registerButton instanceof HTMLButtonElement)) {
        return;
      }

      var row = registerButton.closest('[data-preferences-two-factor-row="1"]');
      if (!(row instanceof HTMLElement)) {
        return;
      }

      void pairWebauthnForRow(row, registerButton);
    });

    list.addEventListener('keydown', function (event) {
      var target = event.target;
      if (!(target instanceof Element)) {
        return;
      }

      if (!(event instanceof KeyboardEvent) || (event.key !== 'Enter' && event.key !== ' ')) {
        return;
      }

      var totpSecretField = target.closest('[data-preferences-two-factor-secret-copy="1"]');
      if (!(totpSecretField instanceof HTMLInputElement)) {
        return;
      }

      event.preventDefault();
      void copyTextValue(totpSecretField.value).then(function (copied) {
        showCopyTooltip(totpSecretField, copied);
        totpSecretField.blur();
      });
    });

    list.addEventListener('focusin', function (event) {
      var target = event.target;
      if (!(target instanceof Element)) {
        return;
      }

      var recoveryCodeField = target.closest('[data-preferences-two-factor-recovery-copy="1"]');
      if (recoveryCodeField instanceof HTMLInputElement) {
        window.setTimeout(function () {
          recoveryCodeField.blur();
        }, 0);
        return;
      }

      var totpSecretField = target.closest('[data-preferences-two-factor-secret-copy="1"]');
      if (!(totpSecretField instanceof HTMLInputElement)) {
        return;
      }

      window.setTimeout(function () {
        totpSecretField.blur();
      }, 0);
    });

    reindexRows();
  });
</script>

<?php if ($flashError !== null): ?>
<div class="alert alert-danger" role="alert"><?= e($flashError) ?></div>
<?php endif; ?>

<form method="post" action="<?= e($panelBase) ?>/preferences/save" enctype="multipart/form-data" autocomplete="off">
    <?= $csrfField ?>
    <nav class="rvnp-editor-actions">
        <button type="submit" class="btn btn-success"><i class="bi bi-floppy me-2" aria-hidden="true"></i>Save Preferences</button>
    </nav>

    <section class="rvnp-editor-layout" data-rvn-tab-layout="editor">
    <ul class="nav nav-tabs" id="rvnp-editor-tabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button
                class="nav-link<?= $activeTab === 'account' ? ' active' : '' ?>"
                id="preferences-account-tab"
                data-bs-toggle="tab"
                data-bs-target="#rvnp-editor-pane-account"
                type="button"
                role="tab"
                aria-controls="rvnp-editor-pane-account"
                aria-selected="<?= $activeTab === 'account' ? 'true' : 'false' ?>"
            >Account</button>
        </li>
        <li class="nav-item" role="presentation">
            <button
                class="nav-link<?= $activeTab === 'profile' ? ' active' : '' ?>"
                id="preferences-profile-tab"
                data-bs-toggle="tab"
                data-bs-target="#rvnp-editor-pane-profile"
                type="button"
                role="tab"
                aria-controls="rvnp-editor-pane-profile"
                aria-selected="<?= $activeTab === 'profile' ? 'true' : 'false' ?>"
            >Profile</button>
        </li>
        <li class="nav-item" role="presentation">
            <button
                class="nav-link<?= $activeTab === 'security' ? ' active' : '' ?>"
                id="preferences-security-tab"
                data-bs-toggle="tab"
                data-bs-target="#rvnp-editor-pane-security"
                type="button"
                role="tab"
                aria-controls="rvnp-editor-pane-security"
                aria-selected="<?= $activeTab === 'security' ? 'true' : 'false' ?>"
            >Security</button>
        </li>
    </ul>

    <div class="tab-content raven-tab-content-surface border border-top-0 p-3" id="rvnp-editor-content">
        <div
            class="tab-pane fade<?= $activeTab === 'account' ? ' show active' : '' ?>"
            id="rvnp-editor-pane-account"
            role="tabpanel"
            aria-labelledby="preferences-account-tab"
            tabindex="0"
        >
            <?php if ($usernameRequiredForAuth): ?>
            <div class="form-group">
                <label class="form-label" for="username">Username</label>
                <input class="form-control"
                    id="username"
                    name="username"
                    required
                    value="<?= e((string) ($preferences['username'] ?? '')) ?>"
                >
            </div>
            <?php endif; ?>

            <div class="form-group">
                <label class="form-label" for="display_name">Display Name</label>
                <input class="form-control"
                    id="display_name"
                    name="display_name"
                    value="<?= e((string) ($preferences['display_name'] ?? '')) ?>"
                >
            </div>

            <div class="form-group">
                <label class="form-label" for="email">Email</label>
                <input class="form-control"
                    id="email"
                    name="email"
                    type="email"
                    required
                    value="<?= e((string) ($preferences['email'] ?? '')) ?>"
                >
            </div>

            <div class="form-group mb-0">
                <label class="form-label" for="theme">Panel Theme</label>
                <select class="form-select" id="theme" name="theme" required>
                    <?php foreach ($themeOptions as $option): ?>
                        <?php $optionLabel = (string) ($themeLabels[$option] ?? $option); ?>
                        <option value="<?= e($option) ?>"<?= $selectedTheme === $option ? ' selected' : '' ?>>
                            <?= e($optionLabel) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text"><code>&lt;Default&gt;</code> follows the system's configured default admin theme.</div>
            </div>
        </div>

        <div
            class="tab-pane fade<?= $activeTab === 'profile' ? ' show active' : '' ?>"
            id="rvnp-editor-pane-profile"
            role="tabpanel"
            aria-labelledby="preferences-profile-tab"
            tabindex="0"
        >
            <div class="form-group">
                <label class="form-label h3" for="bio">Bio</label>
                <textarea
                    class="form-control"
                    id="bio"
                    name="bio"
                    rows="5"
                    maxlength="<?= (int) ($bioMaxLength ?? 500) ?>"
                ><?= e((string) ($preferences['bio'] ?? '')) ?></textarea>
                <div class="form-text">Plaintext profile bio. Max <?= (int) ($bioMaxLength ?? 500) ?> characters.</div>
            </div>

            <div class="form-group">
                <label class="form-label h3" for="avatar">Avatar</label>
                <?php if ($avatarFilename !== ''): ?>
                <div class="mb-2">
                    <!-- Avatar image is served from required public content path. -->
                    <img
                        src="<?= e($avatarThumbUrl) ?>"
                        onerror="this.onerror=null;this.src='<?= e($avatarUrl) ?>';"
                        alt="Current avatar"
                        style="max-width: 96px; max-height: 96px; border-radius: 8px;"
                    >
                </div>
                <?php endif; ?>
                <input id="avatar" name="avatar" type="file" class="form-control" accept=".gif,.jpg,.jpeg,.png,image/gif,image/jpeg,image/png">
                <div class="form-text"><?= e($avatarUploadLimitsNote) ?></div>
                <?php if ($avatarFilename !== ''): ?>
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" value="1" id="remove_avatar" name="remove_avatar">
                        <label class="form-check-label" for="remove_avatar">Remove current avatar</label>
                    </div>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label class="form-label h3" for="cover_image">Cover Image</label>
                <input
                    id="cover_image"
                    name="cover_image"
                    type="text"
                    class="form-control"
                    value="<?= e($coverImage) ?>"
                    placeholder="/uploads/users/cover/example.jpg"
                >
                <div class="form-text">Optional public image path or URL stored with your profile.</div>
            </div>

            <div class="form-group mb-0">
                <label class="form-label h3 d-block">Contact Information</label>
                <div id="preferences-contact-profiles-list">
                    <?php foreach ($contactProfiles as $index => $contactProfile): ?>
                        <?php
                        $contactType = (string) ($contactProfile['type'] ?? '');
                        $contactValue = (string) ($contactProfile['value'] ?? '');
                        ?>
                        <div class="border rounded p-2 mb-2" data-preferences-contact-row="1">
                            <div class="row g-2 align-items-end pb-4">
                                <div class="col-md-4">
                                    <label class="form-label">Type</label>
                                    <select
                                        class="form-select"
                                        data-preferences-contact-key="type"
                                        name="contact_profiles[<?= (int) $index ?>][type]"
                                    >
                                        <?php foreach ($profileContactOptions as $optionSlug => $optionData): ?>
                                            <?php $optionLabel = (string) ($optionData['label'] ?? $optionSlug); ?>
                                            <?php $optionPrefix = (string) ($optionData['url_prefix'] ?? ''); ?>
                                            <option
                                                value="<?= e((string) $optionSlug) ?>"
                                                data-url-prefix="<?= e($optionPrefix) ?>"
                                                <?= $contactType === (string) $optionSlug ? ' selected' : '' ?>
                                            ><?= e($optionLabel) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md pe-md-0">
                                    <label class="form-label">Value</label>
                                    <div class="input-group">
                                        <span class="input-group-text d-none" data-preferences-contact-prefix-addon="1"></span>
                                        <input
                                            type="text"
                                            class="form-control"
                                            data-preferences-contact-key="value"
                                            name="contact_profiles[<?= (int) $index ?>][value]"
                                            value="<?= e($contactValue) ?>"
                                            placeholder="username/path or value"
                                        >
                                    </div>
                                </div>
                                <div class="col-auto ps-md-0 d-flex align-items-end">
                                    <button type="button" class="btn btn-danger ms-2" data-preferences-contact-remove="1"><i class="bi bi-x-circle-fill" aria-hidden="true"></i></button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($profileContactOptions !== []): ?>
                    <button type="button" class="btn btn-primary btn-sm" id="preferences-contact-profiles-add">Add More Contact Information</button>
                <?php else: ?>
                    <div class="form-text text-muted">No contact types are configured in <code>user.contact</code>.</div>
                <?php endif; ?>
            </div>
        </div>

        <div
            class="tab-pane fade<?= $activeTab === 'security' ? ' show active' : '' ?>"
            id="rvnp-editor-pane-security"
            role="tabpanel"
            aria-labelledby="preferences-security-tab"
            tabindex="0"
        >
            <div class="form-group">
                <label class="form-label h3 mb-0" for="new_password">New Password</label>
                <div class="form-text mb-2">Use this only when you want to rotate your panel password.</div>
                <button type="button" class="btn btn-danger btn-sm" id="preferences-password-toggle">Change Password</button>
                <div class="mt-2 d-none" id="preferences-password-fields"></div>
            </div>

            <div class="form-group mb-0">
                <label class="form-label h3 d-block">Two-Factor Authentication</label>
                <p class="form-text mb-2">Add multiple 2FA methods to be enforced at login.<br><strong>Set a backup method so that you do not get locked out!</strong></p>

                <div id="preferences-two-factor-methods-list">
                    <?php foreach ($twoFactorMethods as $index => $method): ?>
                        <?php
                        $methodType = (string) ($method['type'] ?? 'none');
                        $methodLabel = (string) ($method['label'] ?? '');
                        $methodStatus = strtolower((string) ($method['status'] ?? ''));
                        $methodSecret = (string) ($method['secret'] ?? '');
                        $methodRecoveryCode = trim((string) ($method['recovery_code'] ?? ''));
                        $methodRecoveryHash = trim((string) ($method['recovery_hash'] ?? ''));
                        $methodRecoveryHasStoredHash = $methodRecoveryHash !== '' && $methodRecoveryCode === '';
                        $methodRecoveryDisplay = $methodRecoveryHasStoredHash
                            ? 'Stored securely on server'
                            : (preg_replace('/\S/u', '*', $methodRecoveryCode) ?? '');
                        $methodRecoveryHint = $methodRecoveryHasStoredHash
                            ? 'Phrase is stored as a one-way hash. Generate a new phrase to replace it.'
                            : 'Click phrase to copy.';
                        $methodRecoveryCopyEnabled = !$methodRecoveryHasStoredHash && $methodRecoveryCode !== '';
                        $methodReusable = (bool) ($method['reusable'] ?? false);
                        $methodCredentialId = (string) ($method['credential_id'] ?? '');
                        $methodCredentialPublicKey = (string) ($method['credential_public_key'] ?? '');
                        $methodSignatureCounter = (int) ($method['signature_counter'] ?? 0);
                        $methodRequireUv = (bool) ($method['require_uv'] ?? false);
                        $methodEmail = (string) ($method['email'] ?? '');
                        $methodProvisioningUri = (string) ($method['provisioning_uri'] ?? '');
                        $methodQrDataUri = (string) ($method['qr_data_uri'] ?? '');
                        $methodStatusValue = $methodStatus;
                        if (!in_array($methodStatusValue, ['pending', 'confirmed', 'stub'], true)) {
                            $methodStatusValue = match ($methodType) {
                                'totp' => 'pending',
                                'webauthn' => 'stub',
                                default => 'confirmed',
                            };
                        }
                        $isTotpConfirmed = $methodType === 'totp' && $methodStatus === 'confirmed';
                        $totpSecretValue = $isTotpConfirmed ? 'Stored securely on server' : $methodSecret;
                        $totpSecretCopyEnabled = !$isTotpConfirmed;
                        $methodLabelPlaceholder = match ($methodType) {
                            'totp' => 'Authenticator App',
                            'recovery' => '',
                            'webauthn' => 'My Key',
                            'email' => 'My Email',
                            default => '2FA Method Label',
                        };
                        ?>
                        <div
                            class="border rounded p-2 mb-2"
                            data-preferences-two-factor-row="1"
                            data-preferences-two-factor-status="<?= e($methodStatus) ?>"
                            data-preferences-totp-provisioning-uri="<?= e($methodProvisioningUri) ?>"
                            data-preferences-totp-qr-data-uri="<?= e($methodQrDataUri) ?>"
                        >
                            <div class="row g-2 align-items-end pb-4">
                                <div class="col-md-2">
                                    <label class="form-label">Type</label>
                                    <select class="form-select form-select-sm" data-preferences-two-factor-key="type" name="two_factor_methods[<?= (int) $index ?>][type]">
                                        <?php foreach ($twoFactorTypeOptions as $typeValue => $typeLabel): ?>
                                            <?php if ((string) $typeValue === 'none') { continue; } ?>
                                            <option value="<?= e((string) $typeValue) ?>"<?= $methodType === (string) $typeValue ? ' selected' : '' ?>><?= e((string) $typeLabel) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input
                                        type="hidden"
                                        data-preferences-two-factor-key="status"
                                        name="two_factor_methods[<?= (int) $index ?>][status]"
                                        value="<?= e($methodStatusValue) ?>"
                                    >
                                </div>
                                <div class="col-md-3" data-preferences-two-factor-section="label"<?= $methodType === 'recovery' ? ' style="display:none;"' : '' ?>>
                                    <label class="form-label">Label</label>
                                    <input
                                        type="text"
                                        class="form-control form-control-sm"
                                        data-preferences-two-factor-key="label"
                                        name="two_factor_methods[<?= (int) $index ?>][label]"
                                        value="<?= e($methodLabel) ?>"
                                        placeholder="<?= e($methodLabelPlaceholder) ?>"
                                    >
                                </div>
                                <div class="col-md position-relative" data-preferences-two-factor-section="totp"<?= $methodType === 'totp' ? '' : ' style="display:none;"' ?>>
                                    <label class="form-label" data-preferences-two-factor-totp-label="1"><?= $isTotpConfirmed ? 'TOTP Secret' : 'TOTP Secret / Confirm Code' ?></label>
                                    <div class="input-group input-group-sm">
                                        <input
                                            type="text"
                                            class="form-control form-control-sm"
                                            data-preferences-two-factor-key="secret"
                                            <?= $totpSecretCopyEnabled ? 'data-preferences-two-factor-secret-copy="1"' : '' ?>
                                            <?= !$isTotpConfirmed ? 'name="two_factor_methods[' . (int) $index . '][secret]"' : '' ?>
                                            value="<?= e($totpSecretValue) ?>"
                                            placeholder="Click 'Setup App'"
                                            <?= $totpSecretCopyEnabled ? 'title="Click to copy"' : '' ?>
                                            autocomplete="off"
                                            style="caret-color: <?= $totpSecretCopyEnabled ? 'transparent' : 'auto' ?>; cursor: <?= $totpSecretCopyEnabled ? 'pointer' : 'default' ?>;"
                                            readonly
                                        >
                                        <?php if ($isTotpConfirmed): ?>
                                            <input
                                                type="hidden"
                                                data-preferences-two-factor-key="secret"
                                                data-preferences-two-factor-secret-preserved="1"
                                                name="two_factor_methods[<?= (int) $index ?>][secret]"
                                                value="<?= e($methodSecret) ?>"
                                            >
                                        <?php endif; ?>
                                        <input
                                            type="text"
                                            class="form-control form-control-sm"
                                            data-preferences-two-factor-key="verification_code"
                                            name="two_factor_methods[<?= (int) $index ?>][verification_code]"
                                            value=""
                                            placeholder="8-digit code"
                                            <?= $isTotpConfirmed ? ' style="display:none;" disabled' : '' ?>
                                        >
                                        <button type="button" class="btn btn-primary btn-sm" data-preferences-two-factor-totp-setup="1"><?=
                                            $methodStatus === 'confirmed'
                                                ? 'Reset'
                                                : ($methodSecret === '' ? 'Setup App' : 'Finish Setup')
                                        ?></button>
                                    </div>
                                    <div class="small text-muted d-none position-absolute start-0 end-0" style="top:calc(100% + 0.2rem);" data-preferences-two-factor-totp-feedback="1"></div>
                                </div>
                                <div class="col-md position-relative" data-preferences-two-factor-section="webauthn"<?= $methodType === 'webauthn' ? '' : ' style="display:none;"' ?>>
                                    <label class="form-label">Credential ID</label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text">
                                            <input
                                                class="form-check-input mt-0 me-2"
                                                type="checkbox"
                                                data-preferences-two-factor-key="require_uv"
                                                name="two_factor_methods[<?= (int) $index ?>][require_uv]"
                                                value="1"
                                                <?= $methodRequireUv ? ' checked' : '' ?>
                                                aria-label="Require PIN/Biometric?"
                                            >
                                            <span class="small">PIN/Bio</span>
                                        </span>
                                        <input
                                            type="text"
                                            class="form-control form-control-sm"
                                            data-preferences-two-factor-key="credential_id"
                                            name="two_factor_methods[<?= (int) $index ?>][credential_id]"
                                            value="<?= e($methodCredentialId) ?>"
                                            placeholder="Pair a security key to populate this"
                                        >
                                        <button type="button" class="btn btn-primary btn-sm" data-preferences-two-factor-webauthn-register="1">
                                            <?= $methodStatus === 'confirmed' ? 'Reset' : 'Pair Security Key' ?>
                                        </button>
                                    </div>
                                    <div class="small d-none position-absolute start-0 end-0" style="top:calc(100% + 0.2rem);" data-preferences-two-factor-webauthn-feedback="1"></div>
                                    <input
                                        type="hidden"
                                        data-preferences-two-factor-key="credential_public_key"
                                        name="two_factor_methods[<?= (int) $index ?>][credential_public_key]"
                                        value="<?= e($methodCredentialPublicKey) ?>"
                                    >
                                    <input
                                        type="hidden"
                                        data-preferences-two-factor-key="signature_counter"
                                        name="two_factor_methods[<?= (int) $index ?>][signature_counter]"
                                        value="<?= (int) $methodSignatureCounter ?>"
                                    >
                                </div>
                                <div class="col-md" data-preferences-two-factor-section="email"<?= $methodType === 'email' ? '' : ' style="display:none;"' ?>>
                                    <label class="form-label">Email Address</label>
                                    <input
                                        type="email"
                                        class="form-control form-control-sm"
                                        data-preferences-two-factor-key="target_email"
                                        name="two_factor_methods[<?= (int) $index ?>][target_email]"
                                        value="<?= e($methodEmail) ?>"
                                        placeholder="Defaults to account email if blank"
                                    >
                                </div>
                                <div class="col-md position-relative" data-preferences-two-factor-section="recovery"<?= $methodType === 'recovery' ? '' : ' style="display:none;"' ?>>
                                    <label class="form-label">Recovery Phrase</label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text">
                                            <input
                                                class="form-check-input mt-0 me-2"
                                                type="checkbox"
                                                data-preferences-two-factor-key="reusable"
                                                name="two_factor_methods[<?= (int) $index ?>][reusable]"
                                                value="1"
                                                <?= $methodReusable ? ' checked' : '' ?>
                                                aria-label="Reusable"
                                            >
                                                <span class="small">Reusable</span>
                                            </span>
                                        <input
                                            type="hidden"
                                            data-preferences-two-factor-key="recovery_code"
                                            name="two_factor_methods[<?= (int) $index ?>][recovery_code]"
                                            value="<?= e($methodRecoveryCode) ?>"
                                        >
                                        <input
                                            type="hidden"
                                            data-preferences-two-factor-key="recovery_hash"
                                            name="two_factor_methods[<?= (int) $index ?>][recovery_hash]"
                                            value="<?= e($methodRecoveryHash) ?>"
                                        >
                                        <input
                                            type="text"
                                            class="form-control form-control-sm"
                                            data-preferences-two-factor-recovery-display="1"
                                            data-preferences-two-factor-recovery-copy="1"
                                            value="<?= e($methodRecoveryDisplay) ?>"
                                            placeholder="Generate 12-word recovery phrase"
                                            title="<?= e($methodRecoveryCopyEnabled ? 'Click to copy' : 'Stored securely') ?>"
                                            autocomplete="off"
                                            style="<?= e($methodRecoveryCopyEnabled ? 'caret-color: transparent; cursor: pointer;' : 'caret-color: auto; cursor: default;') ?>"
                                            readonly
                                        >
                                        <button
                                            type="button"
                                            class="btn btn-outline-secondary btn-sm"
                                            data-preferences-two-factor-recovery-visibility="1"
                                            title="Show recovery phrase"
                                            aria-label="Show recovery phrase"
                                            <?= $methodRecoveryHasStoredHash ? 'style="display:none;"' : '' ?>
                                        ><i class="bi bi-eye" aria-hidden="true" data-preferences-two-factor-recovery-visibility-icon="1"></i></button>
                                        <button type="button" class="btn btn-primary btn-sm" data-preferences-two-factor-recovery-generate="1">Generate</button>
                                    </div>
                                    <div
                                        class="small text-muted position-absolute start-0 end-0"
                                        style="top:calc(100% + 0.2rem);"
                                        data-preferences-two-factor-recovery-copy-hint="1"
                                    ><?= e($methodRecoveryHint) ?></div>
                                </div>
                                <div class="col-auto ps-md-0 d-flex align-items-end">
                                    <button type="button" class="btn btn-danger btn-sm" data-preferences-two-factor-remove="1"><i class="bi bi-x-circle-fill" aria-hidden="true"></i></button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <button type="button" class="btn btn-primary btn-sm" id="preferences-two-factor-add">Add 2FA Method</button>
            </div>
        </div>
    </div>
    </section>

    <nav class="rvnp-editor-actions">
        <button type="submit" class="btn btn-success"><i class="bi bi-floppy me-2" aria-hidden="true"></i>Save Preferences</button>
    </nav>
</form>

<template id="preferences-password-fields-template">
    <div class="form-text mb-1">Enter new password (minimum 8 chars):</div>
    <input class="form-control"
        id="new_password"
        name="new_password"
        type="text"
        autocomplete="new-password"
        data-preferences-password-kind="new"
        data-preferences-password-field="1"
    >
    <label class="form-text mt-2 mb-0" for="confirm_new_password">Enter new password again to confirm:</label>
    <input class="form-control"
        id="confirm_new_password"
        name="confirm_new_password"
        type="text"
        autocomplete="new-password"
        data-preferences-password-kind="confirm"
        data-preferences-password-field="1"
    >
</template>

<script>
  (function () {
    var toggleButton = document.getElementById('preferences-password-toggle');
    var fieldsContainer = document.getElementById('preferences-password-fields');
    var fieldsTemplate = document.getElementById('preferences-password-fields-template');
    if (
      !(toggleButton instanceof HTMLButtonElement)
      || !(fieldsContainer instanceof HTMLElement)
      || !(fieldsTemplate instanceof HTMLTemplateElement)
    ) {
      return;
    }

    var enabled = false;

    function setEnabled(nextEnabled) {
      enabled = !!nextEnabled;
      if (enabled && !fieldsContainer.hasChildNodes()) {
        fieldsContainer.appendChild(fieldsTemplate.content.cloneNode(true));
        var passwordFields = fieldsContainer.querySelectorAll('[data-preferences-password-field="1"]');
        passwordFields.forEach(function (field) {
          if (field instanceof HTMLInputElement) {
            field.type = 'password';
          }
        });
      } else if (!enabled) {
        fieldsContainer.innerHTML = '';
      }

      fieldsContainer.classList.toggle('d-none', !enabled);
      toggleButton.textContent = enabled ? 'Cancel Password Change' : 'Change Password';
    }

    toggleButton.addEventListener('click', function () {
      setEnabled(!enabled);
      if (!enabled) {
        return;
      }

      var firstField = fieldsContainer.querySelector('#new_password');
      if (firstField instanceof HTMLInputElement) {
        firstField.focus();
      }
    });
  })();
</script>

<?php if ($profileContactOptions !== []): ?>
<template id="preferences-contact-profile-template">
    <div class="border rounded p-2 mb-2" data-preferences-contact-row="1">
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Type</label>
                <select class="form-select" data-preferences-contact-key="type">
                    <?php foreach ($profileContactOptions as $optionSlug => $optionData): ?>
                        <?php $optionLabel = (string) ($optionData['label'] ?? $optionSlug); ?>
                        <?php $optionPrefix = (string) ($optionData['url_prefix'] ?? ''); ?>
                        <option value="<?= e((string) $optionSlug) ?>" data-url-prefix="<?= e($optionPrefix) ?>"><?= e($optionLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md pe-md-0">
                <label class="form-label">Value</label>
                <div class="input-group">
                    <span class="input-group-text d-none" data-preferences-contact-prefix-addon="1"></span>
                    <input
                        type="text"
                        class="form-control"
                        data-preferences-contact-key="value"
                        placeholder="username/path or value"
                    >
                </div>
            </div>
            <div class="col-auto pe-md-0 d-flex align-items-end">
                <button type="button" class="btn btn-danger ms-2" data-preferences-contact-remove="1"><i class="bi bi-x-circle-fill" aria-hidden="true"></i></button>
            </div>
        </div>
    </div>
</template>
<script>
  (function () {
    var list = document.getElementById('preferences-contact-profiles-list');
    var addButton = document.getElementById('preferences-contact-profiles-add');
    var template = document.getElementById('preferences-contact-profile-template');

    if (!(list instanceof HTMLElement) || !(addButton instanceof HTMLButtonElement) || !(template instanceof HTMLTemplateElement)) {
      return;
    }

    function reindexRows() {
      var rows = list.querySelectorAll('[data-preferences-contact-row="1"]');
      rows.forEach(function (row, index) {
        if (!(row instanceof HTMLElement)) {
          return;
        }
        var typeField = row.querySelector('[data-preferences-contact-key="type"]');
        var valueField = row.querySelector('[data-preferences-contact-key="value"]');
        if (typeField instanceof HTMLSelectElement) {
          typeField.name = 'contact_profiles[' + index + '][type]';
        }
        if (valueField instanceof HTMLInputElement) {
          valueField.name = 'contact_profiles[' + index + '][value]';
        }
        syncPrefixAddon(row);
      });
    }

    function syncPrefixAddon(row) {
      if (!(row instanceof HTMLElement)) {
        return;
      }
      var typeField = row.querySelector('[data-preferences-contact-key="type"]');
      var prefixAddon = row.querySelector('[data-preferences-contact-prefix-addon="1"]');
      if (!(typeField instanceof HTMLSelectElement) || !(prefixAddon instanceof HTMLElement)) {
        return;
      }
      var option = typeField.options[typeField.selectedIndex];
      var prefix = option instanceof HTMLOptionElement ? String(option.getAttribute('data-url-prefix') || '').trim() : '';
      if (prefix === '') {
        prefixAddon.textContent = '';
        prefixAddon.classList.add('d-none');
        return;
      }
      prefixAddon.textContent = prefix;
      prefixAddon.classList.remove('d-none');
    }

    function appendRow() {
      var fragment = template.content.cloneNode(true);
      list.appendChild(fragment);
      reindexRows();
    }

    addButton.addEventListener('click', function () {
      appendRow();
    });

    list.addEventListener('change', function (event) {
      var target = event.target;
      if (!(target instanceof HTMLSelectElement) || target.getAttribute('data-preferences-contact-key') !== 'type') {
        return;
      }
      var row = target.closest('[data-preferences-contact-row="1"]');
      syncPrefixAddon(row);
    });

    list.addEventListener('click', function (event) {
      var target = event.target;
      if (!(target instanceof Element)) {
        return;
      }
      var removeButton = target.closest('[data-preferences-contact-remove="1"]');
      if (!(removeButton instanceof HTMLElement)) {
        return;
      }
      var row = removeButton.closest('[data-preferences-contact-row="1"]');
      if (!(row instanceof HTMLElement)) {
        return;
      }
      row.remove();
      reindexRows();
    });

    reindexRows();
  })();
</script>
<?php endif; ?>
