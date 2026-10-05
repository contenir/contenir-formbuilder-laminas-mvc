<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Laminas\Mvc\Controller;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Laminas\Mvc\Loader\FormLoaderInterface;
use Contenir\FormBuilder\Laminas\Mvc\State\FormStateStash;
use Contenir\FormBuilder\Service\FormSubmissionService;
use Contenir\FormBuilder\Service\SubmissionResult;
use Contenir\FormBuilder\Service\TokenReplacer;
use Contenir\Storage\Exception\StorageException;
use Laminas\Form\Exception\ExceptionInterface as FormException;
use Laminas\Http\PhpEnvironment\Request as PhpEnvironmentRequest;
use Laminas\Http\Request as HttpRequest;
use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\Stdlib\ParametersInterface;
use Laminas\View\Model\JsonModel;
use SplObserver;

use function http_build_query;
use function in_array;
use function is_array;
use function is_scalar;
use function is_string;
use function parse_str;
use function preg_match;
use function str_contains;
use function strlen;
use function strstr;
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
 *
 * @api
 *
 * @mago-expect lint:cyclomatic-complexity Kept whole for 2.0 (one action with its response modes); splitting it is a proposed follow-up.
 */
final class SubmitController extends AbstractActionController
{
    /**
     * @param list<SplObserver> $observers Attached to the submission service, in order.
     */
    public function __construct(
        private FormLoaderInterface $loader,
        private FormSubmissionService $service,
        private FormStateStash $stash,
        private TokenReplacer $tokens,
        array $observers = [],
    ) {
        foreach ($observers as $observer) {
            $service->attach($observer);
        }
    }

    /**
     * Answers JSON (405, 400, 404, 422 or 200) when the client accepts JSON
     * or the request cannot be handled, and otherwise redirects.
     *
     * @return JsonModel|Response
     *
     * @throws FormException When Laminas rejects the built form.
     * @throws StorageException When the storage backend cannot store an upload.
     *
     * @mago-expect analysis:mixed-assignment Route and POST parameters are untyped; each is checked before use.
     */
    public function submitAction(): mixed
    {
        $request = $this->getRequest();
        if (! $request instanceof HttpRequest || ! $request->isPost()) {
            return $this->respondJson(['error' => 'Method not allowed'], status: 405);
        }

        $slug = $this->getEvent()->getRouteMatch()?->getParam('slug');
        if (! is_string($slug) || '' === $slug) {
            return $this->respondJson(['error' => 'Missing slug'], status: 400);
        }

        $form = $this->loader->loadBySlug($slug);
        if (null === $form) {
            return $this->respondJson(['error' => 'Form not found'], status: 404);
        }

        /** @var ParametersInterface $postParameters */
        $postParameters = $request->getPost();
        /** @var ParametersInterface $fileParameters */
        $fileParameters = $request->getFiles();
        /** @var array<string, mixed> $post */
        $post = $postParameters->toArray();
        /** @var array<string, mixed> $files */
        $files   = $fileParameters->toArray();
        $anchor  = $post['_anchor'] ?? '';
        $anchor  = $this->resolveAnchor(is_string($anchor) ? $anchor : '');
        $uri     = $request->getUri();
        $referer = SameSiteReferer::resolve($this->serverVar('HTTP_REFERER'), $uri->getHost(), $uri->getPort());
        $result  = $this->service->submit($form, $post, $files, [
            'ip'      => $this->serverVar('REMOTE_ADDR'),
            'user_id' => null,
            'meta'    => [
                'user_agent' => $this->serverVar('HTTP_USER_AGENT'),
                'referer'    => $this->serverVar('HTTP_REFERER'),
            ],
        ]);

        if (! $result->valid && ! $result->isSpam) {
            if ($this->wantsJson()) {
                return $this->respondJson(['ok' => false, 'errors' => $result->errors], status: 422);
            }

            unset($post['_anchor']);
            $this->stash->store($form->slug, $post, $result->errors);

            return $this->redirectToReferrer($referer, null, $anchor);
        }

        return $this->respondToSuccess($form, $result, $anchor, $referer);
    }

    private function redirectTo(string $url): Response
    {
        $response = new Response();
        $response->getHeaders()->addHeaderLine('Location', $url);
        $response->setStatusCode(302);

        return $response;
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
     */
    private function redirectToReferrer(string $referer, ?string $successSlug, string $anchor): Response
    {
        $referer = (string) strstr("{$referer}#", needle: '#', before_needle: true);
        $base    = (string) strstr("{$referer}?", needle: '?', before_needle: true);
        $query   = [];
        parse_str(substr($referer, strlen($base) + 1), $query);
        unset($query['submit']);
        if (null !== $successSlug) {
            $query['submit'] = $successSlug;
        }

        $url = [] === $query ? $base : "{$base}?" . http_build_query($query);

        return $this->redirectTo('' === $anchor ? $url : "{$url}#{$anchor}");
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
        return preg_match('/^[A-Za-z][\w\-]*$/', $value) === 1 ? $value : '';
    }

    /**
     * @return SuccessSettings
     *
     * @mago-expect analysis:mixed-assignment Form settings are decoded JSON; each value is checked before use.
     */
    private function resolveSuccessSettings(FormDefinition $form): array
    {
        $stored = $form->settings['success'] ?? [];
        $stored = is_array($stored) ? $stored : [];

        $mode = $stored['mode'] ?? null;
        $mode = in_array($mode, FormDefinition::SUCCESS_MODES, strict: true)
            ? $mode
            : FormDefinition::SUCCESS_REDIRECT_REFERRER;

        $url     = $stored['redirect_url'] ?? null;
        $title   = $stored['title'] ?? null;
        $message = $stored['message'] ?? null;

        return [
            'mode'         => $mode,
            'redirect_url' => is_string($url) && '' !== $url ? $url : null,
            'title'        => is_string($title) ? $title : null,
            'message'      => is_string($message) ? $message : null,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @mago-expect analysis:deprecated-class JsonModel is the laminas-view 2.x JSON response; its replacement needs laminas-view 3.
     */
    private function respondJson(array $payload, int $status): JsonModel
    {
        $response = $this->getResponse();
        if ($response instanceof Response) {
            $response->setStatusCode($status);
        }

        return new JsonModel($payload);
    }

    /**
     * Valid and spam submissions answer alike, so bots learn nothing.
     *
     * @return JsonModel|Response
     */
    private function respondToSuccess(
        FormDefinition $form,
        SubmissionResult $result,
        string $anchor,
        string $referer,
    ): JsonModel|Response {
        $success = $this->resolveSuccessSettings($form);
        $entry   = null === $result->entryId ? [] : ['id' => $result->entryId];
        $url     = FormDefinition::SUCCESS_REDIRECT_URL === $success['mode'] && null !== $success['redirect_url']
            ? $this->tokens->replaceForUrl($success['redirect_url'], $form, $result->values, $entry)
            : null;

        if (! $this->wantsJson()) {
            return null === $url ? $this->redirectToReferrer($referer, $form->slug, $anchor) : $this->redirectTo($url);
        }

        $payload = ['ok' => true, 'mode' => $success['mode']];
        if (null !== $url) {
            $payload['url'] = $url;
        }

        if (FormDefinition::SUCCESS_INLINE_MESSAGE === $success['mode']) {
            $payload['title']   = $this->tokens->replace((string) $success['title'], $form, $result->values, $entry);
            $payload['message'] = $this->tokens->replace((string) $success['message'], $form, $result->values, $entry);
        }

        return $this->respondJson($payload, status: 200);
    }

    /**
     * @mago-expect analysis:mixed-assignment Server variables are untyped; only scalars are used.
     */
    private function serverVar(string $key, string $default = ''): string
    {
        $request = $this->getRequest();
        $value   = $request instanceof PhpEnvironmentRequest ? $request->getServer($key, $default) : $default;

        return is_scalar($value) ? (string) $value : $default;
    }

    private function wantsJson(): bool
    {
        return str_contains($this->serverVar('HTTP_ACCEPT'), 'application/json');
    }
}
