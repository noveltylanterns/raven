<?php

/**
 * RAVEN CMS
 * ~/private/ext/contact/src/ContactPublicFormRuntime.php
 * Contact extension embedded-form runtime and submit handling.
 * Docs: https://raven.lanterns.io
 */

declare(strict_types=1);

namespace Raven;

use Raven\Core\Config;
use Raven\Core\Extension\EmbeddedFormRuntimeInterface;
use Raven\Core\Security\Csrf;
use Raven\Core\Security\InputSanitizer;
use Raven\Repository\ContactFormRepository;
use Raven\Repository\ContactSubmissionRepository;

use function Raven\Core\Support\redirect;

/**
 * Owns Contact embedded shortcode rendering and submit pipeline.
 */
final class ContactPublicFormRuntime implements EmbeddedFormRuntimeInterface
{
    private InputSanitizer $input;
    private Csrf $csrf;
    private Config $config;
    private ContactFormRepository $forms;
    private ContactSubmissionRepository $submissions;

    public function __construct(
        InputSanitizer $input,
        Csrf $csrf,
        Config $config,
        ContactFormRepository $forms,
        ContactSubmissionRepository $submissions
    ) {
        $this->input = $input;
        $this->csrf = $csrf;
        $this->config = $config;
        $this->forms = $forms;
        $this->submissions = $submissions;
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
        $normalizedSlug = $this->input->slug($slug);
        if ($normalizedSlug === null || $normalizedSlug === '') {
            return '/';
        }

        return '/contact-form/submit/' . rawurlencode($normalizedSlug);
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

        return '<section class="card raven-embedded-form raven-embedded-form-contact" id="' . $sectionId . '" data-raven-form-type="contact" data-raven-form-slug="' . $slug . '">'
            . '<div class="card-body">'
            . '<h3>' . $name . '</h3>'
            . $flashMarkup
            . '<form method="post" action="' . $submitAction . '" novalidate>'
            . $csrfField
            . '<input type="hidden" name="return_path" value="' . $safeReturnPath . '">'
            . '<div class="row g-3">'
            . '<div class="col-12"><label class="form-label">Name</label><input type="text" class="form-control" name="contact_name" placeholder="Your name" value="' . $oldValues['name'] . '" required></div>'
            . '<div class="col-12"><label class="form-label">Email</label><input type="email" class="form-control" name="contact_email" placeholder="you@example.com" value="' . $oldValues['email'] . '" required></div>'
            . '<div class="col-12"><label class="form-label">Message</label><textarea class="form-control" name="contact_message" rows="4" placeholder="How can we help?" required>' . $oldValues['message'] . '</textarea></div>'
            . $additionalFieldMarkup
            . $captchaMarkup
            . '<div class="col-12"><button type="submit" class="btn btn-primary">Send Message</button></div>'
            . '</div>'
            . '</form>'
            . '</div>'
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
            $this->pushFlash($normalizedSlug, 'error', 'This contact form is unavailable right now.');
            redirect($redirectTo);
        }

        $destinations = $this->parseDestinations((string) ($definition['destination'] ?? ''));
        if ($destinations === []) {
            $this->pushFlash($normalizedSlug, 'error', 'This contact form has no valid destination address configured.');
            redirect($redirectTo);
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
            redirect($redirectTo);
        }

        $captchaError = $validateCaptcha();
        if (is_string($captchaError) && $captchaError !== '') {
            $this->pushFlash($normalizedSlug, 'error', $captchaError, $oldValues);
            redirect($redirectTo);
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
            redirect($redirectTo);
        }

        if ($saveMailLocally && !$savedLocally) {
            $notice = 'Thanks, your message has been sent. Local save failed.';
            if (is_string($localSaveError) && $localSaveError !== '') {
                $notice .= ' ' . $localSaveError;
            }
            $this->pushFlash($normalizedSlug, 'success', $notice);
            redirect($redirectTo);
        }

        $this->pushFlash($normalizedSlug, 'success', 'Thanks, your message has been sent.');
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
     * Stores one flash payload for a form slug.
     *
     * @param array{name?: string, email?: string, message?: string, additional?: array<string, string>} $old
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
     *   old: array{name: string, email: string, message: string, additional: array<string, string>}
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
                'input_name' => 'contact_' . $slug . '_' . $fieldNameRaw,
            ];
        }

        return $fields;
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
        $mailPrefix = $this->input->text((string) $this->config->get('mail.prefix', 'Mailer Daemon'), 120);
        if ($mailPrefix === '') {
            $mailPrefix = 'Mailer Daemon';
        }

        $formName = $this->input->text($formName, 120);
        $subject = '[' . $mailPrefix . '] ' . ($formName !== '' ? $formName : 'Submission');
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
     * Sends contact mail using configured global mail agent.
     *
     * @param array<int, string> $destinations
     * @param array<int, string> $ccRecipients
     * @param array<int, string> $bccRecipients
     *
     * @throws \RuntimeException
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
        $agent = strtolower($this->input->text((string) $this->config->get('mail.agent', 'php_mail'), 40));
        if ($agent !== 'php_mail') {
            throw new \RuntimeException('The configured mail agent is not supported yet.');
        }

        $destinationMap = array_fill_keys($destinations, true);
        $ccRecipients = array_values(array_filter($ccRecipients, static fn (string $email): bool => !isset($destinationMap[$email])));
        $toAndCcMap = $destinationMap;
        foreach ($ccRecipients as $ccEmail) {
            $toAndCcMap[$ccEmail] = true;
        }
        $bccRecipients = array_values(array_filter($bccRecipients, static fn (string $email): bool => !isset($toAndCcMap[$email])));

        $fromAddress = $this->configuredMailSenderAddress();
        $fromName = $this->configuredMailSenderName();
        $fromHeader = $fromName !== ''
            ? ($fromName . ' <' . $fromAddress . '>')
            : $fromAddress;
        $subject = str_replace(["\r", "\n"], ' ', $subject);
        $messageId = '<raven-contact-' . bin2hex(random_bytes(12)) . '@' . $this->mailHeaderDomain() . '>';
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $fromHeader,
            'Reply-To: ' . $replyToEmail,
            'Message-ID: ' . $messageId,
            'X-Raven-Contact-Form: ' . $formSlug,
        ];
        $envelopeRecipients = array_values(array_unique(array_merge($destinations, $ccRecipients, $bccRecipients)));

        $transportError = '';
        $sendmailBinary = $this->sendmailBinaryPath();
        if ($sendmailBinary !== null) {
            if (
                $this->sendContactMailViaSendmail(
                    $sendmailBinary,
                    $envelopeRecipients,
                    $destinations,
                    $ccRecipients,
                    $subject,
                    $body,
                    $headers,
                    $fromAddress,
                    $transportError
                )
            ) {
                return;
            }
        }

        // Fallback for environments where direct sendmail execution is unavailable.
        if ($ccRecipients !== []) {
            $headers[] = 'Cc: ' . implode(', ', $ccRecipients);
        }
        if ($bccRecipients !== []) {
            $headers[] = 'Bcc: ' . implode(', ', $bccRecipients);
        }
        $headerString = implode("\r\n", $headers);
        $toRecipients = implode(', ', $destinations);
        $ok = @\mail($toRecipients, $subject, $body, $headerString);
        if ($ok !== true) {
            $suffix = $transportError !== '' ? (' ' . $transportError) : '';
            throw new \RuntimeException('Failed to send contact email via php_mail.' . $suffix);
        }
    }

    /**
     * Returns sendmail binary path from `sendmail_path` ini setting when executable.
     */
    private function sendmailBinaryPath(): ?string
    {
        $rawPath = trim((string) ini_get('sendmail_path'));
        if ($rawPath === '') {
            return null;
        }

        $binary = '';
        if (preg_match('/^(?:"([^"]+)"|\'([^\']+)\'|(\S+))/', $rawPath, $matches) !== 1) {
            return null;
        }

        $binary = (string) ($matches[1] ?? $matches[2] ?? $matches[3] ?? '');
        if ($binary === '' || !is_file($binary) || !is_executable($binary)) {
            return null;
        }

        return $binary;
    }

    /**
     * Sends one contact mail by invoking sendmail directly without `-t`.
     *
     * @param array<int, string> $envelopeRecipients
     * @param array<int, string> $toRecipients
     * @param array<int, string> $ccRecipients
     * @param array<int, string> $baseHeaders
     */
    private function sendContactMailViaSendmail(
        string $sendmailBinary,
        array $envelopeRecipients,
        array $toRecipients,
        array $ccRecipients,
        string $subject,
        string $body,
        array $baseHeaders,
        string $fromAddress,
        string &$error
    ): bool {
        $error = '';
        if ($envelopeRecipients === [] || $toRecipients === []) {
            $error = 'No valid recipients available for sendmail delivery.';
            return false;
        }

        $command = array_merge([$sendmailBinary, '-i', '-f', $fromAddress], $envelopeRecipients);
        $descriptorSpec = [
            0 => ['pipe', 'w'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($command, $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            $error = 'Could not start sendmail process.';
            return false;
        }

        $headers = [];
        foreach ($baseHeaders as $headerLine) {
            $line = trim((string) $headerLine);
            if ($line === '') {
                continue;
            }

            $headers[] = str_replace(["\r", "\n"], '', $line);
        }
        $headers[] = 'To: ' . implode(', ', $toRecipients);
        if ($ccRecipients !== []) {
            $headers[] = 'Cc: ' . implode(', ', $ccRecipients);
        }
        $headers[] = 'Subject: ' . str_replace(["\r", "\n"], ' ', $subject);

        $normalizedBody = str_replace(["\r\n", "\r"], "\n", $body);
        $message = implode("\r\n", $headers) . "\r\n\r\n" . str_replace("\n", "\r\n", $normalizedBody);

        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fwrite($pipes[0], $message);
            fclose($pipes[0]);
        }

        $stdout = '';
        if (isset($pipes[1]) && is_resource($pipes[1])) {
            $stdout = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
        }

        $stderr = '';
        if (isset($pipes[2]) && is_resource($pipes[2])) {
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[2]);
        }

        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            $combined = trim(trim($stdout) . PHP_EOL . trim($stderr));
            $error = $combined !== '' ? ('sendmail exited with status ' . $exitCode . ': ' . $combined) : ('sendmail exited with status ' . $exitCode . '.');
            return false;
        }

        return true;
    }

    /**
     * Returns a safe domain token for RFC Message-ID header generation.
     */
    private function mailHeaderDomain(): string
    {
        $rawDomain = strtolower(trim((string) $this->config->get('site.domain', 'localhost')));
        $host = '';
        if ($rawDomain !== '') {
            $host = (string) parse_url('//' . $rawDomain, PHP_URL_HOST);
            if ($host === '') {
                $host = $rawDomain;
            }
        }

        $host = preg_replace('/[^a-z0-9.-]+/i', '', $host) ?? '';
        $host = trim($host, '.-');
        if ($host === '' || !str_contains($host, '.')) {
            $host = 'localhost.localdomain';
        }

        return $host;
    }

    /**
     * Builds one safe no-reply sender using configured site domain.
     */
    private function defaultNoReplyEmail(): string
    {
        $rawDomain = strtolower(trim((string) $this->config->get('site.domain', 'localhost')));
        $host = '';

        if ($rawDomain !== '') {
            $host = (string) parse_url('//' . $rawDomain, PHP_URL_HOST);
            if ($host === '') {
                $host = $rawDomain;
            }
        }

        $host = preg_replace('/[^a-z0-9.-]+/i', '', $host) ?? '';
        $host = trim($host, '.-');
        if ($host === '' || !str_contains($host, '.')) {
            $host = 'localhost';
        }

        return 'no-reply@' . $host;
    }

    /**
     * Returns configured sender address or fallback.
     */
    private function configuredMailSenderAddress(): string
    {
        $configured = trim((string) $this->config->get('mail.sender_address', ''));
        if ($configured !== '') {
            $normalized = $this->input->email($configured);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return $this->defaultNoReplyEmail();
    }

    /**
     * Returns configured sender display name for outgoing contact mail.
     */
    private function configuredMailSenderName(): string
    {
        $name = $this->input->text((string) $this->config->get('mail.sender_name', 'Postmaster'), 120);
        if ($name === '') {
            $name = 'Postmaster';
        }

        return trim(str_replace(["\r", "\n"], ' ', $name));
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
