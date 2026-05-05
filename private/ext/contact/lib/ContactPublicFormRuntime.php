<?php

/**
 * RAVEN CMS
 * ~/private/ext/contact/lib/ContactPublicFormRuntime.php
 * Contact extension embedded-form runtime and submit handling.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven\Ext;

use Raven\Core\Config;
use Raven\Core\Postmaster;
use Raven\Ext\ContactFormRepository;
use Raven\Ext\ContactSubmissionRepository;
use Raven\Lib\Extension\Public\FormRuntime as ExtensionFormRuntime;
use Raven\Lib\Mail\Address;
use Raven\Lib\Mail\Message;
use Raven\Lib\Security\Csrf;
use Raven\Lib\Security\InputSanitizer;
use Raven\Lib\Transport\Redirect;

/**
 * Owns Contact embedded shortcode rendering and submit pipeline.
 */
final class ContactPublicFormRuntime implements ExtensionFormRuntime
{
    private InputSanitizer $input;
    private Csrf $csrf;
    private Config $config;
    private ContactFormRepository $forms;
    private ContactSubmissionRepository $submissions;
    private Postmaster $postmaster;

    /**
     * Wires up the contact form runtime with its storage, security, and mail dependencies.
     *
     * @param InputSanitizer              $input       Shared input sanitizer for form field normalization.
     * @param Csrf                        $csrf        CSRF validator for form submission protection.
     * @param Config                      $config      Site configuration for subject prefix fallback.
     * @param ContactFormRepository       $forms       Contact form definition storage.
     * @param ContactSubmissionRepository $submissions Contact submission local storage.
     * @param Postmaster                  $postmaster  Shared mail delivery service for contact notifications.
     */
    public function __construct(
        InputSanitizer $input,
        Csrf $csrf,
        Config $config,
        ContactFormRepository $forms,
        ContactSubmissionRepository $submissions,
        Postmaster $postmaster
    ) {
        $this->input = $input;
        $this->csrf = $csrf;
        $this->config = $config;
        $this->forms = $forms;
        $this->submissions = $submissions;
        $this->postmaster = $postmaster;
    }

    public function type(): string
    {
        return 'contact';
    }

    public function extensionKey(): string
    {
        return 'contact';
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
        return 'contact-form-' . $normalizedSlug;
    }

    public function submitAction(string $slug): string
    {
        return '/forms/submit';
    }

    public function render(array $definition, string $returnPath, string $csrfField, string $captchaMarkup): string
    {
        $name = htmlspecialchars(trim((string) ($definition['name'] ?? 'Form')), ENT_QUOTES, 'UTF-8');
        $rawSlug = trim((string) ($definition['slug'] ?? ''));
        $slug = htmlspecialchars($rawSlug, ENT_QUOTES, 'UTF-8');
        $flash = $this->pullFlash($rawSlug);
        $flashMarkup = '';
        $oldValues = [
            'name' => '',
            'email' => '',
            'message' => '',
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
                $oldValues['name'] = htmlspecialchars((string) ($rawOld['name'] ?? ''), ENT_QUOTES, 'UTF-8');
                $oldValues['email'] = htmlspecialchars((string) ($rawOld['email'] ?? ''), ENT_QUOTES, 'UTF-8');
                $oldValues['message'] = htmlspecialchars((string) ($rawOld['message'] ?? ''), ENT_QUOTES, 'UTF-8');
                $oldValues['additional'] = is_array($rawOld['additional'] ?? null) ? (array) $rawOld['additional'] : [];
            }
        }

        $additionalFieldMarkup = '';
        foreach ($this->additionalFieldDefinitions($rawSlug, $definition) as $fieldDefinition) {
            $fieldLabel = htmlspecialchars((string) ($fieldDefinition['label'] ?? ''), ENT_QUOTES, 'UTF-8');
            $fieldName = (string) ($fieldDefinition['name'] ?? '');
            $inputName = htmlspecialchars((string) ($fieldDefinition['input_name'] ?? ''), ENT_QUOTES, 'UTF-8');
            $fieldType = (string) ($fieldDefinition['type'] ?? 'text');
            $requiredAttr = (bool) ($fieldDefinition['required'] ?? false) ? ' required' : '';
            $fieldOptions = is_array($fieldDefinition['options'] ?? null) ? (array) $fieldDefinition['options'] : [];
            $rawOldAdditional = $oldValues['additional'][$fieldName] ?? '';
            $oldSelections = [];
            if (is_array($rawOldAdditional)) {
                foreach ($rawOldAdditional as $selectionValue) {
                    if (!is_scalar($selectionValue)) {
                        continue;
                    }

                    $selection = trim((string) $selectionValue);
                    if ($selection === '' || isset($oldSelections[$selection])) {
                        continue;
                    }

                    $oldSelections[$selection] = $selection;
                }
            } else {
                $selection = trim((string) $rawOldAdditional);
                if ($selection !== '') {
                    $oldSelections[$selection] = $selection;
                }
            }

            if ($fieldType === 'radio' && $fieldOptions !== []) {
                $optionsMarkup = '';
                foreach ($fieldOptions as $optionIndex => $optionValueRaw) {
                    $optionValue = htmlspecialchars((string) $optionValueRaw, ENT_QUOTES, 'UTF-8');
                    $isChecked = isset($oldSelections[(string) $optionValueRaw]) ? ' checked' : '';
                    $radioRequired = $requiredAttr !== '' && $optionIndex === 0 ? ' required' : '';
                    $inputId = htmlspecialchars('contact-' . $rawSlug . '-' . $fieldName . '-option-' . $optionIndex, ENT_QUOTES, 'UTF-8');
                    $optionsMarkup .= '<div class="form-check">'
                        . '<input class="form-check-input" type="radio" id="' . $inputId . '" name="' . $inputName . '" value="' . $optionValue . '"' . $isChecked . $radioRequired . '>'
                        . '<label class="form-check-label" for="' . $inputId . '">' . $optionValue . '</label>'
                        . '</div>';
                }

                $additionalFieldMarkup .= '<div class="col-12"><label class="form-label d-block">' . $fieldLabel . '</label>' . $optionsMarkup . '</div>';
                continue;
            }

            if ($fieldType === 'checkbox') {
                if ($fieldOptions !== []) {
                    $optionsMarkup = '';
                    foreach ($fieldOptions as $optionIndex => $optionValueRaw) {
                        $optionValue = htmlspecialchars((string) $optionValueRaw, ENT_QUOTES, 'UTF-8');
                        $isChecked = isset($oldSelections[(string) $optionValueRaw]) ? ' checked' : '';
                        $checkboxRequired = $requiredAttr !== '' && $optionIndex === 0 ? ' required' : '';
                        $inputId = htmlspecialchars('contact-' . $rawSlug . '-' . $fieldName . '-option-' . $optionIndex, ENT_QUOTES, 'UTF-8');
                        $optionsMarkup .= '<div class="form-check">'
                            . '<input class="form-check-input" type="checkbox" id="' . $inputId . '" name="' . $inputName . '[]" value="' . $optionValue . '"' . $isChecked . $checkboxRequired . '>'
                            . '<label class="form-check-label" for="' . $inputId . '">' . $optionValue . '</label>'
                            . '</div>';
                    }

                    $additionalFieldMarkup .= '<div class="col-12"><label class="form-label d-block">' . $fieldLabel . '</label>' . $optionsMarkup . '</div>';
                    continue;
                }

                $isChecked = isset($oldSelections['1']) ? ' checked' : '';
                $inputId = htmlspecialchars('contact-' . $rawSlug . '-' . $fieldName, ENT_QUOTES, 'UTF-8');
                $additionalFieldMarkup .= '<div class="col-12">'
                    . '<div class="form-check">'
                    . '<input class="form-check-input" type="checkbox" id="' . $inputId . '" name="' . $inputName . '" value="1"' . $isChecked . $requiredAttr . '>'
                    . '<label class="form-check-label" for="' . $inputId . '">' . $fieldLabel . '</label>'
                    . '</div>'
                    . '</div>';
                continue;
            }

            if ($fieldType === 'select' && $fieldOptions !== []) {
                $selectedValue = '';
                foreach ($oldSelections as $selectionValue) {
                    $selectedValue = (string) $selectionValue;
                    break;
                }

                $optionsMarkup = '<option value="">Select...</option>';
                foreach ($fieldOptions as $optionValueRaw) {
                    $optionValueEscaped = htmlspecialchars((string) $optionValueRaw, ENT_QUOTES, 'UTF-8');
                    $selected = $selectedValue === (string) $optionValueRaw ? ' selected' : '';
                    $optionsMarkup .= '<option value="' . $optionValueEscaped . '"' . $selected . '>' . $optionValueEscaped . '</option>';
                }

                $additionalFieldMarkup .= '<div class="col-md-6"><label class="form-label">' . $fieldLabel . '</label>'
                    . '<select class="form-select" name="' . $inputName . '"' . $requiredAttr . '>' . $optionsMarkup . '</select></div>';
                continue;
            }

            $rawValue = '';
            foreach ($oldSelections as $selectionValue) {
                $rawValue = (string) $selectionValue;
                break;
            }
            $fieldValue = htmlspecialchars($rawValue, ENT_QUOTES, 'UTF-8');

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

        return '<section class="raven-embedded-form raven-embedded-form-contact" id="' . $sectionId . '" data-rvn-form-type="contact" data-rvn-form-slug="' . $slug . '">'
            . $flashMarkup
            . '<form method="post" action="' . $submitAction . '" novalidate>'
            . $csrfField
            . '<input type="hidden" name="_rvn_form_type" value="contact">'
            . '<input type="hidden" name="_rvn_form_slug" value="' . $slug . '">'
            . '<input type="hidden" name="return_path" value="' . $safeReturnPath . '">'
            . '<div class="row g-3">'
            . '<div class="col-12"><label class="form-label">Name</label><input type="text" class="form-control" name="contact_name" placeholder="Your Name" value="' . $oldValues['name'] . '" required></div>'
            . '<div class="col-12"><label class="form-label">Email</label><input type="email" class="form-control" name="contact_email" placeholder="Your Email" value="' . $oldValues['email'] . '" required></div>'
            . '<div class="col-12"><label class="form-label">Message</label><textarea class="form-control" name="contact_message" rows="4" placeholder="How can we help?" required>' . $oldValues['message'] . '</textarea></div>'
            . $additionalFieldMarkup
            . $captchaMarkup
            . '<div class="col-12"><button type="submit" class="btn btn-primary">Send Message</button></div>'
            . '</div>'
            . '</form>'
            . '</section>';
    }

    public function submit(string $slug, string $returnPath, callable $validateCaptcha): void
    {
        $normalizedSlug = $this->input->slug($slug);
        if ($normalizedSlug === null || $normalizedSlug === '') {
            Redirect::redirect($returnPath);
        }

        $redirectTo = $returnPath . '#' . $this->anchorId($normalizedSlug);
        if (!$this->csrf->validate($_POST['_csrf'] ?? null)) {
            $this->pushFlash($normalizedSlug, 'error', 'Your session token is invalid. Please retry and submit again.');
            Redirect::redirect($redirectTo);
        }

        $definition = $this->findEnabledDefinitionBySlug($normalizedSlug);
        if ($definition === null) {
            $this->pushFlash($normalizedSlug, 'error', 'This contact form is unavailable right now.');
            Redirect::redirect($redirectTo);
        }

        $destinations = $this->parseDestinations((string) ($definition['destination'] ?? ''));
        if ($destinations === []) {
            $this->pushFlash($normalizedSlug, 'error', 'This contact form has no valid destination address configured.');
            Redirect::redirect($redirectTo);
        }
        $ccRecipients = $this->parseDestinations((string) ($definition['cc'] ?? ''));
        $bccRecipients = $this->parseDestinations((string) ($definition['bcc'] ?? ''));

        $senderName = $this->input->text((string) ($_POST['contact_name'] ?? ''), 160);
        $senderEmailRaw = strtolower($this->input->text((string) ($_POST['contact_email'] ?? ''), 254));
        $senderEmail = $this->input->email($senderEmailRaw);
        $messageRaw = $this->input->html((string) ($_POST['contact_message'] ?? ''), 5000);
        $message = trim((string) preg_replace('/\r\n?/', "\n", strip_tags($messageRaw)));

        $oldValues = [
            'name' => $senderName,
            'email' => $senderEmailRaw,
            'message' => $message,
            'additional' => [],
        ];

        $errors = [];
        if ($senderName === '') {
            $errors[] = 'Name is required.';
        }
        if ($senderEmail === null) {
            $errors[] = 'A valid email address is required.';
        }
        if ($message === '') {
            $errors[] = 'Message is required.';
        }

        $additionalPayload = [];
        foreach ($this->additionalFieldDefinitions($normalizedSlug, $definition) as $fieldDefinition) {
            $inputName = (string) ($fieldDefinition['input_name'] ?? '');
            $fieldName = (string) ($fieldDefinition['name'] ?? '');
            $fieldLabel = (string) ($fieldDefinition['label'] ?? $fieldName);
            $fieldType = (string) ($fieldDefinition['type'] ?? 'text');
            $fieldRequired = (bool) ($fieldDefinition['required'] ?? false);
            $fieldOptions = is_array($fieldDefinition['options'] ?? null) ? (array) $fieldDefinition['options'] : [];

            if ($fieldType === 'checkbox') {
                if ($fieldOptions !== []) {
                    /** @var mixed $rawPosted */
                    $rawPosted = $_POST[$inputName] ?? [];
                    $postedValues = [];
                    if (is_array($rawPosted)) {
                        foreach ($rawPosted as $postedValue) {
                            if (!is_scalar($postedValue)) {
                                continue;
                            }

                            $normalizedValue = $this->input->text((string) $postedValue, 255);
                            if ($normalizedValue === '' || isset($postedValues[$normalizedValue])) {
                                continue;
                            }

                            $postedValues[$normalizedValue] = $normalizedValue;
                        }
                    } elseif (is_scalar($rawPosted)) {
                        $normalizedValue = $this->input->text((string) $rawPosted, 255);
                        if ($normalizedValue !== '') {
                            $postedValues[$normalizedValue] = $normalizedValue;
                        }
                    }

                    $selectedValues = [];
                    $hasInvalidSelection = false;
                    foreach ($postedValues as $postedValue) {
                        if (!in_array($postedValue, $fieldOptions, true)) {
                            $hasInvalidSelection = true;
                            continue;
                        }

                        $selectedValues[$postedValue] = $postedValue;
                    }

                    $selectedValues = array_values($selectedValues);
                    $oldValues['additional'][$fieldName] = $selectedValues;

                    if ($hasInvalidSelection) {
                        $errors[] = $fieldLabel . ' contains an invalid selection.';
                    }
                    if ($fieldRequired && $selectedValues === []) {
                        $errors[] = $fieldLabel . ' is required.';
                    }

                    if ($selectedValues !== []) {
                        $additionalPayload[] = [
                            'label' => $fieldLabel,
                            'value' => implode(', ', $selectedValues),
                        ];
                    }
                    continue;
                }

                /** @var mixed $rawCheckbox */
                $rawCheckbox = $_POST[$inputName] ?? null;
                $isChecked = false;
                if (is_array($rawCheckbox)) {
                    $isChecked = $rawCheckbox !== [];
                } elseif (is_scalar($rawCheckbox)) {
                    $checkboxValue = $this->input->text((string) $rawCheckbox, 10);
                    $isChecked = $checkboxValue !== '' && $checkboxValue !== '0';
                }
                $oldValues['additional'][$fieldName] = $isChecked ? ['1'] : [];
                if ($fieldRequired && !$isChecked) {
                    $errors[] = $fieldLabel . ' is required.';
                }

                if ($isChecked) {
                    $additionalPayload[] = [
                        'label' => $fieldLabel,
                        'value' => 'Yes',
                    ];
                }
                continue;
            }

            $rawValue = $this->input->text((string) ($_POST[$inputName] ?? ''), $fieldType === 'textarea' ? 4000 : 255);
            $oldValues['additional'][$fieldName] = $rawValue;

            if (in_array($fieldType, ['radio', 'select'], true) && $fieldOptions !== []) {
                if ($rawValue !== '' && !in_array($rawValue, $fieldOptions, true)) {
                    $errors[] = $fieldLabel . ' contains an invalid selection.';
                }
                if ($fieldRequired && $rawValue === '') {
                    $errors[] = $fieldLabel . ' is required.';
                }

                if ($rawValue !== '' && in_array($rawValue, $fieldOptions, true)) {
                    $additionalPayload[] = [
                        'label' => $fieldLabel,
                        'value' => $rawValue,
                    ];
                }
                continue;
            }

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
                    'value' => $rawValue,
                ];
            }
        }

        if ($errors !== []) {
            $this->pushFlash($normalizedSlug, 'error', implode(' ', $errors), $oldValues);
            Redirect::redirect($redirectTo);
        }

        $captchaError = $validateCaptcha();
        if (is_string($captchaError) && $captchaError !== '') {
            $this->pushFlash($normalizedSlug, 'error', $captchaError, $oldValues);
            Redirect::redirect($redirectTo);
        }

        $formName = $this->input->text((string) ($definition['name'] ?? 'Contact Form'), 160);
        $subject = $this->buildContactSubject($formName);
        $body = $this->buildContactBody(
            $formName,
            $normalizedSlug,
            $senderName,
            (string) $senderEmail,
            $message,
            $additionalPayload,
            $returnPath
        );

        $saveMailLocally = !array_key_exists('save_mail_locally', $definition)
            || (bool) ($definition['save_mail_locally'] ?? true);
        $savedLocally = false;
        $localSaveError = null;
        if ($saveMailLocally) {
            $additionalFieldsJson = '[]';
            if ($additionalPayload !== []) {
                $encodedAdditional = json_encode($additionalPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (is_string($encodedAdditional) && $encodedAdditional !== '') {
                    $additionalFieldsJson = $this->input->text($encodedAdditional, 20000);
                }
            }

            $ipAddress = $this->normalizeClientIp((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
            $hostname = $this->resolveClientHostname($ipAddress);
            $userAgent = $this->input->text((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 500);

            try {
                $this->submissions->create([
                    'form_slug' => $normalizedSlug,
                    'sender_name' => $senderName,
                    'sender_email' => (string) $senderEmail,
                    'message_text' => $message,
                    'additional_fields_json' => $additionalFieldsJson,
                    'source_url' => $returnPath,
                    'ip_address' => $ipAddress,
                    'hostname' => $hostname,
                    'user_agent' => $userAgent !== '' ? $userAgent : null,
                ]);
                $savedLocally = true;
            } catch (\Throwable $exception) {
                $localSaveError = $exception->getMessage() !== ''
                    ? $exception->getMessage()
                    : 'Failed to save your message locally.';
            }
        }

        try {
            $this->sendContactMail(
                $destinations,
                $ccRecipients,
                $bccRecipients,
                $subject,
                $body,
                (string) $senderEmail,
                $normalizedSlug
            );
        } catch (\RuntimeException $exception) {
            $errorMessage = $exception->getMessage();
            if ($savedLocally) {
                $errorMessage = 'Your message was saved locally, but email delivery failed. ' . $errorMessage;
            } elseif ($localSaveError !== null) {
                $errorMessage = $errorMessage . ' Local save also failed.';
            }

            $this->pushFlash($normalizedSlug, 'error', trim($errorMessage), $oldValues);
            Redirect::redirect($redirectTo);
        }

        if ($saveMailLocally && !$savedLocally) {
            $notice = 'Thanks, your message has been sent. Local save failed.';
            if (is_string($localSaveError) && $localSaveError !== '') {
                $notice .= ' ' . $localSaveError;
            }
            $this->pushFlash($normalizedSlug, 'success', $notice);
            Redirect::redirect($redirectTo);
        }

        $this->pushFlash($normalizedSlug, 'success', 'Thanks, your message has been sent.');
        Redirect::redirect($redirectTo);
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
     * Stores one flash payload for a form slug.
     *
     * @param array{name?: string, email?: string, message?: string, additional?: array<string, string|array<int, string>>} $old
     */
    private function pushFlash(string $slug, string $type, string $message, array $old = []): void
    {
        if (!isset($_SESSION['_raven_contact_form_flash']) || !is_array($_SESSION['_raven_contact_form_flash'])) {
            $_SESSION['_raven_contact_form_flash'] = [];
        }

        $_SESSION['_raven_contact_form_flash'][$slug] = [
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
     *   old: array{name: string, email: string, message: string, additional: array<string, string|array<int, string>>}
     * }|null
     */
    private function pullFlash(string $slug): ?array
    {
        $all = $_SESSION['_raven_contact_form_flash'] ?? null;
        if (!is_array($all) || !isset($all[$slug]) || !is_array($all[$slug])) {
            return null;
        }

        $raw = $all[$slug];
        unset($_SESSION['_raven_contact_form_flash'][$slug]);
        if ((array) ($_SESSION['_raven_contact_form_flash'] ?? []) === []) {
            unset($_SESSION['_raven_contact_form_flash']);
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

                if (is_array($fieldValue)) {
                    $fieldSelections = [];
                    foreach ($fieldValue as $selectionValue) {
                        if (!is_scalar($selectionValue)) {
                            continue;
                        }

                        $selection = $this->input->text((string) $selectionValue, 255);
                        if ($selection === '' || isset($fieldSelections[$selection])) {
                            continue;
                        }

                        $fieldSelections[$selection] = $selection;
                    }

                    $additional[$fieldName] = array_values($fieldSelections);
                    continue;
                }

                $additional[$fieldName] = $this->input->text((string) $fieldValue, 4000);
            }
        }

        return [
            'type' => $type,
            'message' => $message,
            'old' => [
                'name' => $this->input->text((string) ($old['name'] ?? ''), 160),
                'email' => strtolower($this->input->text((string) ($old['email'] ?? ''), 254)),
                'message' => $this->input->text((string) ($old['message'] ?? ''), 5000),
                'additional' => $additional,
            ],
        ];
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
     *   options: array<int, string>,
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
            if ($fieldTypeRaw === 'dropdown') {
                $fieldTypeRaw = 'select';
            }
            if (!in_array($fieldTypeRaw, ['text', 'email', 'textarea', 'radio', 'checkbox', 'select'], true)) {
                $fieldTypeRaw = 'text';
            }
            $fieldOptions = $this->normalizeFieldOptions($rawField['options'] ?? []);

            if ($fieldLabelRaw === '' || $fieldNameRaw === '') {
                continue;
            }

            if (in_array($fieldTypeRaw, ['radio', 'select'], true) && $fieldOptions === []) {
                $fieldTypeRaw = 'text';
            }

            $fields[] = [
                'label' => $fieldLabelRaw,
                'name' => $fieldNameRaw,
                'type' => $fieldTypeRaw,
                'required' => (bool) ($rawField['required'] ?? false),
                'options' => $fieldOptions,
                'input_name' => 'contact_' . $slug . '_' . $fieldNameRaw,
            ];
        }

        return $fields;
    }

    /**
     * @param mixed $rawOptions
     * @return array<int, string>
     */
    private function normalizeFieldOptions(mixed $rawOptions): array
    {
        $optionCandidates = [];
        if (is_array($rawOptions)) {
            $optionCandidates = $rawOptions;
        } elseif (is_string($rawOptions)) {
            $optionCandidates = preg_split('/[\r\n,]+/', $rawOptions) ?: [];
        }

        $options = [];
        foreach ($optionCandidates as $candidate) {
            if (!is_scalar($candidate)) {
                continue;
            }

            $option = trim($this->input->text((string) $candidate, 120));
            if ($option === '' || isset($options[$option])) {
                continue;
            }

            $options[$option] = $option;
            if (count($options) >= 100) {
                break;
            }
        }

        return array_values($options);
    }

    /**
     * Parses one recipient list into valid unique email addresses.
     *
     * @return array<int, string>
     */
    private function parseDestinations(string $rawDestinations): array
    {
        $normalized = $this->input->text($rawDestinations, 1000);
        if ($normalized === '') {
            return [];
        }

        $parts = preg_split('/[;,]+/', $normalized) ?: [];
        $emails = [];
        foreach ($parts as $part) {
            if (!is_string($part) || trim($part) === '') {
                continue;
            }

            $email = $this->input->email(trim($part));
            if ($email === null) {
                continue;
            }

            $emails[$email] = $email;
        }

        return array_values($emails);
    }

    /**
     * Builds one sanitized subject for contact submissions.
     */
    private function buildContactSubject(string $formName): string
    {
        $mailPrefix = $this->input->text($formName, 120);
        if ($mailPrefix === '') {
            $mailPrefix = $this->input->text((string) $this->config->get('site.name', ''), 120);
        }
        if ($mailPrefix === '') {
            $mailPrefix = 'Raven';
        }

        $subject = '[' . $mailPrefix . '] Submission';
        $subject = str_replace(["\r", "\n"], ' ', $subject);
        return trim($subject);
    }

    /**
     * Builds one plain-text contact mail body.
     *
     * @param array<int, array{label: string, value: string}> $additionalFields
     */
    private function buildContactBody(
        string $formName,
        string $formSlug,
        string $senderName,
        string $senderEmail,
        string $message,
        array $additionalFields,
        string $sourceUrl
    ): string {
        $lines = [
            'Contact form submission',
            'Submitted (UTC): ' . gmdate('c'),
            'Form: ' . $formName,
            'Form slug: ' . $formSlug,
            'Source path: ' . $sourceUrl,
            '',
            'Name: ' . $senderName,
            'Email: ' . $senderEmail,
            '',
            'Message:',
            $message,
        ];

        if ($additionalFields !== []) {
            $lines[] = '';
            $lines[] = 'Additional fields:';
            foreach ($additionalFields as $field) {
                $label = $this->input->text((string) ($field['label'] ?? ''), 120);
                $value = $this->input->text((string) ($field['value'] ?? ''), 4000);
                if ($label === '' || $value === '') {
                    continue;
                }

                $lines[] = $label . ': ' . $value;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Sends a contact form notification via the shared Postmaster service.
     *
     * Builds a Message value object from the submission data and delegates all transport,
     * sender-config, sendmail/php_mail selection, and header assembly to Postmaster.
     *
     * @param array<int, string> $destinations  Primary `To:` recipient addresses.
     * @param array<int, string> $ccRecipients  `Cc:` recipient addresses.
     * @param array<int, string> $bccRecipients `Bcc:` recipient addresses.
     * @param string             $subject        Message subject line.
     * @param string             $body           Plain-text message body.
     * @param string             $replyToEmail   Submitter email address for the Reply-To header.
     * @param string             $formSlug       Form slug for the `X-Raven-Contact-Form` header.
     * @throws \RuntimeException When Postmaster reports a delivery failure.
     */
    private function sendContactMail(
        array $destinations,
        array $ccRecipients,
        array $bccRecipients,
        string $subject,
        string $body,
        string $replyToEmail,
        string $formSlug
    ): void {
        $message = (new Message($destinations, $subject, $body))
            ->withCc($ccRecipients)
            ->withBcc($bccRecipients)
            ->withReplyTo($replyToEmail)
            ->withHeader('X-Raven-Contact-Form: ' . Address::sanitizeHeader($formSlug, 120));

        $result = $this->postmaster->send($message);
        if (!(bool) ($result['ok'] ?? false)) {
            throw new \RuntimeException((string) ($result['message'] ?? 'Failed to send contact email.'));
        }
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
