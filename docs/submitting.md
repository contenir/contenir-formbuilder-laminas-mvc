# Submitting

`SubmitController::submitAction()` serves the `forms-submit` route
(`POST /forms/submit/{slug}`). The factory builds a `FormSubmissionService`
and attaches, in order: `StoreSubmissionRegistrar`, `WebhookRegistrar`,
`EmailNotificationRegistrar` (when a mail transport is registered) and the
`formbuilder.observers` services.

## Responses

A client that sends `Accept: application/json` gets JSON; everyone else is
redirected.

| Situation | JSON | Redirect |
| --- | --- | --- |
| Not a POST | 405 `{"error": "Method not allowed"}` | (always JSON) |
| No slug | 400 `{"error": "Missing slug"}` | (always JSON) |
| Unknown form | 404 `{"error": "Form not found"}` | (always JSON) |
| Invalid | 422 `{"ok": false, "errors": {…}}` | Back to the referrer, values and errors stashed |
| Valid or spam | 200 `{"ok": true, "mode": …}` | See success modes |

Spam (a filled honeypot) is answered exactly like a success.

The request context passed to observers is `ip` (`REMOTE_ADDR`), `user_id`
(null) and `meta` (`user_agent`, `referer`). Uploaded files come from the
request.

## Success modes

`FormDefinition::$settings['success']` selects the outcome:

| `mode` | Redirect | JSON adds |
| --- | --- | --- |
| `redirect_referrer` (default, also for unknown modes) | Referrer with `?submit=<slug>` | |
| `redirect_url` | `redirect_url` with merge tags, values URL-encoded; falls back to the referrer when empty | `url` |
| `inline_message` | Referrer with `?submit=<slug>` | `title`, `message` with merge tags |

`{entry:id}` is available once the entry is stored.

## Back to the referrer

The redirect keeps the referrer's path and query, replaces any existing
`submit` parameter and drops its fragment. A POSTed `_anchor` that looks like
an HTML id (`[A-Za-z][\w-]*`) is appended as the fragment, so a mid-page form
scrolls back into view. Without a referrer the target is `/`.

The Referer header comes from the client, so it is only followed back to this
site. A local path is kept, but not `//host` or `/\host`, which browsers treat
as another site. An absolute `http(s)` URL is kept only when its host, and port
if given, match the request's. Anything else, including other schemes,
control characters and any absolute URL when the request host is unknown,
redirects to `/` instead.

## FormStateStash

On an invalid, non-JSON submission the controller stores the POSTed values
(without `_anchor`) and the validation messages in a session container
(`ContenirFormBuilderFlash`), keyed by slug:

```php
$stash->store('contact', $values, $errors);
$stash->consume('contact'); // ['values' => …, 'errors' => …] once, then null
```

Slugs are lower-cased and characters outside `[a-z0-9_-]` become `_`, so
`Contact` and `contact` share an entry. Malformed entries are discarded.
Templates read it through the [`formStashedState`](rendering.md) helper.
