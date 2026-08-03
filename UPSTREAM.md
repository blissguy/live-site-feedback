# Waiting on MarkUp.io

Things this plugin works around because of how MarkUp.io currently behaves. None of them are
fixable here. Each one says what to check, and what to change once it is fixed.

Everything below was verified against the live API and the shipped SDK build, not inferred from
documentation — in several cases the documentation says the opposite.

Worth re-checking when **1.0.0 GA** ships, since the SDK is still `1.0.0-rc.1`.

Where to look:
- SDK build — https://registry.npmjs.org/@ceros-dev/markup-sdk (`dist-tags.latest`)
- Docs — https://developer.markup.io/
- Dashboard install snippet — the SDK page of your workspace

---

## 1. The advertised CDN URL does not exist

**What happens:** `https://sdk.markup.io/v1.0.0/markup-sdk-ui.min.js` returns 404 `NoSuchKey`.
Only `1.0.0-rc.1` is published, and the install snippet the MarkUp.io dashboard generates points
at the dead `v1.0.0` file. Anyone following it gets a silent no-op.

**What we do:** pin the exact working URL and its integrity hash in `mbm-live-feedback.php`.

**When fixed:** update `MBM_LF_SDK_VERSION`, `MBM_LF_SDK_URL` and `MBM_LF_SDK_SRI` together.
Recompute the hash — never reuse the old one:

```
curl -s <url> | openssl dgst -sha384 -binary | openssl base64 -A
```

**How to check:** does `dist-tags.latest` on npm show a non-rc version, and does
`https://sdk.markup.io/v<version>/markup-sdk-ui.min.js` return 200?

---

## 2. Comments have no screenshots

**What happens:** the SDK never captures one. `THREAD_SCREENSHOT` exists in the shipped bundle
only as an unused value in an upload-purpose enum, and no rasterisation library is bundled. The
API supports attaching one — `GET /threads/:id/screenshot-upload-policy`, upload to S3,
`PUT /threads/:id/screenshot` — but the SDK never calls it.

**What we do:** nothing. Building it ourselves would mean rasterising the page in the browser,
which loses CDN-served images, web fonts and iframes, and there is no thread-created event to
hang it off.

**When fixed:** nothing to change — screenshots should simply start appearing.

**How to check:** leave a comment and see whether a screenshot is attached. In the bundle, look
for `THREAD_SCREENSHOT` being *used* rather than merely defined.

---

## 3. `POST /auth/exchange` — the documented auth header breaks it

**What happens:** the spec lists `Authorization: Bearer <API-KEY-SECRET>` as required. Sending it
returns **401**. The endpoint authenticates by `publicKey` in the request body, with no auth
header at all. Following the documentation is what fails.

**What we do:** `MBM_LF_Api_Client::request()` takes a `no_auth` option, used only here. The
exception is commented at the call site so nobody "corrects" it.

**When fixed:** if the documented behaviour ever becomes true, drop the `no_auth` argument from
the exchange call. Do not do this speculatively — test first.

**How to check:** send the exchange request with the header and see whether it still 401s.

---

## 4. The `markupRole` claim is ignored

**What happens:** sending `project:owner`, `project:viewer`, `project:contributor`, or omitting
the claim entirely, all produce a `project:contributor` session. Everyone signed in via a
customer JWT can comment and resolve; nobody can delete.

**What we do:** still send a role derived from WordPress capabilities, since it is documented and
costs nothing. A role-mapping settings UI was built and **removed** — shipping a control that
silently does nothing is worse than not offering one.

**When fixed:** `MBM_LF_Tokens::role_for()` already computes the right value, so honouring it
would need no change. Only then is a settings UI worth reconsidering.

**How to check:** mint a token claiming `project:owner`, exchange it, and read `markupRole` in
the response.

---

## 5. Webhook deliveries are not signed

**What happens:** registering a webhook returns a 32-character `signingKey` that is never used.
Real deliveries carry no signature header, no digest, and no signature in the body. Confirmed by
a control test: a custom header on a self-test request reached the same endpoint intact, so a
signature header would have survived if one were sent.

**What we do:** treat the endpoint as public. A long random token in the URL is the gate,
deliveries for another workspace are refused, and a delivery can only clear a cache — never write
content or cause an outbound request.

**When fixed:** verify the signature in `MBM_LF_Webhooks::check_token()` alongside the URL token.
Keep the token as well: it costs nothing and still helps.

**How to check:** capture a delivery and look for a signature header.

---

## 6. Response shapes are inconsistent

Three separate inconsistencies, all handled but all worth removing if they are ever unified:

| Where | Shape |
| --- | --- |
| `GET /workspace` | `data` is the object |
| `GET /threads` | `data.threads` is the list, plus totals |
| `GET /webhook-registrations` | `data.data` is the list |

Page addresses differ too: `GET /threads` puts `canonicalUrl` / `originalUrl` at the **top
level**, while the webhook payload nests the same fields under `comment.location`. The two cannot
share a reader.

Timestamps differ as well: threads report **milliseconds since the epoch**, while the rest of the
API uses ISO 8601 strings. `MBM_LF_Threads::to_timestamp()` accepts both.

---

## 7. Motion's documentation is wrong in three places

Not MarkUp.io, but the same problem and the same standing question — worth re-checking whenever
Motion changes their API.

- **Workspace statuses come back as `taskStatuses`**, documented as `statuses`. Reading the
  documented name finds nothing, silently.
- **`GET /v1/projects` requires `workspaceId`**, documented as optional. Without it the answer is
  `Validation failed`.
- **`PATCH /v1/tasks/{id}` rejects `workspaceId`**, documented as required — *"property
  workspaceId should not exist"*. `name` is not required either, despite being documented so.

All three are handled. If Motion corrects them, nothing here breaks: the client accepts either
status field name, always sends a workspace to the projects endpoint, and sends only changed
fields on an update.

---

## Undocumented but useful

Not bugs — just things worth knowing that the documentation does not mention.

- **Rate limits** come back from `GET /auth/config`: 1000 requests and 100 sign-ins per minute.
  The plugin stores them at setup rather than assuming.
- **Installations cannot be edited.** No `PATCH` exists, so changing allowed origins means
  creating a new installation and getting a new public key — hence one installation per
  environment.
- **`allowedOrigins` is required** in practice even though the docs call it optional, and the
  "allow all origins" option silently restricts the installation to customer-JWT only.
- **Undocumented endpoints** the SDK uses: `/auth/sdk-exchange` and `/auth/sdk-exchange/result`
  (the sign-in polling pair), and `POST /markups/:id/read-only`.
- **An extra event** exists in the SDK types but not the docs: `comment:reply:delete`.
