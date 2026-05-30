<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/Panel/EditorBlocks.php
 * Shared panel repeater-row wrapper classes for route-scoped editor block UIs.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\View\Panel;

/**
 * Provides thin wrapper-class variants for repeatable panel editor rows.
 *
 * This class intentionally stays presentation-only. Route templates keep their
 * own field semantics, row shaping, and domain-specific normalization logic.
 */
final class EditorBlocks
{
    /**
     * Returns one shared visual variant for repeatable editor-block rows.
     *
     * @param string $variant Visual variant name used by panel/editor templates.
     * @return array{
     *   row_class: string,
     *   remove_button_class: string,
     *   compact_remove_button_class: string,
     *   toolbar_class: string
     * } Shared classes for one block family.
     */
    public function layout(string $variant = 'default'): array
    {
        $normalizedVariant = strtolower(trim($variant));
        // Unknown variants fall back to the shared default class set.
        if (!in_array($normalizedVariant, ['default', 'contact', 'security', 'page_body', 'task'], true)) {
            $normalizedVariant = 'default';
        }

        $variantClass = match ($normalizedVariant) {
            'contact' => 'rvn-editor-block--contact',
            'security' => 'rvn-editor-block--security',
            'page_body' => 'rvn-editor-block--page-body',
            'task' => 'rvn-editor-block--task',
            default => 'rvn-editor-block--default',
        };

        $rowPaddingClass = $normalizedVariant === 'page_body' ? 'p-3 mb-3' : 'p-2 mb-2';

        return [
            'row_class' => 'rvn-editor-block ' . $variantClass . ' border rounded ' . $rowPaddingClass,
            'remove_button_class' => 'btn btn-danger btn-sm ms-2 rvn-editor-block__remove',
            'compact_remove_button_class' => 'btn btn-danger btn-sm rvn-editor-block__remove',
            'toolbar_class' => 'd-flex align-items-center justify-content-between gap-2 mb-2',
        ];
    }
}
