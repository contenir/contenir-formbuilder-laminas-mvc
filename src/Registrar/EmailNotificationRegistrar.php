<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Registrar;

use ArrayObject;
use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Definition\NotificationDefinition;
use Contenir\FormBuilder\Service\BuilderForm;
use Contenir\FormBuilder\Service\TokenReplacer;
use Laminas\Mail\Message;
use Laminas\Mail\Transport\TransportInterface;
use Laminas\Mime\Message as MimeMessage;
use Laminas\Mime\Mime;
use Laminas\Mime\Part as MimePart;
use Psr\Log\LoggerInterface;
use SplObserver;
use SplSubject;
use Throwable;

use function array_filter;
use function array_map;
use function array_values;
use function filter_var;
use function html_entity_decode;
use function is_array;
use function preg_match;
use function preg_replace;
use function preg_split;
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
 * via Laminas\Mail.
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
 */
class EmailNotificationRegistrar implements SplObserver
{
    public function __construct(
        private TokenReplacer $tokens,
        private TransportInterface $transport,
        private ?LoggerInterface $log = null,
    ) {
    }

    public function update(SplSubject $subject): void
    {
        if (! $subject instanceof BuilderForm) {
            return;
        }

        $registry = $this->extractRegistry($subject);
        $form     = $registry['form'] ?? null;
        if (! $form instanceof FormDefinition) {
            return;
        }
        if ((bool) ($registry['spam'] ?? false)) {
            return;
        }

        $values = isset($registry['values']) && is_array($registry['values']) ? $registry['values'] : [];
        $entry  = isset($registry['entry'])  && is_array($registry['entry']) ? $registry['entry'] : [];

        foreach ($form->notifications as $notification) {
            if (! $notification->enabled) {
                continue;
            }
            $this->dispatch($notification, $form, $values, $entry);
        }
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
            $message->setEncoding('UTF-8');
            $message->setSubject((string) preg_replace(
                '/[\r\n]+/',
                ' ',
                $this->tokens->replace($notification->subject, $form, $values, $entry),
            ));

            $this->applyBody($message, $notification->bodyTemplate ?? '', $form, $values, $entry);

            $this->applyAddress(
                $notification->fromAddress,
                $form,
                $values,
                $entry,
                static fn (string $address): Message => $message->setFrom($address),
            );
            $this->applyAddress(
                $notification->replyTo,
                $form,
                $values,
                $entry,
                static fn (string $address): Message => $message->setReplyTo($address),
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
     * Expand the body template and apply it: HTML templates get escaped
     * values and a multipart/alternative body, plain-text templates are set
     * verbatim.
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
            $message->setBody($this->tokens->replace($template, $form, $values, $entry));
            return;
        }

        $this->applyHtmlBody($message, $this->tokens->replaceForHtml($template, $form, $values, $entry));
    }

    /**
     * Apply a resolved HTML notification body as a multipart/alternative
     * MIME message: the HTML part plus a stripped-tag plain-text fallback,
     * so non-HTML clients (and spam scanners) get a readable version.
     */
    private function applyHtmlBody(Message $message, string $body): void
    {
        $textPart           = new MimePart($this->htmlToText($body));
        $textPart->type     = Mime::TYPE_TEXT;
        $textPart->charset  = 'utf-8';
        $textPart->encoding = Mime::ENCODING_QUOTEDPRINTABLE;

        $htmlPart           = new MimePart($body);
        $htmlPart->type     = Mime::TYPE_HTML;
        $htmlPart->charset  = 'utf-8';
        $htmlPart->encoding = Mime::ENCODING_QUOTEDPRINTABLE;

        $mime = new MimeMessage();
        $mime->setParts([$textPart, $htmlPart]);

        $message->setBody($mime);

        $contentType = $message->getHeaders()->get('Content-Type');
        if ($contentType !== false) {
            $contentType->setType('multipart/alternative');
        }
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
        return preg_match('/<[a-z!\/][^>]*>/i', $template) === 1
            || str_contains(strtolower($template), '{entry:fields}');
    }

    /**
     * Reduce HTML to a plaintext fallback. Not a pixel-perfect rendering
     * — just enough for the alternative part to read sensibly when an
     * email client falls back to text/plain.
     */
    private function htmlToText(string $html): string
    {
        $text = (string) preg_replace('!<head\b[^>]*>.*?</head>!is', '', $html);
        $text = (string) preg_replace('!<style\b[^>]*>.*?</style>!is', '', $text);
        $text = (string) preg_replace('!<script\b[^>]*>.*?</script>!is', '', $text);
        $text = (string) preg_replace('!<br\s*/?>!i', "\n", $text);
        $text = (string) preg_replace('!</(p|div|h[1-6]|li|tr)>!i', "\n", $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = (string) preg_replace('/[\t ]+/', ' ', $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);
        $text = trim($text);
        return wordwrap($text, 78);
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
        if ($raw === null || $raw === '') {
            return;
        }
        $resolved = trim($this->tokens->replace($raw, $form, $values, $entry));
        if ($resolved === '') {
            return;
        }
        try {
            $apply($resolved);
        } catch (Throwable $e) {
            // Resolved value isn't a valid address (e.g. unresolved tokens
            // left intact, or a name string accidentally placed in the
            // address slot). Skip rather than abort the whole notification.
        }
    }

    /** @return list<string> */
    private function splitAddresses(string $raw): array
    {
        $parts = preg_split('/[\s,;]+/', $raw) ?: [];
        return array_values(array_filter(
            array_map('trim', $parts),
            static fn (string $s): bool => $s !== '',
        ));
    }

    private function isValidAddress(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
    }

    /** @return array<string, mixed> */
    private function extractRegistry(BuilderForm $form): array
    {
        $registry = $form->registry;
        if ($registry instanceof ArrayObject) {
            return (array) $registry;
        }
        return [];
    }
}
