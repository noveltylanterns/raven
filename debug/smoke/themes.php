<?php

/**
 * RAVEN CMS
 * ~/debug/smoke/themes.php
 * Smoke checks for public-theme brace-tag rendering behavior.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', '0');

require_once dirname(__DIR__, 2) . '/private/sys/Core/Support/Helpers.php';
require_once dirname(__DIR__, 2) . '/private/sys/Core/View/TemplateTagEngine.php';

use Raven\Core\View\TemplateTagEngine;

final class ThemeTemplateSmokeRunner
{
    private string $root;
    private string $cacheDirectory;
    private int $runId;
    /** @var array<int, string> */
    private array $events = [];

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/');
        $this->cacheDirectory = $this->root . '/private/tmp/template_tag_cache';
        $this->runId = time();
    }

    /**
     * @return array<int, string>
     */
    public function events(): array
    {
        return $this->events;
    }

    public function run(): void
    {
        $engine = new TemplateTagEngine($this->cacheDirectory);
        $fixtureFile = $this->root . '/private/tmp/theme-tag-smoke-' . $this->runId . '.php';
        $fixtureSource = <<<'PHP'
escaped_title={page:title}
raw_body={raw:page:content}
object_name={obj:name}
if_published:{if page:published}yes{/if}
if_not_feature:{if not feature:enabled}yes{/if}
if_missing_not:{if not feature:missing}yes{/if}
missing_value=[{page:missing}]
pagination_first={pagination:links:0:label}
loop_start
{each pages}
- {item:title}|{item:public_path}|{item:flags:featured}
{/each}
loop_end
nested_loop
{each pagination:links}[{item:label}:{if item:is_current}current{/if}]{/each}
php_native=<?php $rvnLocal = 'OK'; echo $rvnLocal; ?>
PHP;

        if (file_put_contents($fixtureFile, $fixtureSource, LOCK_EX) === false) {
            throw new RuntimeException('Failed to write temporary tag fixture.');
        }

        try {
            $data = [
                'site' => [
                    'name' => 'Raven CMS',
                    'public_theme_css' => 'raven',
                ],
                'page' => [
                    'title' => 'Hello <Raven>',
                    'content' => '<p><strong>Trusted</strong> HTML</p>',
                    'published' => true,
                ],
                'feature' => [
                    'enabled' => false,
                ],
                'obj' => (object) [
                    'name' => 'Corvid',
                ],
                'pages' => [
                    [
                        'title' => 'First & One',
                        'public_path' => '/first',
                        'flags' => [
                            'featured' => true,
                        ],
                    ],
                    [
                        'title' => 'Second',
                        'public_path' => '/second',
                        'flags' => [
                            'featured' => false,
                        ],
                    ],
                ],
                'pagination' => [
                    'links' => [
                        ['label' => '1', 'is_current' => false],
                        ['label' => '2', 'is_current' => true],
                    ],
                ],
            ];

            $fixtureMtime = @filemtime($fixtureFile);
            $compiledPath = $this->cacheDirectory . '/tag-template-'
                . sha1($fixtureFile . '|' . (string) ($fixtureMtime === false ? 0 : (int) $fixtureMtime))
                . '.php';

            $renderedA = $engine->renderFile($fixtureFile, $data);
            $this->assert(is_file($compiledPath), 'Compiled template cache file was not created.');
            $compiledMtimeA = (int) (@filemtime($compiledPath) ?: 0);
            if ($compiledMtimeA < 1) {
                throw new RuntimeException('Compiled template mtime is invalid.');
            }

            usleep(20000);
            $renderedB = $engine->renderFile($fixtureFile, $data);
            $compiledMtimeB = (int) (@filemtime($compiledPath) ?: 0);

            $this->assert($renderedA === $renderedB, 'Rendered output changed between cached renders.');
            $this->assert($compiledMtimeA === $compiledMtimeB, 'Compiled cache file changed unexpectedly between renders.');

            $expectedSnippets = [
                'escaped_title=Hello &lt;Raven&gt;',
                'raw_body=<p><strong>Trusted</strong> HTML</p>',
                'object_name=Corvid',
                'if_published:yes',
                'if_not_feature:yes',
                'if_missing_not:yes',
                'missing_value=[]',
                'pagination_first=1',
                'loop_start',
                '- First &amp; One|/first|1',
                '- Second|/second|',
                'loop_end',
                'nested_loop',
                '[1:][2:current]',
                'php_native=OK',
            ];
            $lastPosition = -1;
            foreach ($expectedSnippets as $snippet) {
                $position = strpos($renderedA, $snippet);
                $this->assert($position !== false, 'Missing expected output snippet: ' . $snippet);
                $this->assert($position >= $lastPosition, 'Output ordering mismatch around: ' . $snippet);
                $lastPosition = $position;
            }

            $this->events[] = 'fixture_render=ok';
            $this->events[] = 'cache_compile=ok';
            $this->events[] = 'cache_reuse=ok';

            $this->smokeRenderRealTemplates($engine);
            $this->events[] = 'smoke_result=PASS';
            $this->events[] = 'run_id=' . $this->runId;
        } finally {
            @unlink($fixtureFile);
        }
    }

    private function smokeRenderRealTemplates(TemplateTagEngine $engine): void
    {
        $data = [
            'site' => [
                'name' => 'Raven CMS',
                'public_theme' => 'raven',
                'public_theme_css' => 'raven',
                'panel_path' => 'panel',
                'domain' => 'localhost',
                'current_url' => 'http://localhost/',
            ],
            'view_meta' => [
                'title' => 'Smoke',
                'description' => 'Theme smoke render.',
                'document_title' => 'Smoke [Raven CMS]',
            ],
            'content' => '<main>Smoke Content</main>',
            'page' => [
                'title' => 'Smoke Page',
                'content' => '<p>Smoke body</p>',
                'display_title_resolved' => true,
                'extended_blocks' => [],
            ],
            'pages' => [],
            'galleryEnabled' => false,
            'galleryImages' => [],
            'category' => ['name' => 'Smoke Category', 'slug' => 'smoke-category'],
            'tag' => ['name' => 'Smoke Tag', 'slug' => 'smoke-tag'],
            'profile' => [
                'display_name_resolved' => 'Smoke User',
                'username' => 'smoke',
                'contact_profiles' => [],
            ],
            'group' => [
                'name' => 'Smoke Group',
                'member_count_resolved' => 0,
            ],
            'members' => [],
            'pagination' => [
                'current' => 1,
                'total_pages' => 1,
                'links' => [],
            ],
        ];

        $targets = [
            $this->root . '/public/theme/raven/vis/home.php',
            $this->root . '/public/theme/raven/vis/wrapper.php',
            $this->root . '/private/vis/home.php',
            $this->root . '/private/vis/wrapper.php',
        ];

        foreach ($targets as $target) {
            if (!is_file($target)) {
                throw new RuntimeException('Template file missing for smoke: ' . $target);
            }

            $output = $engine->renderFile($target, $data);
            $this->assert(is_string($output), 'Template render returned non-string output.');
            $this->events[] = 'template_rendered=' . basename(dirname($target)) . '/' . basename($target);
        }
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}

$runner = new ThemeTemplateSmokeRunner(dirname(__DIR__, 2));

try {
    $runner->run();
    foreach ($runner->events() as $event) {
        echo $event . PHP_EOL;
    }
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'smoke_result=FAIL' . PHP_EOL);
    fwrite(STDERR, 'error=' . $exception->getMessage() . PHP_EOL);
    foreach ($runner->events() as $event) {
        fwrite(STDERR, $event . PHP_EOL);
    }
    exit(1);
}
