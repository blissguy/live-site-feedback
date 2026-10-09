# Waiting on MarkUp.io

Things this plugin works around because of how MarkUp.io currently behaves. None of them are
fixable here. Each one says what to check, and what to change once it is fixed.

Everything below was verified against the live API and the shipped SDK build, not inferred from
documentation — in several cases the documentation says the opposite.

Last checked **2026-10-09**, against the docs MarkUp.io last updated on 2026-09-21 and SDK
`1.0.0-rc.7`, the build the plugin loads from 0.18.0. Worth re-checking whenever the docs change
and when **1.0.0 GA** ships.

Where to look:
- SDK builds: https://registry.npmjs.org/@ceros-dev/markup-sdk (`dist-tags.latest` is still
  rc.1; `dist-tags.next` is the newest release candidate)
- Each build's files and hashes: `https://sdk.markup.io/v<version>/manifest.json`
- SDK README and types, which say more than the website: `README.md` and `dist/types/ui.d.ts` in
  the npm package
- Docs: https://developer.markup.io/sdk/ and https://developer.markup.io/api-specs/
- Dashboard install snippet: the SDK page of your workspace

| # | Issue | Status on 2026-10-09 |
| --- | --- | --- |
| 1 | The advertised CDN URL doesn't exist | Open. No stable release yet. |
| 2 | Comments had no screenshots | Fixed in rc.4, as a plugin. Used from 0.18.0. |
| 3 | The documented header broke `/auth/exchange` | Fixed upstream. |
| 4 | `markupRole` was ignored | Fixed upstream. Broke reviewers until 0.17.1. |
| 5 | Deliveries aren't signed | Open as far as we know. Not re-tested since July. |
| 6 | Registrations can't be scoped | Open. Confirmed by the 2026-09-21 docs. |
| 7 | Response shapes are inconsistent | Open. |
| 8 | Motion's docs are wrong | Motion, not MarkUp.io. Not re-checked. |
| 9 | Deliveries go missing silently | Open. Worked around from 0.17.0. |
| 10 | The thread list hides resolved threads | Open. The default is still undocumented. |

---

## 1. The advertised CDN URL does not exist

**What happens:** `https://sdk.markup.io/v1.0.0/markup-sdk-ui.min.js` returns 404, and so do the
`/latest/` and `/v1/` aliases the README describes, because there is no stable release yet. The
README and the dashboard's install snippet both point at `/v1.0.0/`. Release candidates are
published: `/next/` serves the newest (1.0.0-rc.7 on 2026-10-09).

**What we do:** pin an exact release candidate in `mbm-live-feedback.php`, with its integrity hash.
Since rc.7 every version folder has a `manifest.json` listing the sha384 of each file, so upgrading
is: change `MBM_LF_SDK_VERSION` and both URLs, then copy both hashes from
`https://sdk.markup.io/v<version>/manifest.json`. Recompute one to be sure:

```
curl -s <url> | openssl dgst -sha384 -binary | openssl base64 -A
```

**When fixed:** move to the stable `/v1.0.0/` files once they exist. Keep pinning an exact version.

**How to check:** does `dist-tags.latest` on npm show a non-rc version, and does
`https://sdk.markup.io/v1.0.0/manifest.json` return 200?

---

## 2. Comments had no screenshots (fixed in rc.4, as a plugin)

**What happened:** up to rc.3 the SDK never captured one.

**Now:** rc.4 added a screenshot plugin, `markup-sdk-plugin-screenshot.min.js`, published next to
the main library. Loaded after it, it attaches `MarkUpSDK.plugins.screenshot()`, which is passed to
`render({plugins: [...]})`. With `{auto: true}` it captures the clicked element when a pin is
placed and attaches the PNG to the comment through the normal upload pipeline, so it arrives as an
attachment on the first message rather than as the thread's own screenshot.

**What we do (0.18.0):** a setting on the Feedback bar tab loads it in auto mode.

---

## 3. `POST /auth/exchange` — the documented auth header broke it (fixed upstream)

**What happened:** until at least July 2026 the spec listed `Authorization: Bearer <API-KEY-SECRET>`
as required, and sending it returned **401**. The endpoint authenticated by `publicKey` in the
request body.

**Now (re-tested 2026-10-09):** it answers 200 with or without the header. `publicKey` has moved
out of the body: it must be a query parameter or an `X-Markup-Public-Key` header, or the answer is
400 "publicKey is required".

**What we do:** nothing. Only the SDK calls this endpoint, and it sends the key the way the API
wants. Kept here because the `no_auth` option in `MBM_LF_Api_Client::request()` exists for it.

---

## 4. The `markupRole` claim was ignored (fixed upstream, and it mattered)

**What happened:** until some point after 2026-10-03, sending `project:owner`, `project:viewer`,
`project:contributor`, or omitting the claim all produced a `project:contributor` session.

**Now (re-tested 2026-10-09):** the claim is honored. Each role comes back exactly as sent, an
omitted claim gives contributor, and any other value is refused with a 400, so sign-in fails.

**What it broke:** the plugin had always sent `project:viewer` to anyone who couldn't edit posts,
on the reasoning that it was harmless while ignored. Once honored, reviewers with member accounts
(learners on learnwithvelo.com) became read-only overnight, with nothing in WordPress to say so.

**What we do (0.17.1):** `MBM_LF_Tokens::default_role_for()` gives owner to people who can delete
others' posts and contributor to everyone else. Only people the visibility setting lets see the
bar get a token, so viewer is never a sensible default. `mbm_lf_user_role` can still choose it.

**How to check:** mint a token with each claim, exchange it, and read `markupRole` in the response.

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

**Since then:** the 2026-09-21 docs add `POST /webhook-registrations/:id/refresh-signing-key`, so
the key is meant for something, but the webhooks page still says nothing about how a delivery is
signed or how to verify one. Not re-tested: the access log shows that a delivery arrived, not its
headers.

**How to check:** capture a delivery's headers (a temporary log in `MBM_LF_Webhooks::handle()` is
enough) and look for a signature header.

---

## 6. Webhook registrations cannot be scoped

**What happens:** a registration takes only `url`, `workspaceId`, `eventTypes` and `enabled` —
confirmed in the resource spec and against a real registration record, whose entire field list is
`id, workspaceId, url, urlMd5, createdAt, modifiedAt, enabled, active, signingKey, eventType,
eventTypes`. There is no markup, project or URL filter. One registration therefore receives every
comment on every markup in the workspace.

This is not a corner case. `markupId` is the only unit of scoping the product has — the SDK takes
one and has no project or workspace option — and on a single-workspace plan every client site
shares one workspace. Without filtering, each site hears about every other site's feedback and
acts on it. That is exactly what happened: comments from two unrelated sites became tasks in a
third site's Motion project.

**What we do:** `MBM_LF_Threads::owns_delivery()` refuses any delivery whose markup this site does
not own, before the payload has any effect at all. The markup id is the test; the page URL is only
consulted when the delivery carries no markup id.

**When fixed:** if registrations gain a markup or project filter, pass it at registration time and
keep this check anyway — a filter set upstream is not visible from here, and the check is cheap.

**Since then:** the 2026-09-21 docs confirm it. Create takes `url`, `workspaceId`, `eventTypes`
and `enabled`; there is still no markup, project or URL filter. They also document an update,
`POST /webhook-registrations/:id` (not PATCH) taking `url`, `eventTypes` and `enabled`, so a moved
site could have its registration's address changed in place instead of deleted and recreated.
Check and repair setup still creates and deletes; that works, and keeps the old registration
untouched until the new one exists.

**How to check:** look for a scoping field in the create body for
`/api-specs/resources/admin/webhook_registrations/`.

---

## 7. Response shapes are inconsistent

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

The 2026-09-21 webhooks page still documents `CommentNotification` without `location` or
`browserContext`: only `id`, timestamps, `resolved`, `firstMessage`, `appUrl`, `messageCount` and an
optional `screenshot`. Real deliveries do carry `comment.location`, and `owns_delivery()` falls
back to it.

---

## 8. Motion's documentation is wrong in three places

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

## 9. Webhook deliveries go missing without anyone noticing

**What happens:** two separate ways, both found on one live site that heard nothing for eight weeks
while MarkUp.io kept sending.

- **Redirects are not followed.** The registration still pointed at the site's old temporary
  address, which 301s to the new one. Deliveries stop at the redirect.
- **The sender looks like a bot.** Deliveries come from AWS addresses (seen: `34.228.29.155`,
  `98.92.152.238`) with an empty user agent. Cloudflare's free Bot Fight Mode turns them away
  before WordPress sees them, and it can't be given an exception: Skip rules have no effect on it.
  The `34.229.37.143` / `.152` pair in Ceros's Cloudflare article is the page proxy, not the
  webhook sender.

There is no delivery log, no retry report and no way to send a test delivery, so neither failure is
visible from MarkUp.io's side either. Retries happen (three attempts over about three minutes
were seen), then the delivery is gone. The 2026-09-21 docs still say nothing about retries, sender
addresses, user agent, redirects or delivery history.

**What we do:** Motion no longer depends on deliveries. `MBM_LF_Motion_Watch` asks for every
thread every five minutes and compares it with a small note of the last answer; a delivery just
makes that happen at once. The Motion integration tab shows when the last check ran. Check and
repair setup registers the current address, removes registrations for old addresses once
`MBM_LF_Address::probe()` confirms they are gone or redirect here, and says that a firewall may be
in the way.

**When fixed:** if MarkUp.io adds a delivery log or a test delivery, show it on the Setup tab. Keep
the five-minute check either way.

**How to check:** after a comment, compare the server's access log with `last_delivery` in
`mbm_lf_options`.

---

## 10. The thread list hides resolved threads

**What happens:** `GET /threads` defaults to `resolved=false`, which the documentation doesn't say,
so asking once only ever returns open threads. Asking for the others needs `resolved=1`:
`resolved=true` is refused with "resolved must be a boolean value". `limit` above 200 is refused,
and paging uses `offset` against the returned `total`.

The 2026-09-21 docs now list the parameters (`resolved`, `limit`, `offset`, `cursorId`, `order`,
`viewMode`, `q` and `withThreadOrMessageId`) but still give no defaults and no maximum `limit`.

**What we do:** `MBM_LF_Threads::fetch_all_raw()` asks twice, once per state, and pages through
each.

**When fixed:** if a single request can return both, use it and drop the second.

**How to check:** `GET /threads?markupId=…` with no `resolved`, and compare `total` with
`allTotals`.

---

## Undocumented but useful

Not bugs, just things worth knowing that the documentation does not mention, or did not when they
were found.

- **Rate limits** come back from `GET /auth/config`: 1000 requests and 100 sign-ins per minute.
  The plugin stores them at setup rather than assuming.
- **Installations cannot be edited.** No `PATCH` exists, so changing allowed origins means
  creating a new installation and getting a new public key, hence one installation per
  environment. Still true on 2026-10-09: the docs only cover `GET /auth/config`, and
  installations themselves aren't documented at all. Check and repair setup makes a new one when
  the address changes.
- **`allowedOrigins` is required** in practice even though the docs call it optional, and the
  "allow all origins" option silently restricts the installation to customer-JWT only.
- **Endpoints the SDK uses** that weren't documented in July: `/auth/sdk-exchange` and
  `/auth/sdk-exchange/result` (the sign-in polling pair), now documented under Feedback API
  authentication; `POST /markups/:id/read-only`, not re-checked.
- **Thread endpoints documented on 2026-09-21** that the plugin doesn't use yet:
  `POST /threads/:id/resolve` and `/unresolve`, `POST /threads/:id/read`, and screenshot upload
  and read. Threads also carry `read` and `unreadMessageCount`, both per caller. Called with the
  API key, those report the key owner's state, not a reviewer's.
- **A new event type,** `comment_screenshot_failed`, sits alongside the five the plugin registers
  for. The plugin doesn't subscribe to it.
- **An extra event** exists in the SDK types but not the docs: `comment:reply:delete`.
- **A link straight to one comment** is `https://app.markup.io/markup/{markupId}/#thread/{threadId}`,
  the same form deliveries carry in `comment.appUrl`. On the site itself, adding
  `commentThread={threadId}` to the page's address makes the bar open that comment on load. This is
  documented from rc.7, and MarkUp.io's notification emails use it.
- **A session can be bound to one markup** by putting `markupId` in the customer JWT. The exchange
  echoes it back, and the session is refused everywhere else.
- **Every comment records the browser it was left in.** `browserContext` on a thread carries
  `viewportWidth`/`viewportHeight` from `window.innerWidth`/`innerHeight`, read at the moment of
  commenting, plus browser, version, OS, screen size and `platform`. Found in July with a comment
  left at 425 × 956 against nine others at 1800 × 1043; documented since, on the threads resource
  and in the rc.7 README, which shows it in a panel behind each comment's device icon. Still
  absent from the documented `CommentNotification`, so the plugin reads it from the thread.
- **Up to rc.5 the device came from the user agent, not the window,** so every SDK comment was
  filed as desktop and resizing changed nothing. From rc.6 it's decided by viewport width,
  re-evaluated as the window crosses a breakpoint, and the `deviceScope` render option shows only
  the reader's own device by default. Comments made before can't be refiled, so on learnwithvelo.com
  everything before 0.18.0 is desktop and doesn't show on phones and tablets. Render with
  `deviceScope: "all"` through `mbm_lf_render_options` to see everything everywhere.
- **Motion labels arrive on the workspace**, as a flat array of strings under `labels`. There is no
  labels endpoint — `GET /v1/labels` is a 404. `POST /v1/tasks` takes them as plain strings and
  returns them as objects (`[{"name":"In Dev"}]`), and an unknown one is a **404 `Unknown workspace
  label: …`**, exactly like an unknown status. The reader accepts strings or objects, since an
  object with a name is the obvious way for that to change.
- **`POST /v1/tasks` accepts a `status`**, which its documented field list omits. It is matched
  against the workspace's status names and a wrong one is a **404 `Unknown Status: …`** — loud,
  which is what we want, since the plugin stores the name and only finds out at drain time. An
  empty string is accepted and treated as absent. This is what the "New tasks start as" setting
  relies on; if Motion ever documents it, nothing changes.
