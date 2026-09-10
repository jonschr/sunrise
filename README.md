# Sunrise 0.1

**Sign in:** open [Sunrise Control](http://127.0.0.1:8787) and create your local account on the first visit. Subsequent visits use email/password or the saved browser session. WordPress’s Sunrise screen provides the connection and approval flow.

Clerk development sign-in is now available at [Sunrise account sign-in](http://127.0.0.1:8787/clerk), including Google. The WordPress connection belongs to the administrator who enrolled it. Other administrators see only **Connect this site**; they cannot inspect or operate that connection through Sunrise's admin actions, REST API, or CLI. Each administrator can enroll independently, including into a different account/network, without replacing another connection. Credentials, approval details, sequences and pauses are scoped to their local user ID in the non-autoloaded `sunrise_agents` option; the original single-owner option migrates without changing its secret or central IDs. Background check-ins run under the enrolled owner's capabilities without requiring that owner to be logged in.

**Connection policies:** component/core exceptions outrank category and site defaults. At equal specificity, the latest explicit change wins across connections using service-issued per-setting revisions; check-in order and unrelated edits do not change precedence. `inherit` withdraws that connection’s rule and reveals the remaining rules. An active administrator’s emergency pause blocks automatic updates until that administrator releases it. Losing an owner’s capabilities or revoking their connection removes its rules; other connections continue. With no valid connected policy, managed updates remain off. Overridden receipts report `local_policy_override` without another network’s identity.

**Hosted transport:** Sunrise defaults to `https://sunrise-staging.elod.in`; no configuration is needed for the testing service. Developers can override the exact trusted origin with `SUNRISE_CONTROL_URL` in `wp-config.php`. Hosted requests use WordPress’s safe HTTP API, TLS verification, no redirects, bounded responses and an honest Sunrise User-Agent. Credentials are bound to that origin; changing it requires fresh enrollment. The HTTP loopback exception remains local-only. Hosted staging is live at https://sunrise-staging.elod.in/clerk. Enrollment, outbound reporting, policy acknowledgment, independent administrators and credential revocation are verified through the deployed service. Hosting-provider compatibility is not yet certified.

**Local central-service pilot is working:** both Cove fixtures are enrolled and applying network plugin/theme rules with site exceptions, explicit component approvals, and durable background policy preparation. See [Sunrise Control README](</Users/jonschroeder/Local Sites/Sunrise Control/README.md>) for setup, verification, and current limits. The sections below also document the legacy, unenrolled WordPress controller.

**Development prototype:** the intended hosted, multi-network service is described in [ARCHITECTURE.md](ARCHITECTURE.md). Automatic-update policy is the primary feature, with scoped push/pull and error visibility planned alongside it. The native API documentation below describes the local prototype, not a completed hosted service or a production security review.

**Installation identity and recovery:** Sunrise creates a random installation ID, keeps a public ID anchor in `wp-content/sunrise-installation.php`, and stores URL hashes and an auth-salt-derived change detector in the database. The anchor survives database-only replacement. These identifiers are not credentials, and the local salt marker is never sent to Control. A URL, salt, or anchor mismatch pauses Sunrise policy enforcement, outbound credential use, and update jobs until explicitly reviewed. The pause persists even if the URL is changed back.

On the Sunrise screen, **Installation identity → Reconnect this site** clears copied local connections, policies, snapshots, and jobs, then starts fresh enrollment. A database replaced under an intact destination anchor retains the destination ID; a new clone receives a new ID. Confirming a move or salt change retains the installation ID but also requires fresh enrollment because the old credentials may be unreadable or copied. The existing Control browser session may be reused, but the new connection still needs approval. Remote enrollment records/history are not automatically merged or revoked by this local recovery. WordPress combines the authorized connections locally; Control never merges tenant records by URL or public installation ID.

WordPress needs write access to the anchor file. Missing/unreadable anchors pause Sunrise; restore the original file to keep the destination ID, or explicitly reconnect with a new ID. A tool copying both files and database can also copy the anchor. Identical copies retaining the same URLs and salts cannot be detected automatically. Uninstall removes the database identity but retains the public anchor, so reinstall requires review rather than silently taking over a copied database. Sunrise's future transfers must preserve the destination anchor and all destination-owned Sunrise state; content changes do not change installation identity.

Run `wp --user=<owner> eval-file /path/to/sunrise/tests/identity-check.php` on an enrolled disposable local fixture with `SUNRISE_TEST_SITE` and `DISABLE_WP_CRON` enabled. It restores the original identity, anchor, connection state, and cron schedule and never sends a credential to the remote service.

The first central-service specification is [CONTROL-CONTRACT.md](CONTROL-CONTRACT.md). Run its permission, policy, and lifecycle reference checks with `node --test tests/control-contract.test.mjs` from the Sunrise Control directory. These checks do not require a database and do not exercise deployed authentication or tenant isolation.

Install the same plugin on the target and controller. **Sunrise** manages this installation or a saved connection. No external service, JavaScript build, vendor SDK, custom tables, or custom scheduler is required. This first release supports **single-site WordPress 6.6+ and PHP 7.4+**; Multisite is explicitly disabled. The optional controller requires OpenSSL.

## Connect

1. Install and activate Sunrise on both WordPress sites.
2. On the target, create a dedicated **Application Password** under **Users → Profile**, using an administrator who can perform the updates you need.
3. In the controller's **Sunrise → Connect another site**, enter the target's WordPress installation URL, username, and application password.
4. Use the site selector to view inventory, change policies, or submit an update job.

Use the WordPress installation URL, including a subdirectory where applicable. Connections belong to the controller user who added them. The target credential is separate from the user's normal password: changing the normal password does not revoke it. Deleting/demoting the user, revoking the application password, or disabling application passwords prevents subsequent authorized operations. Queued jobs recheck the original user and application-password UUID before execution.

WordPress application passwords carry their user's capabilities across WordPress APIs; they are **not scoped solely to Sunrise**. Use a dedicated application password and secure the controller accordingly. No login passwords are collected and no users are silently created. Automatic plugin installation from another site is deferred until a distribution/onboarding channel exists.

Saved credentials use AES-256-GCM, with a key derived from this controller's WordPress auth salts and authenticated context identifying the user, URL, and username. Changing salts requires reconnecting. This protects a database-only copy, not a compromised controller server. “Forget connection” removes the saved secret here; revoke it on the target to invalidate it everywhere. Uninstall cleanup removes local Sunrise data; it cannot revoke credentials on other sites.

## Update policy

Activation leaves existing settings alone. Sunrise adds filters early, preserving later host/security filters, WordPress file-modification restrictions, automatic-updater disabling, and provider `disable_autoupdate` flags.

* `site`: `inherit`, `on`, or `off`. This is the default for items without an override. `on` includes installed inactive plugins/themes and stable major/minor core releases.
* `core`: `inherit`, `off`, `minor`, or `all`. Explicit Sunrise policies exclude development releases. `WP_AUTO_UPDATE_CORE=false` or `minor` is respected.
* `plugins` / `themes`: maps of installed IDs to `on`, `off`, or `inherit`. An explicit item choice overrides the site default. `inherit` removes the override, restoring the site/native decision.
* Exceptions affect **automatic updates only**. Explicit manual update jobs remain allowed by the user's capabilities.

Settings are Sunrise overrides, not replacements for native per-item options. Deactivating Sunrise removes its filters and restores native decisions. Other plugins and host services can enforce different policies; Sunrise reports selection and restrictions, not a guarantee that a host will run an update. Independent managed-host update services cannot be controlled through WordPress filters.

## REST API

Namespace: `/wp-json/sunrise/v1`. The alternative `index.php?rest_route=/sunrise/v1/...` works without pretty permalinks and is used by the bundled controller.

All routes require an authenticated user with `manage_options`. Writes additionally require the corresponding native update capabilities. REST authentication uses WordPress application passwords over HTTPS; native cookie authentication with an `X-WP-Nonce` is also supported. GET requests do not change update state. Responses include `Cache-Control: private, no-store` and vary on authorization/cookies.

| Method | Route | Purpose |
| --- | --- | --- |
| GET | `/inventory` | Cached inventory, offers, policy, user capabilities, cron and host restrictions |
| GET | `/policy` | Current Sunrise overrides |
| POST | `/policy` | Validate and merge a policy patch |
| POST | `/jobs` | Queue a refresh, a single explicit update, or a native automatic-update run |
| GET | `/jobs/{request_id}` | Inspect job status/result |
| POST | `/jobs/{request_id}/run` | Run the queued job directly if cron/loopbacks are unavailable |
| POST | `/jobs/{request_id}/acknowledge` | Acknowledge an interrupted job after inspecting the site |

Policy patch:

```json
{"site":"on","core":"minor","plugins":{"example/example.php":"off"}}
```

Job bodies (generate a new UUID v4 for each intentional operation):

```json
{"request_id":"312c5e09-31e0-4239-a5b4-47006c880eba","action":"refresh"}
```

```json
{"request_id":"64caebf5-e07b-4c12-93c8-dcdfe9459b61","action":"update","type":"plugin","id":"example/example.php","version":"2.1.0"}
```

```json
{"request_id":"d8225884-42d7-42bc-a55f-695e042fa78f","action":"auto_updates"}
```

For themes, use `type: theme` and the stylesheet directory as `id`. For core, use `type: core`, `id: wordpress`, and an offered version. Package URLs, arbitrary filesystem paths, downgrades, and arbitrary cron hooks cannot be submitted. Manual jobs verify the installed version after the native upgrader returns.

Inventory includes all installed ordinary plugins/themes, their active status (including active parent themes), versions, and cached offers. Must-use plugins and drop-ins are informational and not updated by Sunrise. Package URLs and license-bearing provider data are omitted. `update_available: null` means unknown; a missing premium update feed must not look like “up to date.” `last_check_attempt` is WordPress's attempt timestamp, **not proof of a successful provider response**. `auto_update_selected` reflects filters, not filesystem readiness or vendor compatibility guarantees. Premium updates rely on the vendor's installed updater and license.

## Jobs, retries, and cron

Enqueue returns **202** promptly. Native WP-Cron invokes the same worker as the authenticated `/run` endpoint. Existing WordPress automatic-update scheduling is unchanged; a custom shorter interval is deferred. No requests are made from normal front-end page rendering by Sunrise. Refresh jobs attempt provider checks no more than once every five minutes.

One Sunrise job runs per site, with the latest **20** results retained. Same user + same request ID + same body returns the original job during that retention window. A conflicting body or another active job receives **409**. Do not retry an old operation after its result has aged out. Poll pending jobs no faster than the returned **Retry-After: 15**. For host **429/503**, honor Retry-After and use exponential backoff with jitter. Never respond to a timeout by submitting a new request ID: query the original ID first. The bundled controller makes explicit requests without automatic retry loops, disables redirects, and remembers submitted IDs before sending them.

The direct `/run` request can outlast a proxy timeout; the job may still complete. The worker shares WordPress's automatic-updater lock for manual operations. These locks cannot coordinate independent host processes or every concurrent wp-admin/plugin updater; avoid running multiple maintenance tools simultaneously.

Fatal/exception outcomes are marked `interrupted` when possible. A process killed without shutdown can remain `running`. Neither is automatically retried; they block further Sunrise jobs until inspected and acknowledged. A `running` job can only be acknowledged after an hour and after its worker lock expires. The UI provides this recovery action. Deactivation cancels queued jobs. Results report error codes without raw upgrader messages or secret-bearing download URLs.

Sunrise uses native upgraders and their available rollback behavior. It does not implement backups, visual regression checks, or comprehensive application-health rollback. An installed version is not proof that every page/function still works. Core's native database-upgrade loopback is also subject to host restrictions.

## Local testing

Set this on each local WordPress installation:

```php
define( 'WP_ENVIRONMENT_TYPE', 'local' );
```

WordPress then supports application-password authentication over local HTTP. The controller only permits HTTP for `localhost`, `*.localhost`, `127.0.0.1`, or `::1`, and only when the controller environment is `local`. For a local self-signed HTTPS certificate, the connection form provides an explicit opt-in restricted to those hosts. Production targets always require HTTPS with certificate verification. No global TLS-verification filter is installed. Other local hostnames can use trusted HTTPS.

The development setup has two Cove sites, with the same source plugin symlinked into each:

* `sunrise-controller`: the controller UI, WordPress 6.9.7.
* `sunrise-target`: the disposable target, WordPress 6.6, PHP 8.1.

Use `cove url sunrise-controller` and `cove login sunrise-controller` to open it. HTTP/HTTPS ports are machine-specific. The target's site policy is deliberately off during testing so normal core auto-updates do not change the compatibility fixture.

On a **disposable** installation only, add `define( 'SUNRISE_TEST_SITE', true );`, activate Sunrise, and run:

```sh
wp --path=/path/to/disposable/wordpress eval-file /path/to/sunrise/tests/check.php
```

The check requires WordPress CLI and PHP ZipArchive, refuses non-local/non-test sites, blocks outbound HTTP during checks, and exercises permissions, policy precedence, private inventory, update-name collision, idempotency, a real native fixture-plugin update, compatibility rejection, revocation, and credential encryption. It cleans up its temporary plugin, user, jobs, and policy changes. Run with no other Sunrise jobs pending.

For real HTTP authentication, normal-password changes, revocation, and both worker entry points, temporarily set `DISABLE_WP_CRON=true` to keep traffic from racing the test, then run:

```sh
python3 tests/http-check.py --wp-root /path/to/disposable/wordpress \
  --url https://sunrise-target.localhost:8453 --insecure-local
```

The last flag is only needed for a self-signed local certificate. This check explicitly invokes `wp-cron.php`, which still works with traffic-driven cron disabled, and creates/deletes a dedicated test user. Keep the disposable site's core policy off during this test to preserve its WordPress version.

## Hosting references and validation still needed

* [SiteDistrict request identification rules](https://sitedistrict.com/service-and-crawler-request-rules): identify the controller with a genuine User-Agent and explanatory URL. Sunrise sends `SunriseController/0.1.0 (+CONTROLLER_HOME_URL)`. Publish information about the controller at that URL before hosted rollout. User-Agent is identification, not authentication.
* [SiteDistrict blocked-service guidance](https://sitedistrict.com/our-service-or-tool-is-being-blocked): use REST and inspect blocked-request logs. Do not impersonate ManageWP or WP Umbrella.
* [WP Umbrella connection troubleshooting](https://support.wp-umbrella.com/en/articles/79-how-to-resolve-we-can-t-communicate-with-your-wordpress-site-error-in-wp-umbrella): useful reference for REST and firewall diagnostics.
* [WordPress application-password documentation](https://developer.wordpress.org/advanced-administration/security/application-passwords/).
* [WP Engine update sources](https://wpengine.com/support/wordpress-update-source/): native update APIs preserve the host's configured update mirror/provider.

Flywheel, WP Engine, and SiteDistrict are **target environments, not yet certified integrations**. Validate on staging at each host: credential/Authorization handling, WAF/User-Agent behavior, cache bypass, 429 handling, loopbacks, writable files, core restrictions, premium updates, and native auto-update behavior. Firewalls can reject a request before any plugin code executes. Host restrictions are surfaced rather than bypassed.

The central-service agent pushes inventory outbound about every five minutes through WP-Cron; manual **Sync with Sunrise Control**, CLI sync, and authenticated wake requests remain available. Newly delivered policies are acknowledged in the same synchronization. The WordPress panel reads local state on load; opening Sunrise Control reads the central API’s stored network reports. Local/private sites can report without accepting inbound requests, but must be running with working cron. See [Sunrise Control README](</Users/jonschroeder/Local Sites/Sunrise Control/README.md>) for the local pilot configuration and its limitations.

## Central installation pilot

Central exact-version jobs now run through the outbound agent, independently of automatic-update policy. The service first advertises support, then receives offered versions and can queue a specific installed component/version. WordPress obtains a scoped start authorization, persists its execution record, validates local access and the current offer, and uses its native updater. A lost result is retried without repeating installation.

Interrupted work retains `sunrise_remote_job_fence`, pauses managed automatic updates and blocks other Sunrise installations until the service records explicit reconciliation. The process-held `wp-content/.sunrise-execution.php` lock supplements WordPress's expiring locks; hosts without usable file locking refuse execution. This file and all `sunrise_` state are installation-local and must not be pushed/pulled as content. Identity recovery also acquires this lock before clearing copied state.

The real Cove/API check installed only a disposable synthetic plugin on WordPress 6.6/PHP 8.1 and verified duplicate suppression and cleanup. No live plugin/core/theme updates were installed. Run `tests/agent-job-recovery-check.php` with WP-CLI on an enrolled disposable local site for offline recovery checks. The agent supports plugin/theme/core job types through the existing native updater; actual central theme/core replacements and target-host behavior still need certification. Bulk jobs and the Update now UI remain separate work.


## Network navigation and error summaries

Sunrise has Network and Migrations pages at the bottom of the WordPress administrator menu. Each page exposes only the current administrator's connection. Open Sunrise Control selects that connection's network using a navigation hint; Control independently checks the human's membership. No site credential or human token travels in this URL. An unauthorized network hint never silently selects another network. Managed pages avoid the legacy peer/application-password controls. Migration execution remains unavailable until previews and scoped authorization are implemented.

Fatal summaries are **on by default for connected sites**, with an explicit off switch. Capture records only an error category, relative code path (or `outside/unknown`), line, timestamps and sampled count. It never records raw messages, traces, SQL, request bodies or absolute paths. Collection is at most once per ten seconds per installation, with 100 local groups retained for three days from first observation. Counts are samples, not total failures. Each enabled administrator connection uploads these shared installation summaries under its own site credential after normal synchronization. An unchanged acknowledged batch makes no extra HTTP request; lost acknowledgments retry cumulative counts without duplication. Disabling one connection stops its uploads; another enabled connection may still collect/report. Previously received data remains until server retention expires.

The service advertises `error_reports: true` in check-in responses. `POST /v1/agent/errors` accepts up to 100 strict location summaries and derives scope exclusively from the site credential. It retains at most 100 groups per connection, with three-day expiry; hourly bounded cleanup removes up to 1,000 expired groups per run. Maintainers/administrators use `GET N/errors` (100-row composite cursor) and `POST N/errors/{site}/{id}/resolve` with the displayed `revision`. Stale resolutions fail with 412. Increasing counts reopen resolved groups; duplicate delivery leaves them resolved. Viewers and other networks cannot read or resolve them. Resolution marks operator review, not a verified repair.

Collection preserves the native WordPress error response through its passive `wp_php_error_message` filter and a shutdown fallback. Failures before plugin loading, hard kills, memory exhaustion, or custom handlers that exit before either callback may not be observable. A missing report is not proof of health. No production debugging setting is enabled, and no HTTP runs in the error handler. Error delivery waits for a successful ordinary sync; local/unreachable sites report when they next run WordPress cron or a manual sync.

Verification: plugin `tests/errors-check.php`, `tests/agent-check.php` and `tests/identity-check.php` run sequentially on enrolled disposable Cove fixtures. Service `node tests/cove-error-shutdown-check.mjs` deliberately triggers a fatal on the pinned disposable target, checks capture after process exit, then restores its options. Do not run `error-fatal-fixture.php` alone: the parent script owns cleanup. `node tests/browser-plugin-check.mjs` checks both WordPress pages headlessly; `node --experimental-strip-types browser-network-check.ts` checks trusted network selection, resolution/recurrence, policies and viewer restrictions against real HTTP/PostgreSQL.


## Read-only transfer groundwork

The WordPress Migrations page can inspect this installation without making a remote request. `GET /sunrise/v1/transfers/inventory?scope=posts|media|options|plugins|themes|tables&after=...` requires the current administrator's valid connection and pages at 50 records. Post/media records include a length-delimited digest of selected raw post fields (up to 2 MiB); metadata, terms, comments, linked records and file bytes are excluded. Larger records return no fingerprint. Component and table listings are explicitly metadata-only: they do not establish changed file bytes or changed rows. Tables remain blocked until a table-specific handler and approval exist. Pagination is live, not a transactionally frozen snapshot.

`POST /sunrise/v1/transfers/snapshot` with `{"names":["blogname","blogdescription"]}` exports only selected native settings. The seven-name allowlist is blogname, blogdescription, date_format, time_format, start_of_week, timezone_string and gmt_offset. Each value is at most 8 KiB. Sunrise state, home/siteurl, activation, cron, roles, credentials and unknown plugin options are excluded. `POST /sunrise/v1/transfers/preview` accepts that snapshot on the destination, validates its value hashes, rewrites known source URLs, and returns exact before/after values and destination fingerprints. It does not write data or grant execution. Source provenance and fresh independent source/destination authorization must be supplied by the upcoming central transfer workflow; a submitted site ID or hash is never proof of authority. Settings-specific validation and backup/recovery still gate execution.

The pure `transfer_rewrite` helper supports explicit text, JSON and serialized formats. It caps each value at 2 MiB, serialization traversal at 10,000 tokens and depth 32, and mapping count at eight. It uses a small scalar/array wire parser, never PHP `unserialize` on transferred input. Objects, references, unsupported tags, duplicate serialized keys, trailing bytes, wrong lengths, runtime integer overflow and URL-bearing keys are rejected. Nested serialization retains byte lengths. JSON string rewriting preserves numeric bytes and surrounding structure. URL replacements prefer the longest known base, respect path/host boundaries and do not cascade into another mapping. JSON slash escapes are handled; arbitrary URL encodings, opaque signed/checksummed payloads, embedded binary formats and unknown plugin reference semantics require their own handlers.

Checks: `php tests/transfer-rewrite-check.php` passed on PHP 8.1, 8.4 and 8.5. `wp --user=1 eval-file tests/transfer-inventory-check.php` passed on the disposable WordPress 6.6 and 6.9.7 fixtures, including paging, changed content, protected settings, tampering, unchanged destination identity and stale preview fingerprints. The service repository's `python3 tests/cove-transfer-preview-check.py` exercises actual authenticated source REST → destination REST previews and deletes its temporary application passwords. Headless Migrations inspection also passed. No transfer execution is available yet.


## Central transfer preparation and endpoint approval

Network administrators can now prepare native-setting transfers from Control. `POST N/transfers` takes `{source_site_id,destination_site_id,names}` and a UUID `Idempotency-Key`; `GET N/transfers` pages 50 records with its UUID `after` cursor, and `GET N/transfers/{id}` reads one request. The selection is immutable, both connections must be in this account/network, and equal installation URLs are rejected. At most five pending previews per endpoint may exist. Preparation expires after 72 hours. This first settings scope uses explicit per-operation administrator approval; it does not turn update permissions or site enrollment into standing content/code/table transfer grants.

The agent negotiates `transfer_previews` and only makes extra requests when check-in reports `transfer_work_available` or an exact report remains unacknowledged. `POST /v1/agent/transfers/claim` returns one assigned source-snapshot or destination-preview task. Results go to `/v1/agent/transfers/{id}/source|destination`. Source capture runs first; destination preparation receives only that selected source snapshot. Each side may report only for its own connection/generation. PHP persists its exact report before HTTP and reuses it after transport failure, even if local content changed. Native settings/identity remain unchanged. Unsupported preparation reports a bounded failure code instead of retrying arbitrary work. A normal idle sync adds no transfer HTTP request.

Control renders the exact destination before/after values using text nodes. `POST N/transfers/{id}/approve` takes `{side,revision,plan_hash}`. Source and destination approvals are separate human actions, bound to the same immutable canonical plan hash and a shared one-hour expiry (to accommodate site check-in delays). They may come from the same administrator, but neither can come from a WordPress site credential. `POST N/transfers/{id}/cancel` takes `{revision}`. Stale edits fail with 412; changed hashes or expired previews cannot be approved. Site generation changes/disconnection and requester/approver access removal cancel the request; reactivation does not revive it. For the seven supported native settings, Control 0.1.5 enables a destination-only, consumed 60-second start lease after both approvals. The WordPress runner validates current values, commits selected changes with a local backup journal, and reports the retained outcome. Unknown outcomes stay fenced for inspection; retries never repeat a write. Migration 021 invalidates approvals granted before execution was enabled. Content, media, plugin/theme files and arbitrary table execution remain in development.

Migration 013 adds tenant/site RLS, immutable endpoint/selection columns, database guards preventing agents from approving or retargeting requests, and revocation triggers. API projections return only an agent's own assigned task; database row access is limited to an explicitly requested pair. Thirty-day cleanup removes at most 500 expired preview records per hourly run. A future executor must preserve active/uncertain execution records when extending this cleanup. No file/object storage or new cloud permissions are required for these small settings snapshots.

Checks: `npm test` includes scoped preparation/approval, duplicate delivery, foreign-site denial, direct SQL mutation guards, expiry, revocation, queue limits and failed preparation. `tests/agent-transfer-check.php` checks shared locking, persistent exact retries, cancellation and older-service negotiation. `node --experimental-strip-types tests/cove-transfer-work-check.mjs` exercises human HTTP request → both actual outbound Cove agents → exact approvals and verifies unchanged settings/identity. `node --experimental-strip-types browser-transfers-check.ts` exercises the complete review UI, escaping, stale approvals, cancellation and viewer isolation. All temporary sessions/request fixtures are removed; transport sequences are never rewound.

## Transactional native-settings recovery

The internal `transfer_options_commit` primitive supports the seven native settings above. It requires an exact plan hash, a short-lived executor fence, unchanged destination values, valid connection ownership, and the same installation identity. It shares process/update locks with the update runner. Remote execution is still disabled; only disposable fixtures currently create execution fences.

A separate native mysqli connection avoids WordPress reconnect/retry behavior and nested application transactions. All selected rows are locked before writing; settings and a non-autoloaded JSON recovery journal commit together. The first implementation requires the standard `wpdb` driver, mysqlnd, and an InnoDB options table; database drop-ins are rejected. Native sanitization must preserve the reviewed bytes exactly. This copies stored values atomically and invalidates option caches; it does not run `update_option` change hooks or promise third-party hook side effects. Unknown plugin settings remain excluded.

Migrations shows only this administrator's journals for this installation. Its nonce-protected restore action binds the exact reviewed journal hash and requires every selected setting still to equal its transferred value. Newer edits block the entire restore. Replays return the journal outcome and repair caches without repeating writes; an original request cannot reapply a restored transfer. Ambiguous commit outcomes retain the journal/fence for inspection. Reconnection on the same physical installation can retain recovery, but changed installation identity or URLs block copied records. Up to 50 journals per owner are retained; no automatic deletion of replay evidence exists yet. Removing the plugin does not erase these recovery records.

Checks: `wp --user=1 eval-file tests/transfer-options-check.php` on both disposable WordPress versions covers native validation, lock exclusion, row preconditions, forced second-write SQL failure/complete rollback, journal atomicity, stale-cache replay, conditional restore, administrator isolation and copied journals. Service `node tests/browser-transfer-recovery-check.mjs` performs an actual settings transaction, rejects a forged request without a nonce, submits the native owner restore form, and removes its fixture.

The durable native-settings runner is implemented behind the service's `transfer_execution` negotiation. Its start/report boundaries are persisted, and it shares the process lock with updates and local restore. A lost start response retains the original token and fence; a lost result retries only the report. A recovered executing record uses the exact local journal as write evidence and never automatically repeats writes. Missing evidence remains uncertain until an operator confirms closure after the worker stops. Recovery lists page ten local journals at a time and execution recovery reads its exact record. Tests `tests/agent-transfer-execution-check.php` pass on the two disposable WordPress versions with real transactions and intercepted HTTP. The service's HTTP execution routes remain disabled until the full outbound two-site execution check passes.

Central network batches reuse the same exact-version native update runner. After one job finishes and the service reports more explicit work, Sunrise schedules the owner's next WordPress check-in in roughly 60–90 seconds. Routine reporting runs every five minutes. This does not enable disabled cron or bypass a paused/uncertain installation; cron-disabled local sites need manual syncs. `tests/agent-job-recovery-check.php` verifies continuation scheduling and preserves the fixture's original cron/state.

## Plugin releases

Install **sunrise.zip** from [GitHub Releases](https://github.com/jonschr/sunrise/releases). For the SiteDistrict pilot, activate Sunrise and use **Connect this site** as the owning administrator. The staging Control URL is built in; no wp-config.php changes are required. Local settings and connected-site credentials are never bundled in the ZIP.

Sunrise bundles the same Plugin Update Checker library used by legacy RentFetch, updated to upstream v5.7. It checks published releases approximately every twelve hours and provides the native Plugins-page **Check for updates** link. Only a published, non-draft, non-prerelease GitHub release with a `sunrise.zip` asset is eligible; there is no fallback to main, bare tags, or source archives. Sites need no GitHub credentials. The separate `Update URI` and early cache filter prevent the WordPress.org name collision while allowing the GitHub offer to appear.

To release: update `Version` and `VERSION` in sunrise.php, `Stable tag` and the short changelog in readme.txt, and add the next numbered section to changes.md. Commit those changes, then push main and a matching `vX.Y.Z` tag. The GitHub workflow checks PHP syntax, validates matching versions, packages only committed runtime files, and publishes the ZIP with that version's changes.md notes. `python3 scripts/package.py v0.2.1` builds the current installable package locally from a clean tree. Build output is ignored by git. Do not move an already published tag; increment the version for a replacement.


### Sunrise self-updates

From 0.2.3, packaged Sunrise installations select their own published GitHub releases for WordPress's native automatic updater even when the network's plugin policy is off. Other plugins are unaffected. Host restrictions, disabled offers, filesystem checks and cron availability still apply. Symlinked installations and Git checkouts are excluded to protect development work. Existing installations need the 0.2.3 update once to gain this behavior.


### File migration pilot (0.2.8)

The WordPress workbench supports all/active/include/exclude plugin and theme files and all/date/last-success media files. Source and destination approvals bind the exact manifest. Source ZIPs and destination staging/recovery copies stay outside public document roots; transport uses 256 KiB chunks through the private Control relay. Hosts must provide ZipArchive, private writable temporary storage and the required native file permissions. Active plugin/theme state, attachment records, licenses and database content are not changed by a file-only transfer.

The initial transfer ceiling is 5,000 files, 10,000 walked entries, 512 MiB. Media checkpoints advance only after a confirmed successful transfer for the same source/destination pair. Files are added/replaced, not mirrored/deleted. Original destination files can be restored locally while hashes still match; ambiguous interrupted writes remain fenced. Completed private copies expire after seven days during subsequent plugin activity. This is not full database or posts migration support.

Checks: `wp --user=1 eval-file tests/transfer-files-check.php`, `tests/agent-transfer-execution-check.php`, and Control's `node --experimental-strip-types tests/cove-file-transfer-check.mjs` on disposable Cove fixtures. The workbench browser check covers native login, real source inventory, saved selections, exact approval handoff and mobile layout. No live-host compatibility certification is implied.
