<?php

/**
 * RAVEN CMS
 * ~/private/lib/Security/PublicCaptchaFlow.php
 * Request-scoped public captcha validation + markup helper for form-enabled routes.
 * Docs: https://lanterns.io/raven
 */

declare(strict_types=1);

namespace Raven\Lib\Security;

use Raven\Core\Config;
use Raven\Core\Debug\ClientProfiler;

/**
 * Encapsulates public captcha rendering state and validation checks.
 *
 * This helper is intentionally route-local to public form flows so generic
 * public request context classes do not carry captcha-only methods for routes
 * that never render or validate a captcha challenge.
 */
final class PublicCaptchaFlow
{
    private Captcha $captcha;
    private ClientProfiler $clientProfiler;
    private bool $scriptIncluded = false;

    /**
     * @param Config $config Runtime configuration reader for captcha provider settings.
     * @param InputSanitizer $input Shared input sanitizer for captcha payload checks.
     * @param ClientProfiler $clientProfiler Shared client-network normalizer.
     * @return void
     */
    public function __construct(Config $config, InputSanitizer $input, ClientProfiler $clientProfiler)
    {
        $this->captcha = new Captcha($config, $input);
        $this->clientProfiler = $clientProfiler;
    }

    /**
     * Validates one submitted public captcha payload.
     *
     * @param array<string, mixed> $post Submitted request payload.
     * @param array<string, mixed> $server Server environment payload.
     * @return string|null One user-facing error string, or null when captcha passes.
     */
    public function validateSubmission(array $post, array $server): ?string
    {
        $remoteIp = $this->clientProfiler->normalizeClientIp((string) ($server['REMOTE_ADDR'] ?? ''));
        return $this->captcha->validateSubmission($post, $remoteIp);
    }

    /**
     * Returns public captcha widget markup for the current request.
     *
     * @return string Captcha widget markup.
     */
    public function markup(): string
    {
        $markup = $this->captcha->markup($this->scriptIncluded);
        $this->scriptIncluded = (bool) ($markup['script_included'] ?? $this->scriptIncluded);
        return (string) ($markup['markup'] ?? '');
    }
}

