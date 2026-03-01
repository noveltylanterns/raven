<?php

/**
 * RAVEN CMS
 * ~/private/ext/signups/src/SignupPublicFormRuntime.php
 * Signup Sheets embedded-form runtime and submit handling.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven;

use Raven\Core\Extension\EmbeddedFormRuntimeInterface;
use Raven\Core\Security\Csrf;
use Raven\Core\Security\InputSanitizer;
use Raven\Core\Support\CountryOptions;
use Raven\Repository\SignupFormRepository;
use Raven\Repository\SignupSubmissionRepository;

use function Raven\Core\Support\redirect;

/**
 * Owns Signup Sheets shortcode rendering and submit pipeline.
 */
final class SignupPublicFormRuntime implements EmbeddedFormRuntimeInterface
{
    private InputSanitizer $input;
    private Csrf $csrf;
    private SignupFormRepository $forms;
    private SignupSubmissionRepository $submissions;

    public function __construct(
        InputSanitizer $input,
        Csrf $csrf,
        SignupFormRepository $forms,
        SignupSubmissionRepository $submissions
    ) {
        $this->input = $input;
        $this->csrf = $csrf;
        $this->forms = $forms;
        $this->submissions = $submissions;
    }

    public function type(): string
    {
        return 'signups';
    }

    public function extensionKey(): string
    {
        return 'signups';
    }

    public function listEnabledForms(): array
    {
        $rows = $this->forms->listAll();
        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['enabled'])) {
                continue;
            }

            $slug = $this->input->slug((string) ($row['slug'] ?? ''));
            if ($slug === null || $slug === '') {
                continue;
            }

            $row['slug'] = $slug;
            $items[] = $row;
        }

        return $items;
    }

    public function anchorId(string $slug): string
    {
        $normalizedSlug = $this->input->slug($slug) ?? 'item';
        return 'signups-' . $normalizedSlug;
    }

    public function submitAction(string $slug): string
    {
        $normalizedSlug = $this->input->slug($slug);
        if ($normalizedSlug === null || $normalizedSlug === '') {
            return '/';
        }

        return '/signups/submit/' . rawurlencode($normalizedSlug);
    }

    public function render(array $definition, string $returnPath, string $csrfField, string $captchaMarkup): string
    {
        $name = htmlspecialchars(trim((string) ($definition['name'] ?? 'Form')), ENT_QUOTES, 'UTF-8');
        $rawSlug = trim((string) ($definition['slug'] ?? ''));
        $slug = htmlspecialchars($rawSlug, ENT_QUOTES, 'UTF-8');
        $flash = $this->pullFlash($rawSlug);
        $flashMarkup = '';
        $oldValues = [
            'email' => '',
            'display_name' => '',
            'country' => '',
            'additional' => [],
        ];
        if ($flash !== null) {
            $flashType = (string) ($flash['type'] ?? 'error');
            $flashClass = $flashType === 'success' ? 'alert-success' : 'alert-danger';
            $flashMessage = htmlspecialchars((string) ($flash['message'] ?? ''), ENT_QUOTES, 'UTF-8');
            if ($flashMessage !== '') {
                $flashMarkup = '<div class="alert ' . $flashClass . '" role="alert">' . $flashMessage . '</div>';
            }

            /** @var mixed $rawOld */
            $rawOld = $flash['old'] ?? [];
            if (is_array($rawOld)) {
                $oldValues['email'] = htmlspecialchars((string) ($rawOld['email'] ?? ''), ENT_QUOTES, 'UTF-8');
                $oldValues['display_name'] = htmlspecialchars((string) ($rawOld['display_name'] ?? ''), ENT_QUOTES, 'UTF-8');
                $oldValues['country'] = strtolower((string) ($rawOld['country'] ?? ''));
                $oldValues['additional'] = is_array($rawOld['additional'] ?? null) ? (array) $rawOld['additional'] : [];
            }
        }

        $countryOptionsMarkup = '<option value="">Select country</option>';
        foreach ($this->countryOptions() as $countryCode => $countryLabel) {
            $selectedAttr = $oldValues['country'] === $countryCode ? ' selected' : '';
            $countryOptionsMarkup .= '<option value="' . htmlspecialchars($countryCode, ENT_QUOTES, 'UTF-8') . '"' . $selectedAttr . '>'
                . htmlspecialchars($countryLabel, ENT_QUOTES, 'UTF-8')
                . '</option>';
        }

        $additionalFieldMarkup = '';
        foreach ($this->additionalFieldDefinitions($rawSlug, $definition) as $fieldDefinition) {
            $fieldLabel = htmlspecialchars((string) ($fieldDefinition['label'] ?? ''), ENT_QUOTES, 'UTF-8');
            $fieldName = (string) ($fieldDefinition['name'] ?? '');
            $inputName = htmlspecialchars((string) ($fieldDefinition['input_name'] ?? ''), ENT_QUOTES, 'UTF-8');
            $fieldType = (string) ($fieldDefinition['type'] ?? 'text');
            $requiredAttr = (bool) ($fieldDefinition['required'] ?? false) ? ' required' : '';
            $rawOldAdditional = (string) ($oldValues['additional'][$fieldName] ?? '');
            $fieldValue = htmlspecialchars($rawOldAdditional, ENT_QUOTES, 'UTF-8');

            if ($fieldType === 'textarea') {
                $additionalFieldMarkup .= '<div class="col-12"><label class="form-label">' . $fieldLabel . '</label>'
                    . '<textarea class="form-control" name="' . $inputName . '" rows="3"' . $requiredAttr . '>' . $fieldValue . '</textarea></div>';
                continue;
            }

            $inputType = $fieldType === 'email' ? 'email' : 'text';
            $additionalFieldMarkup .= '<div class="col-md-6"><label class="form-label">' . $fieldLabel . '</label>'
                . '<input type="' . $inputType . '" class="form-control" name="' . $inputName . '" value="' . $fieldValue . '"' . $requiredAttr . '></div>';
        }

        $submitAction = htmlspecialchars($this->submitAction($rawSlug), ENT_QUOTES, 'UTF-8');
        $safeReturnPath = htmlspecialchars($returnPath, ENT_QUOTES, 'UTF-8');
        $sectionId = htmlspecialchars($this->anchorId($rawSlug), ENT_QUOTES, 'UTF-8');

        return '<section class="raven-embedded-form raven-embedded-form-signups" id="' . $sectionId . '" data-raven-form-type="signups" data-raven-form-slug="' . $slug . '">'
            . $flashMarkup
            . '<form method="post" action="' . $submitAction . '" novalidate>'
            . $csrfField
            . '<input type="hidden" name="return_path" value="' . $safeReturnPath . '">'
            . '<div class="row g-3">'
            . '<div class="col-12"><label class="form-label">Email</label><input type="email" class="form-control" name="signups_email" placeholder="you@example.com" value="' . $oldValues['email'] . '" required></div>'
            . '<div class="col-12"><label class="form-label">Display Name</label><input type="text" class="form-control" name="signups_display_name" placeholder="Your name" value="' . $oldValues['display_name'] . '" required></div>'
            . '<div class="col-12"><label class="form-label">Country</label><select class="form-select" name="signups_country" required>'
            . $countryOptionsMarkup
            . '</select></div>'
            . $additionalFieldMarkup
            . $captchaMarkup
            . '<div class="col-12"><button type="submit" class="btn btn-primary">Join Signup Sheet</button></div>'
            . '</div>'
            . '</form>'
            . '</section>';
    }

    public function submit(string $slug, string $returnPath, callable $validateCaptcha): void
    {
        $normalizedSlug = $this->input->slug($slug);
        if ($normalizedSlug === null || $normalizedSlug === '') {
            redirect($returnPath);
        }

        $redirectTo = $returnPath . '#' . $this->anchorId($normalizedSlug);
        if (!$this->csrf->validate($_POST['_csrf'] ?? null)) {
            $this->pushFlash($normalizedSlug, 'error', 'Your session token is invalid. Please retry and submit again.');
            redirect($redirectTo);
        }

        $definition = $this->findEnabledDefinitionBySlug($normalizedSlug);
        if ($definition === null) {
            $this->pushFlash($normalizedSlug, 'error', 'This signup sheet form is unavailable right now.');
            redirect($redirectTo);
        }

        $displayName = $this->input->text((string) ($_POST['signups_display_name'] ?? ''), 160);
        $emailRaw = strtolower($this->input->text((string) ($_POST['signups_email'] ?? ''), 254));
        $email = $this->input->email($emailRaw);
        $country = strtolower($this->input->text((string) ($_POST['signups_country'] ?? ''), 16));
        $oldValues = [
            'email' => $emailRaw,
            'display_name' => $displayName,
            'country' => $country,
            'additional' => [],
        ];

        $errors = [];
        if ($email === null) {
            $errors[] = 'A valid email address is required.';
        }
        if ($displayName === '') {
            $errors[] = 'Display name is required.';
        }

        $allowedCountries = array_keys($this->countryOptions());
        if ($country === '' || !in_array($country, $allowedCountries, true)) {
            $errors[] = 'Please choose a valid country option.';
        }

        $additionalPayload = [];
        foreach ($this->additionalFieldDefinitions($normalizedSlug, $definition) as $fieldDefinition) {
            $inputName = (string) ($fieldDefinition['input_name'] ?? '');
            $fieldName = (string) ($fieldDefinition['name'] ?? '');
            $fieldLabel = (string) ($fieldDefinition['label'] ?? $fieldName);
            $fieldType = (string) ($fieldDefinition['type'] ?? 'text');
            $fieldRequired = (bool) ($fieldDefinition['required'] ?? false);

            $rawValue = (string) ($_POST[$inputName] ?? '');
            $rawValue = $this->input->text($rawValue, $fieldType === 'textarea' ? 4000 : 255);
            $oldValues['additional'][$fieldName] = $rawValue;

            if ($fieldType === 'email') {
                $sanitizedEmail = $this->input->email($rawValue);
                if ($rawValue !== '' && $sanitizedEmail === null) {
                    $errors[] = $fieldLabel . ' must be a valid email address.';
                }
                if ($fieldRequired && $sanitizedEmail === null) {
                    $errors[] = $fieldLabel . ' is required.';
                }

                if ($sanitizedEmail !== null) {
                    $additionalPayload[] = [
                        'label' => $fieldLabel,
                        'name' => $fieldName,
                        'type' => $fieldType,
                        'value' => (string) $sanitizedEmail,
                    ];
                }
                continue;
            }

            if ($fieldRequired && $rawValue === '') {
                $errors[] = $fieldLabel . ' is required.';
            }

            if ($rawValue !== '') {
                $additionalPayload[] = [
                    'label' => $fieldLabel,
                    'name' => $fieldName,
                    'type' => $fieldType,
                    'value' => $rawValue,
                ];
            }
        }

        if ($errors !== []) {
            $this->pushFlash($normalizedSlug, 'error', implode(' ', $errors), $oldValues);
            redirect($redirectTo);
        }

        $captchaError = $validateCaptcha();
        if (is_string($captchaError) && $captchaError !== '') {
            $this->pushFlash($normalizedSlug, 'error', $captchaError, $oldValues);
            redirect($redirectTo);
        }

        $ipAddress = $this->normalizeClientIp((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        $hostname = $this->resolveClientHostname($ipAddress);
        $userAgent = $this->input->text((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 500);
        $additionalFieldsJson = '[]';
        if ($additionalPayload !== []) {
            $encodedAdditional = json_encode($additionalPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($encodedAdditional) && $encodedAdditional !== '') {
                $additionalFieldsJson = $this->input->text($encodedAdditional, 20000);
            }
        }

        try {
            $this->submissions->create([
                'form_slug' => $normalizedSlug,
                'email' => (string) $email,
                'display_name' => $displayName,
                'country' => $country,
                'additional_fields_json' => $additionalFieldsJson,
                'source_url' => $returnPath,
                'ip_address' => $ipAddress,
                'hostname' => $hostname,
                'user_agent' => $userAgent !== '' ? $userAgent : null,
            ]);
        } catch (\Throwable $exception) {
            $message = $exception->getMessage();
            if ($message === '') {
                $message = 'Unable to save your signup right now.';
            }

            $this->pushFlash($normalizedSlug, 'error', $message, $oldValues);
            redirect($redirectTo);
        }

        $this->pushFlash($normalizedSlug, 'success', 'Thanks, your signup has been received.');
        redirect($redirectTo);
    }

    /**
     * Finds one enabled form row by slug.
     *
     * @return array<string, mixed>|null
     */
    private function findEnabledDefinitionBySlug(string $slug): ?array
    {
        $normalizedSlug = strtolower(trim($slug));
        foreach ($this->listEnabledForms() as $row) {
            $rowSlug = strtolower(trim((string) ($row['slug'] ?? '')));
            if ($rowSlug === $normalizedSlug) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Returns available signup-country choices.
     *
     * @return array<string, string>
     */
    private function countryOptions(): array
    {
        return CountryOptions::list(true);
    }

    /**
     * Normalizes additional field definitions for rendering and validation.
     *
     * @param array<string, mixed> $definition
     * @return array<int, array{
     *   label: string,
     *   name: string,
     *   type: string,
     *   required: bool,
     *   input_name: string
     * }>
     */
    private function additionalFieldDefinitions(string $slug, array $definition): array
    {
        /** @var mixed $rawAdditionalFields */
        $rawAdditionalFields = $definition['additional_fields'] ?? [];
        if (!is_array($rawAdditionalFields)) {
            return [];
        }

        $fields = [];
        foreach ($rawAdditionalFields as $rawField) {
            if (!is_array($rawField)) {
                continue;
            }

            $fieldLabelRaw = $this->input->text((string) ($rawField['label'] ?? ''), 120);
            $fieldNameRaw = strtolower($this->input->text((string) ($rawField['name'] ?? ''), 80));
            $fieldNameRaw = preg_replace('/[^a-z0-9_]+/', '_', $fieldNameRaw) ?? '';
            $fieldNameRaw = trim($fieldNameRaw, '_');
            $fieldTypeRaw = strtolower($this->input->text((string) ($rawField['type'] ?? 'text'), 20));
            if (!in_array($fieldTypeRaw, ['text', 'email', 'textarea'], true)) {
                $fieldTypeRaw = 'text';
            }

            if ($fieldLabelRaw === '' || $fieldNameRaw === '') {
                continue;
            }

            $fields[] = [
                'label' => $fieldLabelRaw,
                'name' => $fieldNameRaw,
                'type' => $fieldTypeRaw,
                'required' => (bool) ($rawField['required'] ?? false),
                'input_name' => 'signups_' . $slug . '_' . $fieldNameRaw,
            ];
        }

        return $fields;
    }

    /**
     * Stores one flash payload for a signup-sheet form slug.
     *
     * @param array{email?: string, display_name?: string, country?: string, additional?: array<string, string>} $old
     */
    private function pushFlash(string $slug, string $type, string $message, array $old = []): void
    {
        if (!isset($_SESSION['_raven_waitlist_form_flash']) || !is_array($_SESSION['_raven_waitlist_form_flash'])) {
            $_SESSION['_raven_waitlist_form_flash'] = [];
        }

        $_SESSION['_raven_waitlist_form_flash'][$slug] = [
            'type' => $type === 'success' ? 'success' : 'error',
            'message' => $message,
            'old' => $old,
        ];
    }

    /**
     * Returns and clears one flash payload by slug.
     *
     * @return array{
     *   type: string,
     *   message: string,
     *   old: array{email: string, display_name: string, country: string, additional: array<string, string>}
     * }|null
     */
    private function pullFlash(string $slug): ?array
    {
        $all = $_SESSION['_raven_waitlist_form_flash'] ?? null;
        if (!is_array($all) || !isset($all[$slug]) || !is_array($all[$slug])) {
            return null;
        }

        $raw = $all[$slug];
        unset($_SESSION['_raven_waitlist_form_flash'][$slug]);
        if ((array) ($_SESSION['_raven_waitlist_form_flash'] ?? []) === []) {
            unset($_SESSION['_raven_waitlist_form_flash']);
        }

        $type = ((string) ($raw['type'] ?? 'error')) === 'success' ? 'success' : 'error';
        $message = trim((string) ($raw['message'] ?? ''));
        if ($message === '') {
            return null;
        }

        /** @var mixed $rawOld */
        $rawOld = $raw['old'] ?? [];
        $old = is_array($rawOld) ? $rawOld : [];

        /** @var mixed $rawAdditional */
        $rawAdditional = $old['additional'] ?? [];
        $additional = [];
        if (is_array($rawAdditional)) {
            foreach ($rawAdditional as $fieldName => $fieldValue) {
                if (!is_string($fieldName)) {
                    continue;
                }

                $additional[$fieldName] = $this->input->text((string) $fieldValue, 4000);
            }
        }

        return [
            'type' => $type,
            'message' => $message,
            'old' => [
                'email' => $this->input->text((string) ($old['email'] ?? ''), 254),
                'display_name' => $this->input->text((string) ($old['display_name'] ?? ''), 160),
                'country' => strtolower($this->input->text((string) ($old['country'] ?? ''), 16)),
                'additional' => $additional,
            ],
        ];
    }

    /**
     * Returns normalized client IP when present and valid.
     */
    private function normalizeClientIp(string $rawIp): ?string
    {
        $rawIp = trim($rawIp);
        if ($rawIp === '') {
            return null;
        }

        if (str_contains($rawIp, ',')) {
            $parts = explode(',', $rawIp);
            $rawIp = trim((string) ($parts[0] ?? ''));
        }

        if ($rawIp === '' || filter_var($rawIp, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return $this->input->text($rawIp, 45);
    }

    /**
     * Resolves reverse-DNS hostname for one normalized client IP.
     */
    private function resolveClientHostname(?string $ipAddress): ?string
    {
        if ($ipAddress === null || $ipAddress === '') {
            return null;
        }

        $rawHostname = @gethostbyaddr($ipAddress);
        if (!is_string($rawHostname)) {
            return null;
        }

        $hostname = strtolower(trim($rawHostname));
        if ($hostname === '' || $hostname === $ipAddress || filter_var($hostname, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        $hostname = rtrim($hostname, '.');
        if ($hostname === '' || str_contains($hostname, '..') || preg_match('/[^a-z0-9.-]/', $hostname) === 1) {
            return null;
        }

        return $this->input->text($hostname, 255);
    }
}

