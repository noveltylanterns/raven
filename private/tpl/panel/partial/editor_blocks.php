<?php

/**
 * RAVEN CMS
 * ~/private/tpl/panel/partial/editor_blocks.php
 * Route-scoped shared repeater CSS and JS for panel editor-block rows.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

/** @var array<int, array<string, mixed>>|null $editorBlocksBoot */

use Raven\Lib\View\Panel\Footer;

$editorBlocksBoot = is_array($editorBlocksBoot ?? null) ? array_values($editorBlocksBoot) : [];
$editorBlocksBootJson = json_encode(
    $editorBlocksBoot,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
);
if (!is_string($editorBlocksBootJson) || trim($editorBlocksBootJson) === '') {
    $editorBlocksBootJson = '[]';
}
?>
<?php ob_start(); ?>
  body#rvnp .rvn-editor-block {
    border-color: var(--raven-border) !important;
    background: var(--raven-surface-soft);
  }

  body#rvnp .rvn-editor-block .input-group-text {
    white-space: nowrap;
  }

  body#rvnp .rvn-editor-block--page-body {
    padding: 0.9rem !important;
  }

  body#rvnp .rvn-editor-block__remove {
    min-width: 2.35rem;
  }

  body#rvnp .rvn-editor-block--security .form-control[disabled],
  body#rvnp .rvn-editor-block--security .form-select[disabled] {
    background-color: var(--raven-surface);
  }
<?php Footer::pushStyle((string) ob_get_clean()); ?>
<?php ob_start(); ?>
  (function () {
    var configs = <?= $editorBlocksBootJson ?>;
    if (!Array.isArray(configs) || configs.length === 0) {
      return;
    }

    function isFormControl(node) {
      return node instanceof HTMLInputElement || node instanceof HTMLSelectElement || node instanceof HTMLTextAreaElement;
    }

    function normalizeTypeSlug(value) {
      return String(value || '')
        .toLowerCase()
        .replace(/[^a-z0-9-]+/g, '-')
        .replace(/-{2,}/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 80);
    }

    function typeUsesOptions(typeValue) {
      return typeValue === 'radio' || typeValue === 'checkbox' || typeValue === 'select';
    }

    function replaceNameTokens(template, index, key) {
      return String(template || '')
        .replace(/\{index\}/g, String(index))
        .replace(/\{key\}/g, String(key || ''));
    }

    function queryRowControl(row, selector) {
      if (!(row instanceof HTMLElement) || String(selector || '').trim() === '') {
        return null;
      }

      var control = row.querySelector(String(selector));
      return isFormControl(control) ? control : null;
    }

    function syncSelectPrefix(row, actionConfig) {
      if (!(row instanceof HTMLElement)) {
        return;
      }

      var selectSelector = String(actionConfig.select_selector || actionConfig.selector || '').trim();
      var addonSelector = String(actionConfig.addon_selector || '').trim();
      if (selectSelector === '' || addonSelector === '') {
        return;
      }

      var typeField = row.querySelector(selectSelector);
      var prefixAddon = row.querySelector(addonSelector);
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

    function syncOptionsVisibility(row, actionConfig) {
      if (!(row instanceof HTMLElement)) {
        return;
      }

      var selectSelector = String(actionConfig.select_selector || actionConfig.selector || '').trim();
      var wrapSelector = String(actionConfig.wrap_selector || '').trim();
      if (selectSelector === '' || wrapSelector === '') {
        return;
      }

      var typeField = row.querySelector(selectSelector);
      var optionsWrap = row.querySelector(wrapSelector);
      if (!(typeField instanceof HTMLSelectElement) || !(optionsWrap instanceof HTMLElement)) {
        return;
      }

      optionsWrap.classList.toggle('d-none', !typeUsesOptions(String(typeField.value || '').toLowerCase()));
    }

    function normalizeSlugField(row, actionConfig, changedTarget) {
      var control = changedTarget instanceof HTMLInputElement
        ? changedTarget
        : queryRowControl(row, String(actionConfig.selector || ''));
      if (!(control instanceof HTMLInputElement)) {
        return;
      }

      control.value = normalizeTypeSlug(control.value);
    }

    function runRowAction(row, actionConfig, changedTarget) {
      var actionName = String(actionConfig.action || '').trim();
      if (actionName === '') {
        return;
      }

      if (actionName === 'select-prefix') {
        syncSelectPrefix(row, actionConfig);
        return;
      }

      if (actionName === 'toggle-options') {
        syncOptionsVisibility(row, actionConfig);
        return;
      }

      if (actionName === 'normalize-type-slug') {
        normalizeSlugField(row, actionConfig, changedTarget);
      }
    }

    function reindexRows(list, config) {
      var rowSelector = String(config.row_selector || '').trim();
      if (!(list instanceof HTMLElement) || rowSelector === '') {
        return;
      }

      var rows = list.querySelectorAll(rowSelector);
      rows.forEach(function (row, index) {
        if (!(row instanceof HTMLElement)) {
          return;
        }

        var fields = Array.isArray(config.fields) ? config.fields : [];
        fields.forEach(function (fieldConfig) {
          if (typeof fieldConfig !== 'object' || fieldConfig === null) {
            return;
          }

          var selector = String(fieldConfig.selector || '').trim();
          if (selector === '') {
            return;
          }

          var controls = row.querySelectorAll(selector);
          controls.forEach(function (control) {
            if (!isFormControl(control)) {
              return;
            }

            var nameTemplate = String(fieldConfig.name || '').trim();
            if (nameTemplate !== '') {
              control.name = replaceNameTokens(nameTemplate, index, '');
              return;
            }

            var keyAttribute = String(fieldConfig.key_attribute || '').trim();
            var prefix = String(fieldConfig.name_prefix || '').trim();
            if (keyAttribute === '' || prefix === '') {
              return;
            }

            var key = String(control.getAttribute(keyAttribute) || '').trim();
            if (key === '') {
              return;
            }

            control.name = replaceNameTokens(prefix + '[{key}]', index, key);
          });
        });

        var reindexSync = Array.isArray(config.reindex_sync) ? config.reindex_sync : [];
        reindexSync.forEach(function (actionConfig) {
          if (typeof actionConfig !== 'object' || actionConfig === null) {
            return;
          }

          runRowAction(row, actionConfig, null);
        });
      });
    }

    function appendRow(list, template) {
      if (!(list instanceof HTMLElement) || !(template instanceof HTMLTemplateElement)) {
        return false;
      }

      list.appendChild(template.content.cloneNode(true));
      return true;
    }

    function initRepeater(config) {
      if (typeof config !== 'object' || config === null) {
        return;
      }

      var listId = String(config.list_id || '').trim();
      var rowSelector = String(config.row_selector || '').trim();
      if (listId === '' || rowSelector === '') {
        return;
      }

      var list = document.getElementById(listId);
      if (!(list instanceof HTMLElement)) {
        return;
      }

      var templateId = String(config.template_id || '').trim();
      var addButtonId = String(config.add_button_id || '').trim();
      var removeSelector = String(config.remove_selector || '').trim();
      var template = templateId !== '' ? document.getElementById(templateId) : null;
      var addButton = addButtonId !== '' ? document.getElementById(addButtonId) : null;

      if (addButton instanceof HTMLButtonElement && template instanceof HTMLTemplateElement) {
        addButton.addEventListener('click', function () {
          if (!appendRow(list, template)) {
            return;
          }

          reindexRows(list, config);
        });
      }

      list.addEventListener('change', function (event) {
        var target = event.target;
        if (!(target instanceof Element)) {
          return;
        }

        var row = target.closest(rowSelector);
        if (!(row instanceof HTMLElement)) {
          return;
        }

        var changeSync = Array.isArray(config.change_sync) ? config.change_sync : [];
        changeSync.forEach(function (actionConfig) {
          if (typeof actionConfig !== 'object' || actionConfig === null) {
            return;
          }

          var selector = String(actionConfig.selector || '').trim();
          if (selector === '' || !target.matches(selector)) {
            return;
          }

          runRowAction(row, actionConfig, target);
        });
      });

      if (removeSelector !== '') {
        list.addEventListener('click', function (event) {
          var target = event.target;
          if (!(target instanceof Element)) {
            return;
          }

          var removeButton = target.closest(removeSelector);
          if (!(removeButton instanceof HTMLElement)) {
            return;
          }

          var row = removeButton.closest(rowSelector);
          if (!(row instanceof HTMLElement)) {
            return;
          }

          if (row.getAttribute('data-rvn-contact-option-protected') === '1' || removeButton.hasAttribute('disabled')) {
            return;
          }

          row.remove();

          if (!list.querySelector(rowSelector) && config.ensure_one_row === true && template instanceof HTMLTemplateElement) {
            appendRow(list, template);
          }

          reindexRows(list, config);
        });
      }

      if (config.ensure_one_row === true && !list.querySelector(rowSelector) && template instanceof HTMLTemplateElement) {
        appendRow(list, template);
      }

      reindexRows(list, config);
    }

    configs.forEach(initRepeater);
  })();
<?php Footer::pushScript((string) ob_get_clean()); ?>
