<?php

/**
 * RAVEN CMS
 * ~/private/sys/Controller/Public/FormController.php
 * Split public form controller for embedded-form submission routes.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Core\Controller\Public;

use Closure;
use Raven\Lib\Extension\EmbeddedFormRuntimeInterface;
use Raven\Lib\Extension\EmbeddedFormRuntimeService;
use Raven\Lib\Extension\EmbeddedShortcodeRuntimeInterface;

/**
 * Handles split public embedded-form submission routes.
 */
final class FormController
{
    private RequestContext $context;
    private Closure $extensionServicesProvider;
    /** @var array<string, EmbeddedShortcodeRuntimeInterface|EmbeddedFormRuntimeInterface> */
    private array $embeddedFormRuntimes = [];
    private bool $embeddedFormRuntimesLoaded = false;
    private ?EmbeddedFormRuntimeService $embeddedFormRuntimeService = null;

    /**
     * @param RequestContext $context Shared public request context.
     * @param callable(?string=): array<string, mixed> $extensionServicesProvider Lazy extension-services resolver.
     * @return void
     */
    public function __construct(RequestContext $context, callable $extensionServicesProvider)
    {
        $this->context = $context;
        $this->extensionServicesProvider = Closure::fromCallable($extensionServicesProvider);
    }

    /**
     * Handles one public embedded-form submission request by type + slug.
     *
     * @param string $type Normalized embedded form type slug.
     * @param string $formSlug Normalized embedded form slug.
     * @return void
     */
    public function submitEmbeddedForm(string $type, string $formSlug): void
    {
        $runtime = $this->embeddedFormRuntimeService()->runtime($type, $this->embeddedFormRuntimes());
        if ($runtime === null) {
            $this->context->notFound();
            return;
        }

        if (!$this->embeddedFormRuntimeService()->isRuntimeEnabled($runtime)) {
            $this->context->notFound();
            return;
        }

        // Content-only runtimes have no submit handler, so reject submit posts.
        if (!$runtime instanceof EmbeddedFormRuntimeInterface) {
            $this->context->notFound();
            return;
        }

        $slug = $this->context->input()->slug($formSlug);
        if ($slug === null) {
            $this->context->notFound();
            return;
        }

        $returnPath = $this->embeddedFormRuntimeService()->sanitizeReturnPath((string) ($_POST['return_path'] ?? '/'));

        try {
            $runtime->submit($slug, $returnPath, fn (): ?string => $this->context->validatePublicCaptcha());
        } catch (\Throwable $exception) {
            error_log(
                'Raven embedded form submit failed for type "'
                . $runtime->type()
                . '": '
                . $exception->getMessage()
            );
            $this->context->notFound();
        }
    }

    /**
     * Returns the embedded-form runtime service for the current request.
     *
     * @return EmbeddedFormRuntimeService Shared embedded-form runtime service.
     */
    private function embeddedFormRuntimeService(): EmbeddedFormRuntimeService
    {
        if (!$this->embeddedFormRuntimeService instanceof EmbeddedFormRuntimeService) {
            $this->embeddedFormRuntimeService = new EmbeddedFormRuntimeService(
                $this->context->input(),
                dirname(__DIR__, 4)
            );
        }

        return $this->embeddedFormRuntimeService;
    }

    /**
     * Returns the discovered embedded shortcode/form runtimes for the current request.
     *
     * @return array<string, EmbeddedShortcodeRuntimeInterface|EmbeddedFormRuntimeInterface> Runtime map keyed by type.
     */
    private function embeddedFormRuntimes(): array
    {
        if (!$this->embeddedFormRuntimesLoaded) {
            $this->embeddedFormRuntimes = $this->embeddedFormRuntimeService()->discoverRuntimes($this->extensionServices());
            $this->embeddedFormRuntimesLoaded = true;
        }

        return $this->embeddedFormRuntimes;
    }

    /**
     * Returns the extension-services map, booting extensions only when form runtimes are needed.
     *
     * @return array<string, mixed> Public extension-services map.
     */
    private function extensionServices(): array
    {
        /** @var mixed $services */
        $services = ($this->extensionServicesProvider)();
        return is_array($services) ? $services : [];
    }
}
