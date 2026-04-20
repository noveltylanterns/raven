<?php

/**
 * RAVEN CMS
 * ~/private/tpl/auth/login_2fa.php
 * Public two-factor verification helper template.
 * Docs: https://raven.lanterns.io
 */

if (!defined('RAVEN_VIEW_RENDER_CONTEXT')) {
    http_response_code(404);
    exit('Not Found');
}

use function Raven\Lib\Security\e;

$verifyPath = trim((string) ($verifyPath ?? '/login/2fa'));
$selectPath = trim((string) ($selectPath ?? '/login/2fa/select'));
$webauthnOptionsPath = trim((string) ($webauthnOptionsPath ?? '/login/2fa/webauthn/options'));
$webauthnVerifyPath = trim((string) ($webauthnVerifyPath ?? '/login/2fa/webauthn/verify'));
$loginPath = trim((string) ($loginPath ?? '/login'));
$postLoginRedirectPath = trim((string) ($postLoginRedirectPath ?? '/'));
$success = is_string($success ?? null) ? $success : null;
$error = is_string($error ?? null) ? $error : null;
$twoFactorMethods = is_array($twoFactorMethods ?? null) ? $twoFactorMethods : [];
$fallbackMethods = is_array($fallbackMethods ?? null) ? $fallbackMethods : [];
$selectedMethod = is_array($selectedMethod ?? null) ? $selectedMethod : null;
$selectedMethodType = strtolower(trim((string) ($selectedMethodType ?? '')));
$showMethodPicker = (bool) ($showMethodPicker ?? false);
$showTotpForm = (bool) ($showTotpForm ?? false);
$showWebauthnPrompt = (bool) ($showWebauthnPrompt ?? false);
$webauthnFailed = (bool) ($webauthnFailed ?? false);
$canSwitchMethod = (bool) ($canSwitchMethod ?? false);
$webauthnMethodKey = trim((string) ($webauthnMethodKey ?? ''));
$emailCodeTargetMasked = trim((string) ($emailCodeTargetMasked ?? ''));
$selectedEmailInput = trim((string) ($selectedEmailInput ?? ''));
$csrfToken = trim((string) ($csrfToken ?? ''));

$defaultMethodLabel = static function (string $methodType): string {
    $normalized = strtolower(trim($methodType));
    return match ($normalized) {
        'webauthn' => 'Security Key',
        'recovery' => 'Recovery Phrase',
        'email' => 'Email Code',
        default => 'Authenticator App',
    };
};
?>
<section>
    <h1 class="mb-3">Two-Factor Verification</h1>

    <?php if ($success !== null): ?>
    <div class="alert alert-success" role="alert"><?= e($success) ?></div>
    <?php endif; ?>
    <?php if ($error !== null): ?>
    <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($showMethodPicker): ?>
    <div class="mb-3">
        <p class="mb-2">Choose a verification method:</p>
        <?php if ($webauthnMethodKey !== ''): ?>
        <form method="post" action="<?= e($selectPath) ?>" class="mb-2">
            <?= (string) ($csrfField ?? '') ?>
            <input type="hidden" name="redirect_to" value="<?= e($postLoginRedirectPath !== '' ? $postLoginRedirectPath : '/') ?>">
            <input type="hidden" name="method_key" value="<?= e($webauthnMethodKey) ?>">
            <button type="submit" class="btn btn-primary w-100 text-start">Try Security Key</button>        </form>
        <?php endif; ?>
        <?php foreach ($twoFactorMethods as $method): ?>
        <?php
            $methodType = strtolower(trim((string) ($method['type'] ?? '')));
            $methodKey = trim((string) ($method['key'] ?? ''));
            if ($methodKey === '') {
                continue;
            }
            $methodLabel = trim((string) ($method['label'] ?? ''));
            if ($methodLabel === '') {
                $methodLabel = $defaultMethodLabel($methodType);
            }
        ?>
        <form method="post" action="<?= e($selectPath) ?>" class="mb-2">
            <?= (string) ($csrfField ?? '') ?>
            <input type="hidden" name="redirect_to" value="<?= e($postLoginRedirectPath !== '' ? $postLoginRedirectPath : '/') ?>">
            <input type="hidden" name="method_key" value="<?= e($methodKey) ?>">
            <button type="submit" class="btn btn-primary w-100 text-start"><?= e($methodLabel) ?></button>
        </form>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($showTotpForm): ?>
        <?php
            $selectedLabel = trim((string) ($selectedMethod['label'] ?? ''));
            if ($selectedLabel === '') {
                $selectedLabel = $selectedMethodType === 'recovery'
                    ? 'Recovery Phrase'
                    : $defaultMethodLabel($selectedMethodType);
            }
            $isRecovery = $selectedMethodType === 'recovery';
            $isEmail = $selectedMethodType === 'email';
            $inputLabel = $isRecovery
                ? 'Recovery Phrase'
                : ($isEmail ? 'Email Code' : 'Verification Code');
            ?>
        <p class="text-muted mb-2">Method: <?= e($selectedLabel) ?></p>
            <?php if ($isEmail): ?>
        <form method="post" action="<?= e($verifyPath) ?>" class="mb-3" id="rvn-public-login-2fa-email-send-form" novalidate>
            <?= (string) ($csrfField ?? '') ?>
            <input type="hidden" name="redirect_to" value="<?= e($postLoginRedirectPath !== '' ? $postLoginRedirectPath : '/') ?>">
            <input type="hidden" name="email_action" value="send_code">
            <label for="verification_email" class="form-label">Email Address</label>
            <div class="input-group">
                <input
                    id="verification_email"
                    name="verification_email"
                    type="email"
                    class="form-control"
                    autocomplete="email"
                    value="<?= e($selectedEmailInput) ?>"
                    placeholder="you@example.com"
                    required
                >
                <button type="submit" class="btn btn-primary">Send Code</button>
            </div>
        </form>

        <p class="small text-muted mb-2">
            Enter one of your saved 2FA email addresses to request a code.
                <?php if ($emailCodeTargetMasked !== ''): ?>
            Latest dispatch target: <code><?= e($emailCodeTargetMasked) ?></code>.
                <?php endif; ?>
        </p>

        <form method="post" action="<?= e($verifyPath) ?>" class="mb-3" id="rvn-public-login-2fa-email-verify-form" novalidate>
            <?= (string) ($csrfField ?? '') ?>
            <input type="hidden" name="redirect_to" value="<?= e($postLoginRedirectPath !== '' ? $postLoginRedirectPath : '/') ?>">
            <input type="hidden" name="email_action" value="verify_code">
            <input type="hidden" name="verification_email" value="<?= e($selectedEmailInput) ?>" data-login-2fa-verify-email="1">
            <label for="verification_code" class="form-label">Email Code</label>
            <div class="input-group">
                <input
                    id="verification_code"
                    name="verification_code"
                    type="text"
                    class="form-control"
                    autocomplete="one-time-code"
                    inputmode="numeric"
                    pattern="[0-9]{8}"
                    placeholder="12345678"
                    required
                >
                <button type="submit" class="btn btn-primary">Verify</button>
            </div>
        </form>

        <script>
            (function () {
                var emailInput = document.getElementById('verification_email');
                var verifyEmail = document.querySelector('[data-login-2fa-verify-email="1"]');
                var verifyForm = document.getElementById('rvn-public-login-2fa-email-verify-form');
                if (!(emailInput instanceof HTMLInputElement) || !(verifyEmail instanceof HTMLInputElement)) {
                    return;
                }

                function syncVerifyEmail() {
                    verifyEmail.value = String(emailInput.value || '').trim();
                }

                emailInput.addEventListener('input', syncVerifyEmail);
                emailInput.addEventListener('change', syncVerifyEmail);
                if (verifyForm instanceof HTMLFormElement) {
                    verifyForm.addEventListener('submit', syncVerifyEmail);
                }
                syncVerifyEmail();
            })();
        </script>
            <?php else: ?>
        <form method="post" action="<?= e($verifyPath) ?>" novalidate>
            <?= (string) ($csrfField ?? '') ?>
            <input type="hidden" name="redirect_to" value="<?= e($postLoginRedirectPath !== '' ? $postLoginRedirectPath : '/') ?>">
            <div class="mb-3">
                <label for="verification_code" class="form-label"><?= e($inputLabel) ?></label>
                <input
                    id="verification_code"
                    name="verification_code"
                    type="<?= e($isRecovery ? 'password' : 'text') ?>"
                    class="form-control"
                    required
                    autocomplete="<?= e($isRecovery ? 'off' : 'one-time-code') ?>"
                    inputmode="<?= e($isRecovery ? 'text' : 'numeric') ?>"
                    <?= $isRecovery ? '' : 'pattern="[0-9]{8}"' ?>
                    placeholder="<?= e($isRecovery ? 'twelve-word recovery phrase' : '12345678') ?>"
                >
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-primary">Verify</button>
                <?php if ($canSwitchMethod): ?>
                <button
                    type="submit"
                    class="btn btn-secondary"
                    formaction="<?= e($selectPath) ?>"
                    formmethod="post"
                    name="show_method_picker"
                    value="1"
                >Try Other Method</button>
                <?php endif; ?>
            </div>
        </form>
            <?php endif; ?>
            <?php if ($isEmail && $canSwitchMethod): ?>
        <form method="post" action="<?= e($selectPath) ?>" class="d-flex justify-content-start">
            <?= (string) ($csrfField ?? '') ?>
            <input type="hidden" name="redirect_to" value="<?= e($postLoginRedirectPath !== '' ? $postLoginRedirectPath : '/') ?>">
            <button type="submit" class="btn btn-secondary" name="show_method_picker" value="1">Try Other Method</button>
        </form>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($showWebauthnPrompt): ?>
            <?php if ($csrfToken !== ''): ?>
        <input type="hidden" id="rvn-public-login-webauthn-csrf" value="<?= e($csrfToken) ?>">
            <?php endif; ?>
        <div class="mb-3" data-rvn-webauthn-prompt="1" data-webauthn-autostart="<?= $webauthnFailed ? '0' : '1' ?>">
            <p class="mb-2">Press your security key or use your passkey to continue.</p>
            <div><button type="button" class="btn btn-primary" data-rvn-webauthn-start="1">Try Security Key</button></div>
            <div class="mt-2 d-none" data-rvn-webauthn-status="1"></div>
        </div>

        <div class="mt-3" data-rvn-webauthn-fallback="1" <?= $webauthnFailed ? '' : 'style="display:none;"' ?>>
            <h3 class="h6">Security key failed</h3>
            <p class="text-muted mb-2">Try again or choose another verification method:</p>
            <?php foreach ($fallbackMethods as $method): ?>
                <?php
                $methodKey = trim((string) ($method['key'] ?? ''));
                if ($methodKey === '') {
                    continue;
                }
                $methodLabel = trim((string) ($method['label'] ?? ''));
                if ($methodLabel === '') {
                    $methodLabel = $defaultMethodLabel((string) ($method['type'] ?? ''));
                }
                ?>
            <form method="post" action="<?= e($selectPath) ?>" class="mb-2">
                <?= (string) ($csrfField ?? '') ?>
                <input type="hidden" name="redirect_to" value="<?= e($postLoginRedirectPath !== '' ? $postLoginRedirectPath : '/') ?>">
                <input type="hidden" name="method_key" value="<?= e($methodKey) ?>">
                <button type="submit" class="btn btn-secondary w-100 text-start"><?= e($methodLabel) ?></button>
            </form>
            <?php endforeach; ?>
        </div>

        <script>
            (function () {
                var prompt = document.querySelector('[data-rvn-webauthn-prompt="1"]');
                if (!(prompt instanceof HTMLElement)) {
                    return;
                }

                var startButton = prompt.querySelector('[data-rvn-webauthn-start="1"]');
                var statusBox = prompt.querySelector('[data-rvn-webauthn-status="1"]');
                var fallbackBox = document.querySelector('[data-rvn-webauthn-fallback="1"]');
                var csrfInput = document.getElementById('rvn-public-login-webauthn-csrf');
                if (!(csrfInput instanceof HTMLInputElement)) {
                    csrfInput = document.querySelector('input[name="_csrf"]');
                }
                var csrfToken = csrfInput instanceof HTMLInputElement ? String(csrfInput.value || '') : '';
                var optionsUrl = <?= json_encode($webauthnOptionsPath, JSON_UNESCAPED_SLASHES) ?>;
                var verifyUrl = <?= json_encode($webauthnVerifyPath, JSON_UNESCAPED_SLASHES) ?>;

                if (!(startButton instanceof HTMLButtonElement) || !(statusBox instanceof HTMLElement)) {
                    return;
                }

                function setStatus(message, level) {
                    statusBox.classList.remove('d-none', 'alert-danger', 'alert-info');
                    statusBox.classList.add(level === 'danger' ? 'alert-danger' : 'alert-info');
                    statusBox.classList.add('alert');
                    statusBox.textContent = message;
                }

                function showFallback() {
                    if (fallbackBox instanceof HTMLElement) {
                        fallbackBox.style.display = '';
                    }
                }

                function recursiveBinaryStringToArrayBuffer(obj) {
                    var prefix = '=?BINARY?B?';
                    var suffix = '?=';
                    if (!obj || typeof obj !== 'object') {
                        return;
                    }

                    Object.keys(obj).forEach(function (key) {
                        var value = obj[key];
                        if (typeof value === 'string') {
                            if (value.slice(0, prefix.length) !== prefix || value.slice(-suffix.length) !== suffix) {
                                return;
                            }

                            var base64Value = value.slice(prefix.length, value.length - suffix.length);
                            var binaryString = window.atob(base64Value);
                            var bytes = new Uint8Array(binaryString.length);
                            for (var index = 0; index < binaryString.length; index += 1) {
                                bytes[index] = binaryString.charCodeAt(index);
                            }
                            obj[key] = bytes.buffer;
                            return;
                        }

                        recursiveBinaryStringToArrayBuffer(value);
                    });
                }

                function arrayBufferToBase64(buffer) {
                    var bytes = new Uint8Array(buffer);
                    var binary = '';
                    for (var index = 0; index < bytes.length; index += 1) {
                        binary += String.fromCharCode(bytes[index]);
                    }

                    return window.btoa(binary);
                }

                async function startWebauthn() {
                    if (csrfToken === '') {
                        setStatus('Security token is missing. Refresh and try again.', 'danger');
                        showFallback();
                        return;
                    }

                    if (
                        typeof window.PublicKeyCredential === 'undefined'
                        || !navigator.credentials
                        || typeof navigator.credentials.get !== 'function'
                    ) {
                        setStatus('This browser does not support WebAuthn security keys.', 'danger');
                        showFallback();
                        return;
                    }

                    startButton.disabled = true;
                    setStatus('Touch your security key to continue.', 'info');

                    try {
                        var optionsResponse = await window.fetch(optionsUrl, {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: new URLSearchParams({ _csrf: csrfToken }).toString()
                        });
                        var optionsPayload = await optionsResponse.json();

                        if (!optionsResponse.ok || !optionsPayload || optionsPayload.ok !== true || !optionsPayload.options) {
                            throw new Error(optionsPayload && optionsPayload.message ? optionsPayload.message : 'Unable to start security key verification.');
                        }

                        recursiveBinaryStringToArrayBuffer(optionsPayload);
                        var assertion = await navigator.credentials.get(optionsPayload.options);
                        if (!(assertion instanceof PublicKeyCredential)) {
                            throw new Error('Security key response was invalid.');
                        }

                        var verifyPayload = {
                            _csrf: csrfToken,
                            id: assertion.rawId ? arrayBufferToBase64(assertion.rawId) : '',
                            clientDataJSON: assertion.response && assertion.response.clientDataJSON ? arrayBufferToBase64(assertion.response.clientDataJSON) : '',
                            authenticatorData: assertion.response && assertion.response.authenticatorData ? arrayBufferToBase64(assertion.response.authenticatorData) : '',
                            signature: assertion.response && assertion.response.signature ? arrayBufferToBase64(assertion.response.signature) : '',
                            userHandle: assertion.response && assertion.response.userHandle ? arrayBufferToBase64(assertion.response.userHandle) : ''
                        };
                        var verifyResponse = await window.fetch(verifyUrl, {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: new URLSearchParams(verifyPayload).toString()
                        });
                        var verifyResult = await verifyResponse.json();
                        if (!verifyResponse.ok || !verifyResult || verifyResult.ok !== true) {
                            throw new Error(verifyResult && verifyResult.message ? verifyResult.message : 'Security key verification failed.');
                        }

                        window.location.assign(String(verifyResult.redirect || '/'));
                    } catch (error) {
                        var message = error && typeof error.message === 'string' ? error.message : 'Security key verification failed.';
                        setStatus(message, 'danger');
                        showFallback();
                    } finally {
                        startButton.disabled = false;
                        startButton.textContent = 'Try Security Key Again';
                    }
                }

                startButton.addEventListener('click', function () {
                    void startWebauthn();
                });

                if (prompt.getAttribute('data-webauthn-autostart') === '1') {
                    void startWebauthn();
                }
            })();
        </script>
        <?php endif; ?>

        <hr>
        <p class="mb-0"><a href="<?= e($loginPath) ?>">Back to Login</a></p>
    </div>
</section>
