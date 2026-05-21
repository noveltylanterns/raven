<?php

/**
 * RAVEN CMS
 * ~/private/lib/View/Public/PageMarkdown.php
 * Public page Markdown-to-HTML rendering helpers.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\View\Public;

/**
 * Renders lightweight Markdown used by public page body blocks.
 */
final class PageMarkdown
{
    /**
     * Converts one Markdown string into HTML for public page blocks.
     *
     * @param string $markdown Raw Markdown content.
     * @return string Rendered HTML, or an empty string when nothing remains after normalization.
     */
    public function toHtml(string $markdown): string
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        $markdown = trim($markdown);
        if ($markdown === '') {
            return '';
        }

        $codeBlockPlaceholders = [];
        $processedLines = [];
        $isInFence = false;
        $fenceChar = '';
        $fenceLength = 0;
        $fenceLines = [];
        $lines = preg_split('/\n/', $markdown) ?: [];
        foreach ($lines as $line) {
            if (!$isInFence) {
                if (preg_match('/^([`~]{3,})(?:\s*[a-z0-9_-]+)?\s*$/i', $line, $matches) === 1) {
                    $fence = (string) ($matches[1] ?? '');
                    if ($fence !== '') {
                        $isInFence = true;
                        $fenceChar = substr($fence, 0, 1);
                        $fenceLength = strlen($fence);
                        $fenceLines = [];
                        continue;
                    }
                }

                $processedLines[] = $line;
                continue;
            }

            $closingFencePattern = '/^' . preg_quote($fenceChar, '/') . '{' . $fenceLength . ',}\s*$/';
            if (preg_match($closingFencePattern, $line) === 1) {
                $token = '__RAVEN_FENCED_CODE_' . count($codeBlockPlaceholders) . '__';
                $codeBlockPlaceholders[$token] = '<pre><code>' . $this->escapeHtml(implode("\n", $fenceLines)) . '</code></pre>';
                $processedLines[] = '';
                $processedLines[] = $token;
                $processedLines[] = '';
                $isInFence = false;
                $fenceChar = '';
                $fenceLength = 0;
                $fenceLines = [];
                continue;
            }

            $fenceLines[] = $line;
        }

        if ($isInFence) {
            $token = '__RAVEN_FENCED_CODE_' . count($codeBlockPlaceholders) . '__';
            $codeBlockPlaceholders[$token] = '<pre><code>' . $this->escapeHtml(implode("\n", $fenceLines)) . '</code></pre>';
            $processedLines[] = '';
            $processedLines[] = $token;
            $processedLines[] = '';
        }

        $markdown = trim(implode("\n", $processedLines));
        if ($markdown === '') {
            return '';
        }

        $parts = [];
        $paragraphLines = [];
        $lines = preg_split('/\n/', $markdown) ?: [];
        $lineCount = count($lines);
        $lineIndex = 0;

        $flushParagraph = function () use (&$paragraphLines, &$parts): void {
            if ($paragraphLines === []) {
                return;
            }

            $paragraphText = trim(implode("\n", $paragraphLines));
            $paragraphLines = [];
            if ($paragraphText === '') {
                return;
            }

            $parts[] = '<p>' . nl2br($this->renderInline($paragraphText), false) . '</p>';
        };

        while ($lineIndex < $lineCount) {
            $line = (string) ($lines[$lineIndex] ?? '');
            $trimmedLine = trim($line);

            if ($trimmedLine === '') {
                $flushParagraph();
                $lineIndex++;
                continue;
            }

            if (isset($codeBlockPlaceholders[$trimmedLine])) {
                $flushParagraph();
                $parts[] = (string) $codeBlockPlaceholders[$trimmedLine];
                $lineIndex++;
                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.+)$/', $trimmedLine, $headingMatches) === 1) {
                $flushParagraph();
                $level = strlen((string) ($headingMatches[1] ?? '#'));
                $text = (string) ($headingMatches[2] ?? '');
                $parts[] = '<h' . $level . '>' . $this->renderInline($text) . '</h' . $level . '>';
                $lineIndex++;
                continue;
            }

            if (preg_match('/^[-*+]\s+(.+)$/', $trimmedLine, $listMatches) === 1) {
                $flushParagraph();
                $items = [];
                while ($lineIndex < $lineCount) {
                    $listLine = trim((string) ($lines[$lineIndex] ?? ''));
                    if (preg_match('/^[-*+]\s+(.+)$/', $listLine, $itemMatches) !== 1) {
                        break;
                    }

                    $items[] = '<li>' . $this->renderInline((string) ($itemMatches[1] ?? '')) . '</li>';
                    $lineIndex++;
                }

                if ($items !== []) {
                    $parts[] = '<ul>' . implode('', $items) . '</ul>';
                }
                continue;
            }

            if (preg_match('/^\d+\.\s+(.+)$/', $trimmedLine, $orderedListMatches) === 1) {
                $flushParagraph();
                $items = [];
                while ($lineIndex < $lineCount) {
                    $listLine = trim((string) ($lines[$lineIndex] ?? ''));
                    if (preg_match('/^\d+\.\s+(.+)$/', $listLine, $itemMatches) !== 1) {
                        break;
                    }

                    $items[] = '<li>' . $this->renderInline((string) ($itemMatches[1] ?? '')) . '</li>';
                    $lineIndex++;
                }

                if ($items !== []) {
                    $parts[] = '<ol>' . implode('', $items) . '</ol>';
                }
                continue;
            }

            if (preg_match('/^>\s?.+$/', $trimmedLine) === 1) {
                $flushParagraph();
                $quoteLines = [];
                while ($lineIndex < $lineCount) {
                    $quoteLine = trim((string) ($lines[$lineIndex] ?? ''));
                    if (preg_match('/^>\s?.+$/', $quoteLine) !== 1) {
                        break;
                    }

                    $quoteLines[] = (string) preg_replace('/^>\s?/', '', $quoteLine);
                    $lineIndex++;
                }

                if ($quoteLines !== []) {
                    $quoteText = implode("\n", $quoteLines);
                    $parts[] = '<blockquote><p>' . nl2br($this->renderInline($quoteText), false) . '</p></blockquote>';
                }
                continue;
            }

            $paragraphLines[] = $line;
            $lineIndex++;
        }

        $flushParagraph();
        return implode("\n", $parts);
    }

    /**
     * Renders inline Markdown fragments used inside paragraphs, headings, and lists.
     *
     * @param string $text Raw inline Markdown text.
     * @return string Rendered inline HTML.
     */
    public function renderInline(string $text): string
    {
        $escaped = $this->escapeHtml($text);
        $codePlaceholders = [];
        $escaped = preg_replace_callback('/`([^`]+)`/', function (array $matches) use (&$codePlaceholders): string {
            $token = '%%RAVENCODE' . count($codePlaceholders) . '%%';
            $codePlaceholders[$token] = '<code>' . $this->escapeHtml((string) ($matches[1] ?? '')) . '</code>';
            return $token;
        }, $escaped) ?? $escaped;

        $escaped = preg_replace_callback('/\[(.+?)\]\((.+?)\)/', function (array $matches): string {
            $label = (string) ($matches[1] ?? '');
            $url = $this->normalizeLinkUrl((string) ($matches[2] ?? ''));
            if ($url === null) {
                return $this->escapeHtml($label);
            }

            return '<a href="' . $this->escapeHtml($url) . '">' . $this->escapeHtml($label) . '</a>';
        }, $escaped) ?? $escaped;

        $escaped = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $escaped) ?? $escaped;
        $escaped = preg_replace('/__([^_]+)__/', '<strong>$1</strong>', $escaped) ?? $escaped;
        $escaped = preg_replace('/\*([^*\n]+)\*/', '<em>$1</em>', $escaped) ?? $escaped;
        $escaped = preg_replace('/_([^_\n]+)_/', '<em>$1</em>', $escaped) ?? $escaped;

        foreach ($codePlaceholders as $token => $html) {
            $escaped = str_replace($token, $html, $escaped);
        }

        return $escaped;
    }

    /**
     * Normalizes one Markdown link URL to the supported public schemes.
     *
     * @param string $url Raw Markdown link target.
     * @return string|null Safe public URL, or null when the target is rejected.
     */
    public function normalizeLinkUrl(string $url): ?string
    {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($url === '') {
            return null;
        }

        if (preg_match('/^(<[^>]+>|\\S+)(?:\\s+["\'][^"\']*["\'])?$/u', $url, $matches) === 1) {
            $url = (string) ($matches[1] ?? $url);
        }
        $url = trim($url, "<> \t\n\r\0\x0B");
        if ($url === '') {
            return null;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1 || str_starts_with($url, '//')) {
            return null;
        }

        if (str_starts_with($url, '#')) {
            return $url;
        }

        if (str_starts_with($url, '/')) {
            return $url;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== '') {
            if (!in_array($scheme, ['http', 'https'], true)) {
                return null;
            }

            return filter_var($url, FILTER_VALIDATE_URL) === false ? null : $url;
        }

        if (str_contains($url, '\\')) {
            return null;
        }

        return $url;
    }

    /**
     * Escapes one string for safe HTML output.
     *
     * @param string $value Raw text fragment.
     * @return string HTML-escaped text.
     */
    private function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
