<?php

/**
 * RAVEN CMS
 * ~/build/docs/prep-audit.php
 * Prep-work audit helper for documentation rewrite readiness checks.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

final class PrepAudit
{
    /** @var array<int, string> */
    private array $headerRoots = ['private', 'public', 'panel'];

    /** @var array<int, string> */
    private array $codeRoots = ['private/sys', 'private/lib'];

    private const LIMIT = 25;

    public function run(): int
    {
        $header = $this->auditHeaders();
        $symbol = $this->auditSymbolDocblocks();
        $control = $this->auditControlComments();

        $this->printHeaderSummary($header);
        $this->printSymbolSummary($symbol);
        $this->printControlSummary($control);

        $hasErrors = $header['bad_line1'] > 0
            || $header['missing_raven'] > 0
            || $header['missing_docs_url'] > 0;

        return $hasErrors ? 1 : 0;
    }

    /**
     * @return array{
     *   total_php: int,
     *   bad_line1: int,
     *   missing_raven: int,
     *   missing_docs_url: int,
     *   generic_descriptions: int,
     *   bad_line1_samples: array<int, string>,
     *   missing_raven_samples: array<int, string>,
     *   missing_docs_samples: array<int, string>,
     *   generic_description_samples: array<int, string>
     * }
     */
    private function auditHeaders(): array
    {
        $result = [
            'total_php' => 0,
            'bad_line1' => 0,
            'missing_raven' => 0,
            'missing_docs_url' => 0,
            'generic_descriptions' => 0,
            'bad_line1_samples' => [],
            'missing_raven_samples' => [],
            'missing_docs_samples' => [],
            'generic_description_samples' => [],
        ];

        foreach ($this->phpFilesInRoots($this->headerRoots) as $path) {
            $result['total_php']++;
            $lines = @file($path, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                continue;
            }

            $line1 = $lines[0] ?? '';
            if (trim($line1) !== '<?php') {
                $result['bad_line1']++;
                $this->appendSample($result['bad_line1_samples'], $path);
            }

            $headerChunk = implode("\n", array_slice($lines, 0, 20));
            if (!str_contains($headerChunk, 'RAVEN CMS')) {
                $result['missing_raven']++;
                $this->appendSample($result['missing_raven_samples'], $path);
            }

            if (!str_contains($headerChunk, 'Docs: https://lanterns.io/raven')) {
                $result['missing_docs_url']++;
                $this->appendSample($result['missing_docs_samples'], $path);
            }

            foreach (array_slice($lines, 0, 14) as $lineNumber => $line) {
                if (!preg_match('/^\s*\*\s+(.*?)\s*$/', $line, $match)) {
                    continue;
                }

                $content = trim($match[1]);
                if ($content === 'Admin panel view template for this screen.') {
                    $result['generic_descriptions']++;
                    $this->appendSample(
                        $result['generic_description_samples'],
                        $path . ':' . (string) ($lineNumber + 1)
                    );
                }
            }
        }

        return $result;
    }

    /**
     * @return array{
     *   missing_class_doc: int,
     *   missing_method_doc: int,
     *   class_samples: array<int, string>,
     *   method_samples: array<int, string>
     * }
     */
    private function auditSymbolDocblocks(): array
    {
        $result = [
            'missing_class_doc' => 0,
            'missing_method_doc' => 0,
            'class_samples' => [],
            'method_samples' => [],
        ];

        $classDeclTokens = [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM];
        $allowedBeforeClass = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_FINAL, T_ABSTRACT, T_READONLY];
        $allowedBeforeMethod = [
            T_WHITESPACE,
            T_COMMENT,
            T_DOC_COMMENT,
            T_PUBLIC,
            T_PROTECTED,
            T_PRIVATE,
            T_STATIC,
            T_FINAL,
            T_ABSTRACT,
            T_ATTRIBUTE,
            T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG,
        ];

        foreach ($this->phpFilesInRoots($this->codeRoots) as $path) {
            $code = @file_get_contents($path);
            if (!is_string($code)) {
                continue;
            }

            $tokens = token_get_all($code);
            $tokenCount = count($tokens);

            for ($i = 0; $i < $tokenCount; $i++) {
                $token = $tokens[$i];
                if (!is_array($token)) {
                    continue;
                }

                $id = $token[0];
                if (in_array($id, $classDeclTokens, true)) {
                    $previous = $this->previousNonWhitespaceToken($tokens, $i - 1);
                    if (is_array($previous) && $previous[0] === T_NEW) {
                        continue;
                    }
                    if (is_array($previous) && defined('T_DOUBLE_COLON') && $previous[0] === T_DOUBLE_COLON) {
                        continue;
                    }
                    if (is_string($previous) && $previous === '::') {
                        continue;
                    }

                    if (!$this->hasDocblockBefore($tokens, $i, $allowedBeforeClass)) {
                        $result['missing_class_doc']++;
                        $this->appendSample($result['class_samples'], $path . ':' . $token[2]);
                    }
                    continue;
                }

                if ($id === T_FUNCTION) {
                    // Skip closures (`function (`) and anonymous assignments.
                    $next = $this->nextNonWhitespaceToken($tokens, $i + 1);
                    if (!is_array($next) || $next[0] !== T_STRING) {
                        continue;
                    }

                    if (!$this->hasDocblockBefore($tokens, $i, $allowedBeforeMethod)) {
                        $result['missing_method_doc']++;
                        $this->appendSample($result['method_samples'], $path . ':' . $token[2]);
                    }
                }
            }
        }

        return $result;
    }

    /**
     * @return array{
     *   missing_control_comment: int,
     *   control_samples: array<int, string>
     * }
     */
    private function auditControlComments(): array
    {
        $result = [
            'missing_control_comment' => 0,
            'control_samples' => [],
        ];

        foreach ($this->phpFilesInRoots($this->codeRoots) as $path) {
            $lines = @file($path, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                continue;
            }

            $tokens = token_get_all((string) file_get_contents($path));
            foreach ($tokens as $token) {
                if (!is_array($token)) {
                    continue;
                }

                $tokenId = $token[0];
                if (!in_array($tokenId, [T_IF, T_FOREACH, T_TRY], true)) {
                    continue;
                }

                $line = (int) $token[2];
                if ($line < 1 || $line > count($lines)) {
                    continue;
                }

                if ($this->hasNearbyComment($lines, $line)) {
                    continue;
                }

                $result['missing_control_comment']++;
                $this->appendSample(
                    $result['control_samples'],
                    $path . ':' . $line . ':' . token_name($tokenId)
                );
            }
        }

        return $result;
    }

    /**
     * @param array<int, mixed> $tokens
     * @param array<int, int> $allowedTokenTypes
     */
    private function hasDocblockBefore(array $tokens, int $index, array $allowedTokenTypes): bool
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                if (trim((string) $token) === '') {
                    continue;
                }
                return false;
            }

            $id = $token[0];
            if ($id === T_DOC_COMMENT) {
                return true;
            }

            if (!in_array($id, $allowedTokenTypes, true)) {
                return false;
            }
        }

        return false;
    }

    /**
     * @param array<int, mixed> $tokens
     * @return mixed
     */
    private function nextNonWhitespaceToken(array $tokens, int $startIndex)
    {
        $count = count($tokens);
        for ($i = $startIndex; $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_array($token)) {
                if ($token[0] === T_WHITESPACE) {
                    continue;
                }
                return $token;
            }

            if (trim((string) $token) === '') {
                continue;
            }

            return $token;
        }

        return null;
    }

    /**
     * @param array<int, mixed> $tokens
     * @return mixed
     */
    private function previousNonWhitespaceToken(array $tokens, int $startIndex)
    {
        for ($i = $startIndex; $i >= 0; $i--) {
            $token = $tokens[$i];
            if (is_array($token)) {
                if ($token[0] === T_WHITESPACE) {
                    continue;
                }
                return $token;
            }

            if (trim((string) $token) === '') {
                continue;
            }

            return $token;
        }

        return null;
    }

    /**
     * Checks the current line and up to two lines above for comment markers.
     *
     * @param array<int, string> $lines
     */
    private function hasNearbyComment(array $lines, int $line): bool
    {
        $start = max(1, $line - 2);
        for ($ln = $start; $ln <= $line; $ln++) {
            $text = trim($lines[$ln - 1] ?? '');
            if ($text === '') {
                continue;
            }

            if (str_starts_with($text, '//')
                || str_starts_with($text, '/*')
                || str_starts_with($text, '*')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $samples
     */
    private function appendSample(array &$samples, string $value): void
    {
        if (count($samples) >= self::LIMIT) {
            return;
        }
        $samples[] = $value;
    }

    /**
     * @param array<int, string> $roots
     * @return array<int, string>
     */
    private function phpFilesInRoots(array $roots): array
    {
        $paths = [];
        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $entry) {
                if (!$entry->isFile()) {
                    continue;
                }

                $path = $entry->getPathname();
                if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'php') {
                    continue;
                }

                // private/dat contains generated runtime state and seed fragments, not source
                // modules; those files intentionally do not carry the source-file documentation header.
                $normalizedPath = str_replace('\\', '/', $path);
                if (str_contains($normalizedPath, 'private/dat/')) {
                    continue;
                }

                $paths[] = $normalizedPath;
            }
        }

        sort($paths, SORT_NATURAL | SORT_FLAG_CASE);
        return $paths;
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function printHeaderSummary(array $summary): void
    {
        echo "[header]\n";
        echo 'total_php=' . $summary['total_php'] . "\n";
        echo 'bad_line1=' . $summary['bad_line1'] . "\n";
        echo 'missing_raven=' . $summary['missing_raven'] . "\n";
        echo 'missing_docs_url=' . $summary['missing_docs_url'] . "\n";
        echo 'generic_descriptions=' . $summary['generic_descriptions'] . "\n";
        $this->printSamples('bad_line1_samples', $summary['bad_line1_samples']);
        $this->printSamples('missing_raven_samples', $summary['missing_raven_samples']);
        $this->printSamples('missing_docs_samples', $summary['missing_docs_samples']);
        $this->printSamples('generic_description_samples', $summary['generic_description_samples']);
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function printSymbolSummary(array $summary): void
    {
        echo "[symbols]\n";
        echo 'missing_class_doc=' . $summary['missing_class_doc'] . "\n";
        echo 'missing_method_doc=' . $summary['missing_method_doc'] . "\n";
        $this->printSamples('missing_class_doc_samples', $summary['class_samples']);
        $this->printSamples('missing_method_doc_samples', $summary['method_samples']);
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function printControlSummary(array $summary): void
    {
        echo "[control]\n";
        echo 'missing_control_comment=' . $summary['missing_control_comment'] . "\n";
        $this->printSamples('missing_control_comment_samples', $summary['control_samples']);
    }

    /**
     * @param array<int, string> $samples
     */
    private function printSamples(string $label, array $samples): void
    {
        if ($samples === []) {
            return;
        }

        echo $label . ":\n";
        foreach ($samples as $sample) {
            echo '- ' . $sample . "\n";
        }
    }
}

$audit = new PrepAudit();
exit($audit->run());
