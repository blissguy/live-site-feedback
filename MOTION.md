# Turning feedback into Motion tasks

A plan, not an implementation. Branch: `feat/motion-tasks`.

**What it should do:** when a client leaves a comment on the site, a task appears in Motion in
the right project, assigned to whoever owns that page. Replies land as comments on that task.
Resolving the comment closes the task.

The module stays completely inert until a Motion key is saved — no menu item, no scheduled work,
no queue. Someone using the plugin without Motion should not carry any of it.

---

## 1. Verified API facts

Checked against Motion's documentation, 2026-08-02. Base `https://api.usemotion.com/v1`,
auth via an **`X-API-Key`** header (not Bearer).

| Endpoint | What we use it for |
| --- | --- |
| `GET /v1/workspaces` | Settings picker. Returns `id`, `name`, `type`, `labels`, and `statuses[]` — each with `name`, `isDefaultStatus`, **`isResolvedStatus`**. Paginated. |
| `GET /v1/projects` / `GET /v1/projects/{id}` | Settings picker. `id`, `name`, `workspaceId`, `status`. |
| `GET /v1/users?workspaceId=` | Maps a WordPress author to a Motion user. Returns `id`, **`email`**, `name`. Paginated via `cursor`. |
| `POST /v1/tasks` | Create the task. Requires `name` + `workspaceId`; optional `projectId`, `assigneeId`, `description` (GitHub Flavored Markdown), `dueDate`, `priority`, `labels[]`, `duration`, `autoScheduled`. |
| `POST /v1/comments` | Append a reply. Requires `taskId` + `content` (GFM). |
| `PATCH /v1/tasks/{id}` | Move a task to a resolved status. |

Two of these are load-bearing:

- **`GET /v1/users` returns email.** This is the whole basis of author-based assignment. Without
  it we would be asking someone to hand-map every user.
- **Workspace statuses carry `isResolvedStatus`.** So "mark it done" means finding that status
  in *that* workspace, not hardcoding a name like "Completed" that breaks on the next client.

### Rate limit

**120 requests per minute** (Teams plan, confirmed by the account owner). Individual plans get
12, so anything built here must read the limit from configuration rather than assuming.

120/min is two per second — comfortable, but not a reason to skip the queue. See §5.

### What Motion cannot do

- **No outbound webhooks.** Nothing in Motion can notify WordPress. Completing a task there
  cannot close the pin in MarkUp without polling.
- **No subtasks.** `POST /v1/tasks` has no `parentTaskId` or any parent/child field. The
  hierarchy has to come from projects, which is what §3 does.

---

## 2. What triggers it

**The MarkUp webhook, and only the webhook.**

This is worth stating because the obvious-looking shortcut does not work. The browser cannot
tell us when a thread is created: the SDK's `pin:placed` event fires *before* the comment is
submitted, and there is **no thread-created event** in its event map at all. Any client-side
trigger would be guesswork.

The receiver already exists (`MBM_LF_Webhooks`) and is already subscribed to the events we need:
`comment_created`, `comment_reply_created`, `comment_resolved`, `comment_unresolved`.

The payload is rich enough to build a task with **no extra API call** — worth protecting, because
every avoided request is budget kept:

```
data.comment.firstMessage.text     the comment itself
data.comment.threadNumber          the pin number
data.comment.appUrl                deep link straight to the pin
data.comment.resolved
data.comment.author { name, email }
data.comment.location.canonicalUrl which page it was left on
data.markup { id, name, webpageUrl }
```

**Deliveries are unsigned** (see `UPSTREAM.md` §5). The receiver already treats them as
untrusted. That has a direct consequence here: a delivery must never cause an outbound write on
its own. It enqueues; the queue worker decides. A forged delivery then costs one wasted job, not
a bogus task in a client's Motion board.

---

## 3. The mapping

| MarkUp | Motion | Why |
| --- | --- | --- |
| This WordPress site | **Workspace + project**, set once in settings | Matches how the team already works: a project per brand |
| A thread (one pin) | **Task** in that project | One pin is one unit of work |
| Replies within that thread | **Comments** on that task | A reply is discussion, not separate work |
| Comment resolved | Task moved to the workspace's `isResolvedStatus` | Uses whatever that workspace calls done |

Motion has no subtasks, so the parent/child shape comes from the project rather than from task
nesting. That is arguably the better fit anyway: a reply saying "actually make it blue" is not a
task, it is context on one.

### Task content

**Title** — has to be scannable in a Motion list, so lead with the page:

```
Home — "the logo is too small on mobile"
```

Page name, then the comment truncated to roughly 60 characters. The thread id does **not** go in
the title; we store the mapping ourselves (§6), so the title is free to be readable.

**Description** (GFM):

```markdown
> the logo is too small on mobile

Left by **Ama Boateng** on [Home](https://example.com/) · pin #7

[Open the comment in MarkUp.io](https://app.markup.io/markup/…#thread/…)
```

The deep link is the important part — one click from the task to the exact pin.

---

## 4. Assignment

In order, first match wins:

1. **A per-page override**, if set in the editor panel.
2. **The page's WordPress author, matched to a Motion user by email.** The team uses the same
   addresses in both systems, so this needs no configuration and stays correct as authorship
   changes.
3. **The default assignee** from settings, when the author has no Motion account.

The email map is cached (§5), so assignment costs no requests in the normal case.

Comparison is lowercased and trimmed on both sides. Where a page has no author — an archive, or
the site-wide list — go straight to the default.

---

## 5. Queue and rate limiting

**Nothing calls Motion inside a web request.** Not the webhook, not a page load, not saving a
post. Every write is a job.

Three reasons, none of which 120/min removes: a delivery arriving during a burst must not block;
a Motion outage must not fail a request that has nothing to do with Motion; and retries need
somewhere to live.

### Storage: a small table

The first table this plugin would add, and justified. A queue needs ordered reads, concurrent
writes, per-job status and retry counts. An option holding an array loses jobs the moment two
deliveries land together, because option writes are not atomic.

```
{prefix}mbm_lf_motion_queue
  id            bigint  auto increment
  thread_id     varchar index      MarkUp thread
  action        varchar            create_task | add_comment | resolve_task
  payload       longtext           JSON
  attempts      tinyint
  available_at  datetime index     for backoff
  created_at    datetime
```

Schema version in an option, `dbDelta` on upgrade.

### Draining

A cron event every minute takes jobs whose `available_at` has passed, up to a **token bucket**
sized from the configured limit with headroom — say 80% of it, so the plugin never becomes the
reason a colleague's own Motion script starts failing.

At 120/min that is roughly 96 calls a minute available; a client leaving 15 pins drains in one
pass. At 12/min it would take two.

**WP-Cron only runs when someone visits the site.** On a quiet staging site jobs can sit. Two
mitigations: opportunistically drain a few jobs on admin page loads for users who can edit, and
say plainly in the settings screen that a real system cron makes it prompt.

### Failure handling

| Response | What we do |
| --- | --- |
| 429 | Leave the job, push `available_at` out, halve the bucket for the next pass |
| 5xx / network | Retry with backoff: 1, 5, 20, 60 minutes, then park it |
| 401 / 403 | Stop the queue entirely and surface it in settings — a bad key will not fix itself, and retrying just burns budget |
| 404 on a comment or status change | The task was deleted in Motion. Drop the mapping and stop chasing it |
| Anything else 4xx | Park the job with the error visible; do not retry a request the server has rejected on its merits |

Parked jobs need to be visible somewhere, or they are just silent data loss. A short list on the
settings screen, with a retry button.

---

## 6. Not creating duplicates

Store the mapping both ways:

```
markup_thread_id  →  motion_task_id
```

Checked before creating anything. Webhook retries, a double delivery, or a queue replay then all
become no-ops rather than three tasks for one pin.

Motion's own `GET /v1/tasks?name=` filter was considered as a dedupe check and rejected: it costs
a request, matches on a string a human might edit, and we already hold the authoritative answer.

Jobs are also keyed so that two `create_task` jobs for the same thread collapse into one.

---

## 7. Settings

Under the existing screen, shown only once a Motion key is saved.

| Setting | Notes |
| --- | --- |
| Motion API key | Encrypted at rest like the others; `MBM_LF_MOTION_API_KEY` constant overrides |
| Workspace | Dropdown from `GET /v1/workspaces`, cached |
| Project | Dropdown filtered to the chosen workspace, cached |
| Default assignee | Dropdown from `GET /v1/users`, used when the author has no Motion account |
| Task priority | Optional; ASAP / HIGH / MEDIUM / LOW |
| Labels | Optional; applied to every task so client work is filterable in Motion |
| Send resolved status | On by default |

Plus a **Test connection** button, mirroring the MarkUp one, and a queue readout: pending,
parked, when it last ran.

Caches: workspaces and projects for a day, the user email map for a day, all refreshable by hand.

---

## 8. Where this lives

**Inside this plugin, as a module that does nothing until configured.** This reverses my earlier
suggestion of a separate plugin, for three reasons: the webhook receiver is already here, the
page-to-author relationship is already here, and the settings screen is already here. A separate
plugin would have to duplicate all three and then keep them in step.

The guard is that every Motion hook registers only when a key exists, so a site without Motion
gets no table, no cron event and no code path.

---

## 9. Honest limits

- **One-way.** Completing a task in Motion cannot close the MarkUp pin — Motion has no outbound
  webhooks. Doing it would mean polling `GET /v1/tasks` on a schedule and spending budget to
  discover that mostly nothing changed. Worth living without at first and seeing whether it is
  actually missed.
- **Deleting a comment in MarkUp** does not delete the task. Deliberate: someone may already be
  working on it. It could add a comment saying the feedback was withdrawn.
- **Editing a comment** does not rewrite the task title. Also deliberate — the task may have been
  renamed by a human, and overwriting that would be rude.

---

## 10. Phases

**M0 — Connect.** Settings, encrypted key, connection test, workspace and project pickers, user
email map with caching. Nothing is created yet. Ends with: the settings screen can prove it is
talking to the right Motion workspace.

**M1 — Threads become tasks.** The queue table, the cron worker, the token bucket, the
thread→task mapping, and `create_task`. The first genuinely useful milestone.

**M2 — Replies become comments.** `add_comment`, keyed off the existing mapping.

**M3 — Resolving closes the task.** Look up `isResolvedStatus` for the workspace and `PATCH`.

**M4 — Refinements.** Per-page assignee override, labels, priority, the parked-jobs list with
retry.

Each is shippable on its own.

---

## 11. To confirm before building

1. **Does `GET /v1/projects` accept `workspaceId` as a filter?** Only the single-project GET was
   documented clearly. If not, we list and filter client-side, cached — no real difference.
2. **What does Motion return on 429?** Undocumented: no status code, headers or backoff guidance
   given. Needs one deliberate test so the queue reacts correctly rather than guessing.
3. **Does `PATCH /v1/tasks/{id}` accept a status by name or id?** Determines whether the resolved
   status is resolved once at setup or looked up per call.
