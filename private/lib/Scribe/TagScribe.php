<?php

/**
 * RAVEN CMS
 * ~/private/lib/Scribe/TagScribe.php
 * Tag-specific write-side persistence helper.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Scribe;

/**
 * Owns tag SQL mutations on top of the shared taxonomy write base.
 *
 * TagRead keeps the read-heavy listing and lookup queries while this scribe
 * narrows the shared taxonomy mutation rules to the tags tables.
 */
final class TagScribe extends TaxonomyScribe
{
    /**
     * Returns the logical taxonomy table name for tag writes.
     *
     * @return string Unprefixed taxonomy table name.
     */
    protected function taxonomyTableKey(): string
    {
        return 'tags';
    }

    /**
     * Returns the logical page-link table name for tag writes.
     *
     * @return string Unprefixed page-link table name.
     */
    protected function relationTableKey(): string
    {
        return 'page_tags';
    }

    /**
     * Returns the join-table column that points back to one tag id.
     *
     * @return string Plain SQL column name used inside the link table.
     */
    protected function relationColumn(): string
    {
        return 'tag';
    }
}
