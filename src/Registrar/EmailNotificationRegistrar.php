<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Registrar;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Definition\NotificationDefinition;
use Contenir\FormBuilder\Service\BuilderForm;
use Contenir\FormBuilder\Service\TokenReplacer;
use Contenir\Mail\Message;
use Contenir\Mail\Transport\TransportInterface;
use Override;
use Psr\Log\LoggerInterface;
use SplObserver;
use SplSubject;
use Throwable;

use function array_filter;
use function array_values;
use function explode;
use function filter_var;
use function html_entity_decode;
use function is_array;
use function preg_match;
use function preg_replace;
use function sprintf;
use function str_contains;
use function strip_tags;
use function strtolower;
use function trim;
use function wordwrap;

use const ENT_QUOTES;
use const FILTER_VALIDATE_EMAIL;

/**
 * Sends each enabled notification defined for the form after submission,
 * via contenir-mail.
 *
 * Skips when the entry was flagged as spam, when no notifications are
 * configured, or when the per-notification trigger does not match.
 * Failures are logged via the optional PSR-3 logger rather than
 * propagating — a transport error must never prevent submission
 * persistence.
 *
 * Mirrors the Zend_Mail-bound variant kept in admin4 for the legacy
 * ZF1 admin module; the runtime semantics (token replacement, address
 * splitting, validation, From/Reply-To handling) are identical.
 *
 * @api
 *
 * @mago-expect lint:cyclomatic-complexity Kept whole for 2.0 (message assembly, addressing and HTML-to-text in one registrar); splitting it is a proposed follow-up.
 */
final class EmailNotificationRegistrar implements SplObserver
{
    public function __construct(
        private TokenReplacer $tokens,
        private TransportInterface $transport,
        private ?LoggerInterface $log = null,
    ) {}

    /**
     * @mago-expect analysis:mixed-assignment The registry is an untyped bag; each entry is checked before use.
     * @mago-expect analysis:less-specific-argument Registry values and entry are keyed by field and attribute name.
     */
    #[Override]
    public function update(SplSubject $subject): void
    {
        $registry = $subject instanceof BuilderForm ? $subject->registry?->getArrayCopy() ?? [] : [];
        $form     = $registry['form'] ?? null;
        if (! $form instanceof FormDefinition || true === ($registry['spam'] ?? false)) {
            return;
        }

        $values = $registry['values'] ?? [];
        $entry  = $registry['entry'] ?? [];
        $values = is_array($values) ? $values : [];
        $entry  = is_array($entry) ? $entry : [];

        foreach ($form->notifications as $notification) {
            if (! $notification->enabled) {
                continue;
            }
            $this->dispatch($notification, $form, $values, $entry);
        }
    }

    /**
     * Resolve tokens on a single From / Reply-To address and hand the
     * result to the caller's setter. Failures (invalid email after
     * resolution, e.g. an admin who put name tokens in the from-
     * address slot) are swallowed so one bad notification field
     * cannot suppress every other notification on the same submission.
     *
     * @param array<string, mixed>             $values
     * @param array<string, mixed>             $entry
     * @param callable(string): Message        $apply
     */
    private function applyAddress(
        ?string $raw,
        FormDefinition $form,
        array $values,
        array $entry,
        callable $apply,
    ): void {
        if (null === $raw || '' === $raw) {
            return;
        }
        $resolved = trim($this->tokens->replace($raw, $form, $values, $entry));
        if ('' === $resolved) {
            return;
        }
        try {
            $apply($resolved);
        } catch (Throwable $e) {
            $this->log?->notice(sprintf('Form notification address "%s" skipped: %s', $resolved, $e->getMessage()));
        }
    }

    /**
     * Expand the body template and apply it: HTML templates get escaped
     * values and a multipart/alternative body, plain-text templates a UTF-8
     * text/plain body, verbatim.
     *
     * @param array<string, mixed> $values
     * @param array<string, mixed> $entry
     */
    private function applyBody(
        Message $message,
        string $template,
        FormDefinition $form,
        array $values,
        array $entry,
    ): void {
        if (! $this->isHtmlTemplate($template)) {
            $message->setText($this->tokens->replace($template, $form, $values, $entry));

            return;
        }

        $body = $this->tokens->replaceForHtml($template, $form, $values, $entry);
        $message->setText($this->htmlToText($body));
        $message->setHtml($body);
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed> $entry
     */
    private function dispatch(
        NotificationDefinition $notification,
        FormDefinition $form,
        array $values,
        array $entry,
    ): void {
        try {
            $message = new Message();
            $message->setSubject((string) preg_replace(
                '/[\r\n]+/',
                replacement: ' ',
                subject: $this->tokens->replace($notification->subject, $form, $values, $entry),
            ));

            $this->applyBody($message, $notification->bodyTemplate ?? '', $form, $values, $entry);

            $this->applyAddress(
                $notification->fromAddress,
                $form,
                $values,
                $entry,
                $message->setFrom(...),
            );
            $this->applyAddress(
                $notification->replyTo,
                $form,
                $values,
                $entry,
                $message->setReplyTo(...),
            );

            foreach ($this->splitAddresses($notification->toAddress) as $recipient) {
                $resolved = $this->tokens->replace($recipient, $form, $values, $entry);
                if ($this->isValidAddress($resolved)) {
                    $message->addTo($resolved);
                }
            }

            $this->transport->send($message);
        } catch (Throwable $e) {
            $this->log?->warning(sprintf(
                'Form notification "%s" failed for form "%s": %s',
                $notification->name,
                $form->slug,
                $e->getMessage(),
            ));
        }
    }

    /**
     * Reduce HTML to a plaintext fallback. Not a pixel-perfect rendering
     * — just enough for the alternative part to read sensibly when an
     * email client falls back to text/plain.
     */
    private function htmlToText(string $html): string
    {
        $text = (string) preg_replace(
            [
                '!<head\b[^>]*>.*?</head>!is',
                '!<style\b[^>]*>.*?</style>!is',
                '!<script\b[^>]*>.*?</script>!is',
                '!<br\s*/?>!i',
                '!</(p|div|h[1-6]|li|tr)>!i',
            ],
            ['', '', '', "\n", "\n"],
            $html,
        );
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES, encoding: 'UTF-8');
        $text = (string) preg_replace(['/[\t ]+/', '/\n{3,}/'], [' ', "\n\n"], $text);

        return wordwrap(trim($text), width: 78);
    }

    /**
     * Whether the body template, before merge tags are expanded, is HTML: it
     * contains markup or `{entry:fields}`, which expands to an HTML table.
     *
     * Decided on the template, never the resolved body: otherwise a visitor
     * could turn a plain-text notification into an HTML one by submitting
     * markup, which would then be rendered unescaped.
     */
    private function isHtmlTemplate(string $template): bool
    {
        return (
            preg_match('/<[a-z!\/][^>]*>/i', $template) === 1
                || str_contains(strtolower($template), '{entry:fields}')
        );
    }

    private function isValidAddress(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
    }

    /** @return list<string> */
    private function splitAddresses(string $raw): array
    {
        $parts = explode(' ', trim((string) preg_replace('/[\s,;]+/', replacement: ' ', subject: $raw)));

        return array_values(array_filter($parts, static fn(string $part): bool => '' !== $part));
    }
}
