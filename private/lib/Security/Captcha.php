<?php

/**
 * RAVEN CMS
 * ~/private/lib/Security/Captcha.php
 * Captcha provider config, server-side verification, and widget markup helpers.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Lib\Security;

use Raven\Core\Config;

/**
 * Captcha provider config, server-side verification, and widget markup helpers.
 */
final class Captcha
{
    private Config $config;
    private InputSanitizer $input;

    /**
     * @param Config $config Runtime config for captcha provider settings.
     * @param InputSanitizer $input Input sanitizer used to normalize config reads.
     */
    public function __construct(Config $config, InputSanitizer $input)
    {
        $this->config = $config;
        $this->input = $input;
    }

    /**
     * Returns the active captcha provider slug from config.
     *
     * @return string Provider slug ('none', 'hcaptcha', 'recaptcha2', or 'recaptcha3').
     */
    public function provider(): string
    {
        $provider = strtolower($this->input->text((string) $this->config->get('captcha.provider', 'none'), 20));
        if (!in_array($provider, ['none', 'hcaptcha', 'recaptcha2', 'recaptcha3'], true)) {
            return 'none';
        }

        return $provider;
    }

    /**
     * Returns the public/site key for a given provider.
     *
     * @param string $provider Provider slug as returned by `provider()`.
     * @return string Public site key, or empty string when not configured.
     */
    public function siteKey(string $provider): string
    {
        return match ($provider) {
            'hcaptcha' => $this->input->text((string) $this->config->get('captcha.hcaptcha.public_key', ''), 500),
            'recaptcha2' => $this->input->text((string) $this->config->get('captcha.recaptcha2.public_key', ''), 500),
            'recaptcha3' => $this->input->text((string) $this->config->get('captcha.recaptcha3.public_key', ''), 500),
            default => '',
        };
    }

    /**
     * Returns the secret/server key for a given provider.
     *
     * @param string $provider Provider slug as returned by `provider()`.
     * @return string Secret server key, or empty string when not configured.
     */
    public function secretKey(string $provider): string
    {
        return match ($provider) {
            'hcaptcha' => $this->input->text((string) $this->config->get('captcha.hcaptcha.secret_key', ''), 500),
            'recaptcha2' => $this->input->text((string) $this->config->get('captcha.recaptcha2.secret_key', ''), 500),
            'recaptcha3' => $this->input->text((string) $this->config->get('captcha.recaptcha3.secret_key', ''), 500),
            default => '',
        };
    }

    /**
     * Returns the POST field name that carries the captcha token for a given provider.
     *
     * @param string $provider Provider slug as returned by `provider()`.
     * @return string POST field name.
     */
    public function responseField(string $provider): string
    {
        return $provider === 'hcaptcha' ? 'h-captcha-response' : 'g-recaptcha-response';
    }

    /**
     * Validates a captcha submission and returns a user-facing error message on failure.
     *
     * Returns null immediately when the provider is 'none'.
     *
     * @param array<string, mixed> $post Submitted POST data.
     * @param string|null $remoteIp Client IP address forwarded to the captcha verification endpoint.
     * @return string|null One user-facing validation error, or null when captcha passes.
     */
    public function validateSubmission(array $post, ?string $remoteIp): ?string
    {
        $provider = $this->provider();
        if ($provider === 'none') {
            return null;
        }

        $siteKey = $this->siteKey($provider);
        $secretKey = $this->secretKey($provider);
        if ($siteKey === '' || $secretKey === '') {
            return 'Captcha is not configured right now. Please try again later.';
        }

        $responseField = $this->responseField($provider);
        $captchaToken = $this->input->text((string) ($post[$responseField] ?? ''), 6000);
        if ($captchaToken === '') {
            return 'Please complete the captcha challenge.';
        }

        if (!$this->verifyToken($provider, $secretKey, $captchaToken, $remoteIp)) {
            return 'Captcha verification failed. Please try again.';
        }

        return null;
    }

    /**
     * Builds the HTML widget markup and optional JS script tag for embedding a captcha on a form.
     *
     * The `script_included` flag tracks whether the provider script has already been emitted on
     * the page so callers can avoid duplicate script tags across multiple widget instances.
     *
     * @param bool $scriptIncluded Whether the provider JS script has already been emitted on this page.
     * @return array{markup: string, script_included: bool} Widget HTML and updated script-included flag.
     */
    public function markup(bool $scriptIncluded): array
    {
        $provider = $this->provider();
        if ($provider === 'none') {
            return [
                'markup' => '',
                'script_included' => $scriptIncluded,
            ];
        }

        $siteKey = $this->siteKey($provider);
        if ($siteKey === '') {
            return [
                'markup' => '<div class="col-12"><div class="alert alert-warning mb-0" role="alert">Captcha is currently unavailable.</div></div>',
                'script_included' => $scriptIncluded,
            ];
        }

        $widgetClass = $provider === 'hcaptcha' ? 'h-captcha' : 'g-recaptcha';
        $scriptSrc = match ($provider) {
            'hcaptcha' => 'https://js.hcaptcha.com/1/api.js',
            'recaptcha3' => 'https://www.google.com/recaptcha/api.js?render=' . rawurlencode($siteKey),
            default => 'https://www.google.com/recaptcha/api.js',
        };
        $escapedSiteKey = htmlspecialchars($siteKey, ENT_QUOTES, 'UTF-8');
        $escapedScriptSrc = htmlspecialchars($scriptSrc, ENT_QUOTES, 'UTF-8');

        $scriptMarkup = '';
        if (!$scriptIncluded) {
            $scriptMarkup = '<script src="' . $escapedScriptSrc . '" async defer></script>';
            if ($provider === 'recaptcha3') {
                $siteKeyJson = json_encode($siteKey, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
                if (!is_string($siteKeyJson) || $siteKeyJson === '') {
                    $siteKeyJson = '""';
                }

                $scriptMarkup .= '<script>'
                    . '(function(){'
                    . 'if(window.__ravenRecaptcha3Bound){return;}'
                    . 'window.__ravenRecaptcha3Bound=true;'
                    . 'var siteKey=' . $siteKeyJson . ';'
                    . 'document.addEventListener("submit",function(event){'
                    . 'var form=event.target;'
                    . 'if(!(form instanceof HTMLFormElement)){return;}'
                    . 'if(!form.querySelector(\'[data-rvn-captcha-provider="recaptcha3"]\')){return;}'
                    . 'if(String(form.getAttribute("data-rvn-recaptcha3-submitting")||"")==="1"){return;}'
                    . 'event.preventDefault();'
                    . 'form.setAttribute("data-rvn-recaptcha3-submitting","1");'
                    . 'var tokenField=form.querySelector(\'input[name="g-recaptcha-response"]\');'
                    . 'if(!(tokenField instanceof HTMLInputElement)){'
                    . 'tokenField=document.createElement("input");'
                    . 'tokenField.type="hidden";'
                    . 'tokenField.name="g-recaptcha-response";'
                    . 'form.appendChild(tokenField);'
                    . '}'
                    . 'var submitWithoutToken=function(){'
                    . 'form.removeAttribute("data-rvn-recaptcha3-submitting");'
                    . 'form.submit();'
                    . '};'
                    . 'if(!window.grecaptcha||typeof window.grecaptcha.ready!=="function"||typeof window.grecaptcha.execute!=="function"){'
                    . 'submitWithoutToken();'
                    . 'return;'
                    . '}'
                    . 'window.grecaptcha.ready(function(){'
                    . 'window.grecaptcha.execute(siteKey,{action:"submit"}).then(function(token){'
                    . 'tokenField.value=String(token||"");'
                    . 'form.removeAttribute("data-rvn-recaptcha3-submitting");'
                    . 'form.submit();'
                    . '}).catch(function(){submitWithoutToken();});'
                    . '});'
                    . '},true);'
                    . '})();'
                    . '</script>';
            }
            $scriptIncluded = true;
        }

        if ($provider === 'recaptcha3') {
            return [
                'markup' => $scriptMarkup
                    . '<div class="col-12">'
                    . '<input type="hidden" name="g-recaptcha-response" value="">'
                    . '<div class="small text-muted" data-rvn-captcha-provider="recaptcha3">Protected by reCAPTCHA.</div>'
                    . '</div>',
                'script_included' => $scriptIncluded,
            ];
        }

        return [
            'markup' => $scriptMarkup
                . '<div class="col-12"><div class="' . $widgetClass . '" data-theme="dark" data-sitekey="' . $escapedSiteKey . '"></div></div>',
            'script_included' => $scriptIncluded,
        ];
    }

    private function verifyToken(string $provider, string $secretKey, string $captchaToken, ?string $remoteIp): bool
    {
        $endpoint = $provider === 'hcaptcha'
            ? 'https://api.hcaptcha.com/siteverify'
            : 'https://www.google.com/recaptcha/api/siteverify';

        $payload = [
            'secret' => $secretKey,
            'response' => $captchaToken,
        ];
        if ($remoteIp !== null && $remoteIp !== '') {
            $payload['remoteip'] = $remoteIp;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($payload, '', '&', PHP_QUERY_RFC3986),
                'timeout' => 8,
                'ignore_errors' => true,
            ],
        ]);

        $rawResponse = @file_get_contents($endpoint, false, $context);
        if (!is_string($rawResponse) || trim($rawResponse) === '') {
            return false;
        }

        $decoded = json_decode($rawResponse, true);
        if (!is_array($decoded)) {
            return false;
        }

        return !empty($decoded['success']);
    }
}
