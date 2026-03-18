<?php

/**
 * RAVEN CMS
 * ~/private/tpl/panel/login_2fa.php
 * Admin panel 2FA challenge template.
 */

/** @var array<string, string> $site */
/** @var string $csrfField */
/** @var string $csrfToken */
/** @var string|null $success */
/** @var string|null $error */
/** @var array<int, array<string, mixed>> $twoFactorMethods */
/** @var bool $showMethodPicker */
/** @var bool $showTotpForm */
/** @var bool $showWebauthnPrompt */
/** @var bool $webauthnFailed */
/** @var array<int, array<string, mixed>> $fallbackMethods */
/** @var array<string, mixed>|null $selectedMethod */
/** @var string $selectedMethodType */
/** @var bool $canSwitchMethod */
/** @var string $webauthnMethodKey */
/** @var string $emailCodeTargetMasked */
/** @var string $selectedEmailInput */
/** @var string $panelBaseUrl */

use function Raven\Core\Support\e;

$panelBase = trim((string) ($panelBaseUrl ?? ''), '/');
if ($panelBase === '') {
    $panelBase = trim((string) ('/' . trim((string) ($site['panel_path'] ?? 'panel'), '/')), '/');
}
$panelBase = '/' . $panelBase;
$twoFactorMethods = is_array($twoFactorMethods ?? null) ? $twoFactorMethods : [];
$fallbackMethods = is_array($fallbackMethods ?? null) ? $fallbackMethods : [];
$selectedMethod = is_array($selectedMethod ?? null) ? $selectedMethod : null;
$selectedMethodType = strtolower(trim((string) ($selectedMethodType ?? '')));
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
<div class="rvnp-login-shell">
    <div class="card rvnp-login-card">
        <div class="card-body">
            <h2 class="h4 mb-3">Two-Factor Verification</h2>

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
                        <form method="post" action="<?= e($panelBase) ?>/login/2fa/select" class="mb-2">
                            <?= $csrfField ?>
                            <input type="hidden" name="method_key" value="<?= e($webauthnMethodKey) ?>">
                            <button type="submit" class="btn btn-primary w-100 text-start">Try Security Key</button>
                        </form>
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
                        <form method="post" action="<?= e($panelBase) ?>/login/2fa/select" class="mb-2">
                            <?= $csrfField ?>
                            <input type="hidden" name="method_key" value="<?= e($methodKey) ?>">
                            <button type="submit" class="btn btn-outline-primary w-100 text-start"><?= e($methodLabel) ?></button>
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
                $inputPattern = $isEmail ? '[0-9]{8}' : '[0-9]{6,8}';
                ?>
                <form method="post" action="<?= e($panelBase) ?>/login/2fa" novalidate>
                    <?= $csrfField ?>
                    <p class="text-muted mb-2">Method: <?= e($selectedLabel) ?></p>
                    <?php if ($isEmail): ?>
                        <div class="mb-3">
                            <label for="verification_email" class="form-label">Email Address</label>
                            <input
                                id="verification_email"
                                name="verification_email"
                                type="email"
                                class="form-control"
                                autocomplete="email"
                                value="<?= e($selectedEmailInput) ?>"
                                placeholder="you@example.com"
                            >
                        </div>
                    <?php endif; ?>
                    <?php if ($isEmail): ?>
                        <p class="small text-muted mb-2">
                            Enter one of your saved 2FA email addresses to request a code.
                            <?php if ($emailCodeTargetMasked !== ''): ?>
                                Latest dispatch target: <code><?= e($emailCodeTargetMasked) ?></code>.
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
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
                            <?= $isRecovery ? '' : ('pattern="' . e($inputPattern) . '"') ?>
                            placeholder="<?= e($isRecovery ? 'twelve-word recovery phrase' : ($isEmail ? '12345678' : '123456')) ?>"
                        >
                    </div>
                    <div class="d-flex flex-wrap justify-content-center gap-2">
                        <button type="submit" class="btn btn-primary">Verify</button>
                        <?php if ($canSwitchMethod): ?>
                            <button
                                type="submit"
                                class="btn btn-outline-secondary"
                                formaction="<?= e($panelBase) ?>/login/2fa/select"
                                formmethod="post"
                                name="show_method_picker"
                                value="1"
                            >Try Other Method</button>
                        <?php endif; ?>
                    </div>
                </form>
            <?php endif; ?>

            <?php if ($showWebauthnPrompt): ?>
                <?php if ($csrfToken !== ''): ?>
                    <input type="hidden" id="rvn-login-webauthn-csrf" value="<?= e($csrfToken) ?>">
                <?php endif; ?>
                <div
                    class="mb-3"
                    data-rvn-webauthn-prompt="1"
                    data-webauthn-autostart="<?= $webauthnFailed ? '0' : '1' ?>"
                >
                    <p class="mb-2">Press your security key or use your passkey to continue.</p>
                    <div class="text-center"><button type="button" class="btn btn-primary" data-rvn-webauthn-start="1">Try Security Key</button></div>
                    <div class="mt-2 d-none" data-rvn-webauthn-status="1"></div>
                </div>

                <div
                    class="mt-3"
                    data-rvn-webauthn-fallback="1"
                    <?= $webauthnFailed ? '' : 'style="display:none;"' ?>
                >
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
                        <form method="post" action="<?= e($panelBase) ?>/login/2fa/select" class="mb-2">
                            <?= $csrfField ?>
                            <input type="hidden" name="method_key" value="<?= e($methodKey) ?>">
                            <button type="submit" class="btn btn-outline-secondary w-100 text-start"><?= e($methodLabel) ?></button>
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
                        var csrfInput = document.getElementById('rvn-login-webauthn-csrf');
                        if (!(csrfInput instanceof HTMLInputElement)) {
                            csrfInput = document.querySelector('input[name="_csrf"]');
                        }
                        var csrfToken = csrfInput instanceof HTMLInputElement ? String(csrfInput.value || '') : '';
                        var panelBase = <?= json_encode($panelBase, JSON_UNESCAPED_SLASHES) ?>;

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
                                var optionsResponse = await window.fetch(panelBase + '/login/2fa/webauthn/options', {
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
                                var verifyResponse = await window.fetch(panelBase + '/login/2fa/webauthn/verify', {
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

                                window.location.assign(String(verifyResult.redirect || (panelBase + '/')));
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
        </div>
    </div>
</div>
