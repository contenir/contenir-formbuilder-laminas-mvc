<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Controller;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Laminas\Mvc\Loader\LaminasDbFormLoader;
use Contenir\FormBuilder\Laminas\Mvc\State\FormStateStash;
use Contenir\FormBuilder\Service\FormSubmissionService;
use Contenir\FormBuilder\Service\TokenReplacer;
use Laminas\Http\Request as HttpRequest;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use SplObserver;

use function http_build_query;
use function in_array;
use function is_array;
use function is_string;
use function parse_str;
use function preg_match;
use function str_contains;
use function strpos;
use function substr;

/**
 * Public POST endpoint for site submissions.
 *
 * Routes typically map at `/forms/submit/{slug}`. The controller:
 *  - Resolves the form by slug via {@see LaminasDbFormLoader}.
 *  - Hands off to {@see FormSubmissionService} for build / validate /
 *    spam-detection / dispatch.
 *  - Branches on the form's `settings.success.mode` for the response:
 *    redirect_referrer (default) / redirect_url / inline_message.
 *  - Returns JSON when `Accept: application/json` is set on the
 *    request, otherwise redirects.
 *
 * Successful referrer redirects carry `?submit={slug}` so the section
 * block on the next render can swap to its success state. Validation
 * failures redirect with no query at all — the referring page reads
 * the submitted values + validator messages back out of
 * {@see FormStateStash} keyed by slug, which is sufficient signal to
 * rehydrate the form with errors visible.
 *
 * If the request includes an `_anchor` field (a sanitised HTML id, e.g.
 * `form-contact`) it is appended as a fragment to referrer redirects
 * so the browser scrolls back to the form after a mid-page submission.
 *
 * Observers (registrars) are attached by the consumer site via
 * {@see ConfigProvider}'s factory wiring or by passing an array of
 * SplObserver instances at construction time.
 *
 * @phpstan-type SuccessSettings array{
 *     mode: string,
 *     redirect_url: ?string,
 *     title: ?string,
 *     message: ?string,
 * }
 */
class SubmitController extends AbstractActionController
{
    /** @param list<SplObserver> $observers */
    public function __construct(
        private LaminasDbFormLoader $loader,
        private FormSubmissionService $service,
        private FormStateStash $stash,
        private TokenReplacer $tokens,
        private array $observers = [],
    ) {
        foreach ($observers as $observer) {
            $service->attach($observer);
        }
    }

    public function submitAction(): mixed
    {
        $request = $this->getRequest();
        if (! $request->isPost()) {
            return $this->respondJson(['error' => 'Method not allowed'], 405);
        }

        $slug = (string) $this->params()->fromRoute('slug', '');
        if ($slug === '') {
            return $this->respondJson(['error' => 'Missing slug'], 400);
        }

        $form = $this->loader->loadBySlug($slug);
        if ($form === null) {
            return $this->respondJson(['error' => 'Form not found'], 404);
        }

        $context = [
            'ip'      => $this->getServerVar('REMOTE_ADDR'),
            'user_id' => null,
            'meta'    => [
                'user_agent' => (string) $this->getServerVar('HTTP_USER_AGENT'),
                'referer'    => (string) $this->getServerVar('HTTP_REFERER'),
            ],
        ];

        $post   = $request->getPost()->toArray();
        $files  = $_FILES;
        $anchor = $this->resolveAnchor(is_string($post['_anchor'] ?? null) ? $post['_anchor'] : '');
        $result = $this->service->submit($form, $post, $files, $context);

        $isSuccess = $result->valid || $result->isSpam;
        if (! $isSuccess) {
            if ($this->wantsJson()) {
                return $this->respondJson(['ok' => false, 'errors' => $result->errors], 422);
            }
            $this->stash->store($form->slug, $this->stripInternalFields($post), $result->errors);
            return $this->redirectToReferrer(null, $anchor);
        }

        $success = $this->resolveSuccessSettings($form);
        $entry   = $result->entryId !== null ? ['id' => $result->entryId] : [];

        if ($this->wantsJson()) {
            $payload = ['ok' => true, 'mode' => $success['mode']];
            if (
                $success['mode'] === FormDefinition::SUCCESS_REDIRECT_URL
                && is_string($success['redirect_url']) && $success['redirect_url'] !== ''
            ) {
                $payload['url'] = $this->tokens->replaceForUrl(
                    $success['redirect_url'],
                    $form,
                    $result->values,
                    $entry,
                );
            } elseif ($success['mode'] === FormDefinition::SUCCESS_INLINE_MESSAGE) {
                $payload['title']   = $this->tokens->replace(
                    (string) $success['title'],
                    $form,
                    $result->values,
                    $entry,
                );
                $payload['message'] = $this->tokens->replace(
                    (string) $success['message'],
                    $form,
                    $result->values,
                    $entry,
                );
            }
            return $this->respondJson($payload, 200);
        }

        if (
            $success['mode'] === FormDefinition::SUCCESS_REDIRECT_URL
            && is_string($success['redirect_url']) && $success['redirect_url'] !== ''
        ) {
            $url = $this->tokens->replaceForUrl(
                $success['redirect_url'],
                $form,
                $result->values,
                $entry,
            );
            return $this->redirect()->toUrl($url);
        }

        return $this->redirectToReferrer($form->slug, $anchor);
    }

    /**
     * @return SuccessSettings
     */
    private function resolveSuccessSettings(FormDefinition $form): array
    {
        $stored = is_array($form->settings['success'] ?? null) ? $form->settings['success'] : [];

        $mode = $stored['mode'] ?? null;
        $mode = is_string($mode) && in_array($mode, FormDefinition::SUCCESS_MODES, true)
            ? $mode
            : FormDefinition::SUCCESS_REDIRECT_REFERRER;

        return [
            'mode'         => $mode,
            'redirect_url' => is_string($stored['redirect_url'] ?? null) ? $stored['redirect_url'] : null,
            'title'        => is_string($stored['title'] ?? null) ? $stored['title'] : null,
            'message'      => is_string($stored['message'] ?? null) ? $stored['message'] : null,
        ];
    }

    /**
     * Build the post-submit redirect back to the referring page.
     *
     * Pass the form slug to flag a successful submission ($_GET['submit']
     * picks the form section that should swap to its success state on
     * re-render) or null for the invalid path — failures are signalled
     * by the {@see FormStateStash} entry, so no query param is needed.
     *
     * Any pre-existing `submit` query value on the referer is replaced,
     * so two submissions in the same session don't accumulate
     * `?submit=...&submit=...`.
     *
     * The Referer header is client-supplied, so it is reduced to a local
     * path or a same-host URL by {@see SameSiteReferer} first.
     */
    private function redirectToReferrer(?string $successSlug, string $anchor = ''): Response
    {
        $request  = $this->getRequest();
        $uri      = $request instanceof HttpRequest ? $request->getUri() : null;
        $referer  = SameSiteReferer::resolve(
            $this->getServerVar('HTTP_REFERER'),
            $uri?->getHost(),
            $uri?->getPort(),
        );
        $hashPos  = strpos($referer, '#');
        if ($hashPos !== false) {
            $referer = substr($referer, 0, $hashPos);
        }
        $queryPos = strpos($referer, '?');
        $base     = $queryPos !== false ? substr($referer, 0, $queryPos) : $referer;
        $rawQuery = $queryPos !== false ? substr($referer, $queryPos + 1) : '';

        parse_str($rawQuery, $query);
        unset($query['submit']);
        if ($successSlug !== null && $successSlug !== '') {
            $query['submit'] = $successSlug;
        }

        $url = $base;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }
        if ($anchor !== '') {
            $url .= '#' . $anchor;
        }
        return $this->redirect()->toUrl($url);
    }

    /**
     * Restrict an incoming `_anchor` value to a safe HTML id shape.
     *
     * Anything outside `[A-Za-z][\w\-]*` is silently dropped — the
     * value is concatenated straight into a redirect URL, so we do
     * not want callers smuggling in additional path or query
     * components via this field.
     */
    private function resolveAnchor(string $value): string
    {
        if ($value === '') {
            return '';
        }
        return preg_match('/^[A-Za-z][\w\-]*$/', $value) === 1 ? $value : '';
    }

    /**
     * Drop the controller's own helper fields before stashing.
     *
     * These are render-time hints (anchor target, CSRF tokens emitted
     * by Laminas\Form, the honeypot) — re-presenting them in the form
     * would either leak the wrong value or be rejected on the next
     * submission.
     *
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function stripInternalFields(array $post): array
    {
        unset($post['_anchor']);
        return $post;
    }

    private function wantsJson(): bool
    {
        $accept = (string) $this->getServerVar('HTTP_ACCEPT');
        return str_contains($accept, 'application/json');
    }

    private function getServerVar(string $key, string $default = ''): string
    {
        return (string) ($_SERVER[$key] ?? $default);
    }

    /** @param array<string, mixed> $payload */
    private function respondJson(array $payload, int $status): JsonModel
    {
        $this->getResponse()->setStatusCode($status);
        return new JsonModel($payload);
    }
}
