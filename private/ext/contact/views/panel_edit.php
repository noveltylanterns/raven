<?php

/**
 * RAVEN CMS
 * ~/private/ext/contact/views/panel_edit.php
 * Contact Forms extension edit/create page template.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

/** @var array{
 *   name: string,
 *   slug: string,
 *   enabled: bool,
 *   save_mail_locally?: bool,
 *   destination: string,
 *   cc?: string,
 *   bcc?: string,
 *   additional_fields?: array<int, array{
 *     label: string,
 *     name: string,
 *     type: string,
 *     required: bool
 *   }>
 * }|null $formData */
/** @var string $formAction */
/** @var string $deleteAction */
/** @var string $indexPath */
/** @var string $contactSubmissionsBasePath */
/** @var string|null $flashSuccess */
/** @var string|null $flashError */
/** @var string $csrfField */

use function Raven\Core\Support\e;

$isEditMode = is_array($formData);
$formName = (string) ($formData['name'] ?? '');
$formSlug = (string) ($formData['slug'] ?? '');
$formEnabled = (bool) ($formData['enabled'] ?? true);
$formSaveMailLocally = !is_array($formData) || !array_key_exists('save_mail_locally', $formData)
    ? true
    : (bool) ($formData['save_mail_locally'] ?? true);
$formDestination = (string) ($formData['destination'] ?? '');
$formCc = (string) ($formData['cc'] ?? '');
$formBcc = (string) ($formData['bcc'] ?? '');
$shortcodeValue = $formSlug !== '' ? '[contact slug="' . $formSlug . '"]' : '';
$additionalFields = is_array($formData['additional_fields'] ?? null) ? (array) $formData['additional_fields'] : [];
$deleteFormId = 'delete-contact-form';
?>
<header class="card">
    <div class="card-body">
        <div class="d-flex align-items-start justify-content-between gap-2">
            <h1><?= $isEditMode ? 'Edit Contact Form: \'' . e($formName !== '' ? $formName : $formSlug) . '\'' : 'Create New Contact Form' ?></h1>

            <?php if ($isEditMode): ?>
            <div class="d-flex gap-2">
                <a href="<?= e($contactSubmissionsBasePath) ?>/<?= rawurlencode($formSlug) ?>" class="btn btn-primary btn-sm">View Submissions</a>
            </div>
            <?php endif; ?>
        </div>

        <?php if (!$isEditMode): ?>
        <p class="text-mutedmb-0">Create or update contact form fields and delivery settings.</p>
        <?php endif; ?>

        <?php if ($isEditMode): ?>
        <p class="mb-0 small">
            <i class="bi bi-link-45deg me-1" style="font-size: 1.2em; vertical-align: -0.12em;" aria-hidden="true"></i>
            <code
                id="contact_form_shortcode"
                role="button"
                tabindex="0"
                title="Click to copy shortcode"
                data-bs-toggle="tooltip"
                data-bs-placement="top"
            ><?= e($shortcodeValue) ?></code>
        </p>
        <?php endif; ?>
    </div>
</header>

<?php if ($flashSuccess !== null): ?>
<div class="alert alert-success" role="alert"><?= e($flashSuccess) ?></div>
<?php endif; ?>

<?php if ($flashError !== null): ?>
<div class="alert alert-danger" role="alert"><?= e($flashError) ?></div>
<?php endif; ?>

<?php if ($isEditMode): ?>
<form id="<?= e($deleteFormId) ?>" method="post" action="<?= e($deleteAction) ?>">
    <?= $csrfField ?>
    <input type="hidden" name="slug" value="<?= e($formSlug) ?>">
</form>
<?php endif; ?>

<form method="post" action="<?= e($formAction) ?>">
    <?= $csrfField ?>
    <input type="hidden" name="original_slug" value="<?= e($formSlug) ?>">

    <div class="d-flex justify-content-end gap-2 mb-3">
        <button type="submit" class="btn btn-success"><i class="bi bi-floppy me-2" aria-hidden="true"></i>Save Form</button>
        <a href="<?= e($indexPath) ?>" class="btn btn-secondary"><i class="bi bi-box-arrow-left me-2" aria-hidden="true"></i>Back to Contact Forms</a>
        <?php if ($isEditMode): ?>
            <button
                type="submit"
                class="btn btn-danger"
                form="<?= e($deleteFormId) ?>"
                onclick="return confirm('Delete this contact form?');"
            >
                <i class="bi bi-trash3 me-2" aria-hidden="true"></i>Delete Form
            </button>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label h5" for="contact_form_name">Name</label>
                <input
                    id="contact_form_name"
                    type="text"
                    class="form-control"
                    name="name"
                    required
                    value="<?= e($formName) ?>"
                >
            </div>

            <div class="mb-3">
                <label class="form-label h5" for="contact_form_slug">Slug</label>
                <input
                    id="contact_form_slug"
                    type="text"
                    class="form-control"
                    name="slug"
                    required
                    value="<?= e($formSlug) ?>"
                >
                <div class="form-text">Lowercase letters, numbers, and dashes only.</div>
            </div>

            <div class="mb-3">
                <label class="form-label h5" for="contact_form_destination">Destination Email(s)</label>
                <input
                    id="contact_form_destination"
                    type="text"
                    class="form-control"
                    name="destination"
                    required
                    value="<?= e($formDestination) ?>"
                >
                <div class="form-text">Separate multiple addresses with commas or semicolons.</div>
            </div>

            <div class="mb-3">
                <label class="form-label h5" for="contact_form_cc">CC Email(s)</label>
                <input
                    id="contact_form_cc"
                    type="text"
                    class="form-control"
                    name="cc"
                    value="<?= e($formCc) ?>"
                >
                <div class="form-text">Optional. Separate multiple addresses with commas or semicolons.</div>
            </div>

            <div class="mb-3">
                <label class="form-label h5" for="contact_form_bcc">BCC Email(s)</label>
                <input
                    id="contact_form_bcc"
                    type="text"
                    class="form-control"
                    name="bcc"
                    value="<?= e($formBcc) ?>"
                >
                <div class="form-text">Optional. Separate multiple addresses with commas or semicolons.</div>
            </div>

            <div class="mb-3">
                <input type="hidden" name="save_mail_locally" value="0">
                <div class="form-check mb-0">
                    <input
                        id="contact_form_save_mail_locally"
                        type="checkbox"
                        class="form-check-input"
                        name="save_mail_locally"
                        value="1"
                        <?= $formSaveMailLocally ? 'checked' : '' ?>
                    >
                    <label class="form-check-label" for="contact_form_save_mail_locally">Save Mail Locally?</label>
                </div>
                <div class="form-text">When enabled, successful contact submissions are also stored in local submissions storage.</div>
            </div>

            <div class="mb-3">
                <h2 class="h5">Fields</h2>
                <div class="form-text mb-2">
                    Default fields are always included: Name, Email, and Message.
                    Add optional additional fields below.
                </div>
                <div id="contact-additional-fields">
                    <?php foreach ($additionalFields as $index => $additionalField): ?>
                        <?php
                        $fieldLabel = (string) ($additionalField['label'] ?? '');
                        $fieldName = (string) ($additionalField['name'] ?? '');
                        $fieldType = strtolower((string) ($additionalField['type'] ?? 'text'));
                        if (!in_array($fieldType, ['text', 'email', 'textarea'], true)) {
                            $fieldType = 'text';
                        }
                        $fieldRequired = (bool) ($additionalField['required'] ?? false);
                        ?>
                        <div class="border rounded p-2 mb-2" data-contact-additional-row="1">
                            <div class="row g-2 align-items-end">
                                <div class="col-md-4">
                                    <label class="form-label">Label</label>
                                    <input
                                        type="text"
                                        class="form-control"
                                        data-contact-field-key="label"
                                        name="additional_fields[<?= (int) $index ?>][label]"
                                        value="<?= e($fieldLabel) ?>"
                                        placeholder="Phone Number"
                                    >
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Field Name</label>
                                    <input
                                        type="text"
                                        class="form-control font-monospace"
                                        data-contact-field-key="name"
                                        name="additional_fields[<?= (int) $index ?>][name]"
                                        value="<?= e($fieldName) ?>"
                                        placeholder="phone_number"
                                    >
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Type</label>
                                    <select
                                        class="form-select"
                                        data-contact-field-key="type"
                                        name="additional_fields[<?= (int) $index ?>][type]"
                                    >
                                        <option value="text"<?= $fieldType === 'text' ? ' selected' : '' ?>>Text</option>
                                        <option value="email"<?= $fieldType === 'email' ? ' selected' : '' ?>>Email</option>
                                        <option value="textarea"<?= $fieldType === 'textarea' ? ' selected' : '' ?>>Textarea</option>
                                    </select>
                                </div>
                                <div class="col-md-2 d-flex align-items-center justify-content-between gap-2">
                                    <div class="form-check mb-0">
                                        <input
                                            class="form-check-input"
                                            type="checkbox"
                                            value="1"
                                            data-contact-field-key="required"
                                            name="additional_fields[<?= (int) $index ?>][required]"
                                            <?= $fieldRequired ? 'checked' : '' ?>
                                        >
                                        <label class="form-check-label">Required</label>
                                    </div>
                                    <button type="button" class="btn btn-secondary btn-sm" data-contact-remove-field="1">Remove</button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-primary btn-sm" id="contact-additional-field-add">Add Field</button>
            </div>

            <div class="form-check mb-0">
                <input
                    id="contact_form_enabled"
                    type="checkbox"
                    class="form-check-input"
                    name="enabled"
                    value="1"
                    <?= $formEnabled ? 'checked' : '' ?>
                >
                <label class="form-check-label" for="contact_form_enabled">Enabled</label>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-end gap-2 mt-3">
        <button type="submit" class="btn btn-success"><i class="bi bi-floppy me-2" aria-hidden="true"></i>Save Form</button>
        <a href="<?= e($indexPath) ?>" class="btn btn-secondary"><i class="bi bi-box-arrow-left me-2" aria-hidden="true"></i>Back to Contact Forms</a>
        <?php if ($isEditMode): ?>
            <button
                type="submit"
                class="btn btn-danger"
                form="<?= e($deleteFormId) ?>"
                onclick="return confirm('Delete this contact form?');"
            >
                <i class="bi bi-trash3 me-2" aria-hidden="true"></i>Delete Form
            </button>
        <?php endif; ?>
    </div>
</form>

<template id="contact-additional-field-template">
    <div class="border rounded p-2 mb-2" data-contact-additional-row="1">
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Label</label>
                <input
                    type="text"
                    class="form-control"
                    data-contact-field-key="label"
                    placeholder="Phone Number"
                >
            </div>
            <div class="col-md-3">
                <label class="form-label">Field Name</label>
                <input
                    type="text"
                    class="form-control font-monospace"
                    data-contact-field-key="name"
                    placeholder="phone_number"
                >
            </div>
            <div class="col-md-3">
                <label class="form-label">Type</label>
                <select class="form-select" data-contact-field-key="type">
                    <option value="text" selected>Text</option>
                    <option value="email">Email</option>
                    <option value="textarea">Textarea</option>
                </select>
            </div>
            <div class="col-md-2 d-flex align-items-center justify-content-between gap-2">
                <div class="form-check mb-0">
                    <input class="form-check-input" type="checkbox" value="1" data-contact-field-key="required">
                    <label class="form-check-label">Required</label>
                </div>
                <button type="button" class="btn btn-secondary btn-sm" data-contact-remove-field="1">Remove</button>
            </div>
        </div>
    </div>
</template>

<script>
  (function () {
    var slugInput = document.getElementById('contact_form_slug');
    var shortcodeElement = document.getElementById('contact_form_shortcode');
    var copyTooltip = null;
    var copyFeedbackTimer = null;

    if (!(slugInput instanceof HTMLInputElement) || !(shortcodeElement instanceof HTMLElement)) {
      return;
    }

    function normalizedSlug(value) {
      return String(value || '')
        .toLowerCase()
        .replace(/[^a-z0-9-]+/g, '-')
        .replace(/-{2,}/g, '-')
        .replace(/^-+|-+$/g, '');
    }

    function updateShortcode() {
      var slug = normalizedSlug(slugInput.value);
      if (slug === '') {
        shortcodeElement.textContent = '';
        return;
      }

      shortcodeElement.textContent = '[contact slug="' + slug + '"]';
    }

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

    function tooltipInstance() {
      if (copyTooltip !== null) {
        return copyTooltip;
      }

      if (!window.bootstrap || typeof window.bootstrap.Tooltip !== 'function') {
        return null;
      }

      copyTooltip = window.bootstrap.Tooltip.getOrCreateInstance(shortcodeElement, {
        trigger: 'manual',
        placement: 'top',
        title: 'Copied!'
      });

      return copyTooltip;
    }

    function showCopyFeedback() {
      var tooltip = tooltipInstance();
      if (!tooltip) {
        return;
      }

      if (typeof tooltip.setContent === 'function') {
        tooltip.setContent({ '.tooltip-inner': 'Copied!' });
      }

      tooltip.show();
      if (copyFeedbackTimer !== null) {
        window.clearTimeout(copyFeedbackTimer);
      }

      copyFeedbackTimer = window.setTimeout(function () {
        tooltip.hide();
      }, 900);
    }

    function copyShortcode() {
      var value = String(shortcodeElement.textContent || '').trim();
      if (value === '') {
        return;
      }

      if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
        // Prefer async clipboard API for modern browsers.
        navigator.clipboard.writeText(value).then(function () {
          showCopyFeedback();
        }).catch(function () {
          if (fallbackCopy(value)) {
            showCopyFeedback();
          }
        });
        return;
      }

      if (fallbackCopy(value)) {
        showCopyFeedback();
      }
    }

    shortcodeElement.addEventListener('click', copyShortcode);
    shortcodeElement.addEventListener('keydown', function (event) {
      if (!(event instanceof KeyboardEvent)) {
        return;
      }

      if (event.key !== 'Enter' && event.key !== ' ') {
        return;
      }

      event.preventDefault();
      copyShortcode();
    });

    slugInput.addEventListener('input', updateShortcode);
    updateShortcode();
  })();
</script>

<script>
  (function () {
    var list = document.getElementById('contact-additional-fields');
    var addButton = document.getElementById('contact-additional-field-add');
    var template = document.getElementById('contact-additional-field-template');

    if (!(list instanceof HTMLElement) || !(addButton instanceof HTMLButtonElement) || !(template instanceof HTMLTemplateElement)) {
      return;
    }

    function reindexRows() {
      var rows = list.querySelectorAll('[data-contact-additional-row="1"]');
      rows.forEach(function (row, index) {
        if (!(row instanceof HTMLElement)) {
          return;
        }

        var controls = row.querySelectorAll('[data-contact-field-key]');
        controls.forEach(function (control) {
          if (!(control instanceof HTMLInputElement || control instanceof HTMLSelectElement || control instanceof HTMLTextAreaElement)) {
            return;
          }

          var key = String(control.getAttribute('data-contact-field-key') || '');
          if (key === '') {
            return;
          }

          control.name = 'additional_fields[' + index + '][' + key + ']';
        });
      });
    }

    function bindRow(row) {
      if (!(row instanceof HTMLElement)) {
        return;
      }

      var removeButton = row.querySelector('[data-contact-remove-field="1"]');
      if (removeButton instanceof HTMLButtonElement) {
        removeButton.addEventListener('click', function () {
          row.remove();
          reindexRows();
        });
      }
    }

    addButton.addEventListener('click', function () {
      var fragment = template.content.cloneNode(true);
      var row = fragment.querySelector('[data-contact-additional-row="1"]');
      if (!(row instanceof HTMLElement)) {
        return;
      }

      list.appendChild(fragment);
      bindRow(list.lastElementChild);
      reindexRows();
    });

    list.querySelectorAll('[data-contact-additional-row="1"]').forEach(function (row) {
      bindRow(row);
    });
    reindexRows();
  })();
</script>
