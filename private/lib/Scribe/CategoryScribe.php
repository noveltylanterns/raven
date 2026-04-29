<?php

/**
 * RAVEN CMS
 * ~/private/lib/Scribe/CategoryScribe.php
 * Category-specific write-side persistence helper.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Scribe;

/**
 * Owns category SQL mutations on top of the shared taxonomy write base.
 *
 * CategoryRead keeps the read-heavy listing and lookup queries while this
 * scribe narrows the shared taxonomy mutation rules to the categories tables.
 */
final class CategoryScribe extends TaxonomyScribe
{
    /**
     * Returns the logical taxonomy table name for category writes.
     *
     * @return string Unprefixed taxonomy table name.
     */
    protected function taxonomyTableKey(): string
    {
        return 'categories';
    }

    /**
     * Returns the logical page-link table name for category writes.
     *
     * @return string Unprefixed page-link table name.
     */
    protected function relationTableKey(): string
    {
        return 'page_categories';
    }

    /**
     * Returns the join-table column that points back to one category id.
     *
     * @return string Plain SQL column name used inside the link table.
     */
    protected function relationColumn(): string
    {
        return 'category';
    }
}
