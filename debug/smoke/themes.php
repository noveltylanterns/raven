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
require_once dirname(__DIR__, 2) . '/private/lib/Security/InputSanitizer.php';
require_once dirname(__DIR__, 2) . '/private/lib/View/PublicTemplateResolver.php';
require_once dirname(__DIR__, 2) . '/private/lib/View/PublicTemplatePipeline.php';

use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\View\PublicTemplatePipeline;
use Raven\Lib\View\PublicTemplateResolver;
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
        $this->cacheDirectory = $this->root . '/.tmp/template_tag_cache';
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
        $tmpDirectory = dirname($this->cacheDirectory);
        if (!is_dir($tmpDirectory) && !mkdir($tmpDirectory, 0775, true) && !is_dir($tmpDirectory)) {
            throw new RuntimeException('Failed to create temporary directory for theme smoke.');
        }
        if (!is_dir($this->cacheDirectory) && !mkdir($this->cacheDirectory, 0775, true) && !is_dir($this->cacheDirectory)) {
            throw new RuntimeException('Failed to create template tag cache directory for theme smoke.');
        }

        $engine = new TemplateTagEngine($this->cacheDirectory);
        $fixtureFile = $this->root . '/.tmp/theme-tag-smoke-' . $this->runId . '.php';
        $fixtureSource = <<<'PHP'
escaped_title={page:title}
raw_snippet={raw:snippet:html}
object_name={obj:name}
if_published:{if page:published}yes{/if}
if_not_feature:{if not feature:enabled}yes{/if}
if_missing_not:{if not feature:missing}yes{/if}
missing_value=[{page:missing}]
pagination_first={pagination:links:0:label}
loop_start
{each pages}
- {item:title}|{item:url}|{item:flags:featured}
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
                ],
                'page' => [
                    'title' => 'Hello <Raven>',
                    'published' => true,
                ],
                'snippet' => [
                    'html' => '<p><strong>Trusted</strong> HTML</p>',
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
                        'url' => '/first',
                        'flags' => [
                            'featured' => true,
                        ],
                    ],
                    [
                        'title' => 'Second',
                        'url' => '/second',
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
                'raw_snippet=<p><strong>Trusted</strong> HTML</p>',
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

            $this->smokeTemplateRedirects($engine);
            $this->smokeRenderRealTemplates($engine);
            $this->events[] = 'smoke_result=PASS';
            $this->events[] = 'run_id=' . $this->runId;
        } finally {
            @unlink($fixtureFile);
        }
    }

    private function smokeTemplateRedirects(TemplateTagEngine $engine): void
    {
        $root = $this->root . '/.tmp/theme-redirect-smoke-' . $this->runId;
        if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
            throw new RuntimeException('Failed to create redirect smoke directory.');
        }

        $fixtures = [
            'redirect-404' => ['tag' => '{redirect:404}', 'expect' => 'Not Found', 'status' => 404],
            'redirect-denied' => ['tag' => '{redirect:denied}', 'expect' => 'Permission Denied', 'status' => 403],
            'redirect-disabled' => ['tag' => '{redirect:disabled}', 'expect' => 'Site Disabled', 'status' => 503],
        ];

        try {
            foreach ($fixtures as $name => $fixture) {
                $path = $root . '/' . $name . '.php';
                if (file_put_contents($path, (string) ($fixture['tag'] ?? ''), LOCK_EX) === false) {
                    throw new RuntimeException('Failed to write redirect smoke fixture: ' . $name);
                }
            }

            $pipeline = new PublicTemplatePipeline(new PublicTemplateResolver(new InputSanitizer()));
            $data = [
                'redirect' => [
                    '404' => '__RVN_TEMPLATE_REDIRECT__:status/404',
                    'disabled' => '__RVN_TEMPLATE_REDIRECT__:status/disabled',
                    'denied' => '__RVN_TEMPLATE_REDIRECT__:status/denied',
                ],
            ];

            foreach ($fixtures as $name => $fixture) {
                http_response_code(200);
                $output = $pipeline->render(
                    $name,
                    $data,
                    null,
                    fn (string $file, array $payload): string => $engine->renderFile($file, $payload),
                    $root,
                    $this->root . '/private/tpl'
                );
                $expected = (string) ($fixture['expect'] ?? '');
                $this->assert(str_contains($output, $expected), 'Redirect tag did not resolve expected message view: ' . $name);
                $expectedStatus = (int) ($fixture['status'] ?? 200);
                $this->assert(http_response_code() === $expectedStatus, 'Redirect tag did not set expected status for: ' . $name);
                $this->events[] = 'template_redirect=' . $name;
            }
        } finally {
            foreach (array_keys($fixtures) as $name) {
                @unlink($root . '/' . $name . '.php');
            }
            @rmdir($root);
        }
    }

    private function smokeRenderRealTemplates(TemplateTagEngine $engine): void
    {
        $data = [
            'site' => [
                'name' => 'Raven CMS',
                'domain' => 'localhost',
                'url' => 'http://localhost',
                'current_url' => 'http://localhost/',
            ],
            'theme' => [
                'slug' => 'raven',
                'css' => 'raven',
                'url' => 'http://localhost/theme/raven',
            ],
            'panel' => [
                'slug' => 'panel',
                'url' => 'http://localhost/panel',
            ],
            'meta' => [
                'title' => 'Smoke',
                'desc' => 'Theme smoke render.',
                'image' => 'http://localhost/og.png',
                'url' => 'http://localhost',
            ],
            'content' => '<main>Smoke Content</main>',
            'page' => [
                'title' => 'Smoke Page',
                'title_show' => true,
                'channel_id' => 7,
                'content' => [
                    [
                        'html' => '<p>Smoke block</p>',
                        'css_id' => '',
                        'class' => 'raven-page-extended-block raven-page-extended-block-1',
                    ],
                ],
            ],
            'pages' => [],
            'category' => ['name' => 'Smoke Category', 'slug' => 'smoke-category'],
            'tag' => ['name' => 'Smoke Tag', 'slug' => 'smoke-tag'],
            'profile' => [
                'display_name_resolved' => 'Smoke User',
                'username' => 'smoke',
                'contact_profiles' => [],
            ],
            'group' => [
                'name' => 'Smoke Group',
                'member_count' => 0,
            ],
            'members' => [],
            'pagination' => [
                'current' => 1,
                'total_pages' => 1,
                'links' => [],
            ],
        ];

        $targets = [
            [
                'label' => 'stock/home.php',
                'path' => is_dir($this->root . '/public/theme/raven/tpl')
                    ? $this->root . '/public/theme/raven/tpl/home.php'
                    : $this->root . '/themebak/tpl/home.php',
            ],
            [
                'label' => 'stock/wrapper.php',
                'path' => is_dir($this->root . '/public/theme/raven/tpl')
                    ? $this->root . '/public/theme/raven/tpl/wrapper.php'
                    : $this->root . '/themebak/tpl/wrapper.php',
            ],
            [
                'label' => 'fallback/home.php',
                'path' => $this->root . '/private/tpl/home.php',
            ],
            [
                'label' => 'fallback/wrapper.php',
                'path' => $this->root . '/private/tpl/wrapper.php',
            ],
        ];

        foreach ($targets as $target) {
            $path = (string) ($target['path'] ?? '');
            $label = (string) ($target['label'] ?? basename($path));
            if (!is_file($path)) {
                $this->events[] = 'template_skipped=' . $label;
                continue;
            }

            $output = $engine->renderFile($path, $data);
            $this->assert(is_string($output), 'Template render returned non-string output.');
            if (str_ends_with($label, 'home.php')) {
                $this->assert(str_contains($output, 'Smoke block'), 'Home template did not render page:content block rows.');
            }
            $this->events[] = 'template_rendered=' . $label;
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
