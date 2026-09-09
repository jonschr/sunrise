# Sunrise 0.1

**Sign in:** open [Sunrise Control](http://127.0.0.1:8787) and create your local account on the first visit. Subsequent visits use email/password or the saved browser session. WordPress’s Sunrise screen provides the connection and approval flow.

Clerk development sign-in is now available at [Sunrise account sign-in](http://127.0.0.1:8787/clerk), including Google. The WordPress connection belongs to the administrator who enrolled it. Other administrators see only **Connect this site**; they cannot inspect or operate that connection through Sunrise's admin actions, REST API, or CLI. Each administrator can enroll independently, including into a different account/network, without replacing another connection. Credentials, approval details, sequences and pauses are scoped to their local user ID in the non-autoloaded `sunrise_agents` option; the original single-owner option migrates without changing its secret or central IDs. Background check-ins run under the enrolled owner's capabilities without requiring that owner to be logged in.

**Connection policies:** component/core exceptions outrank category and site defaults. At equal specificity, the latest explicit change wins across connections using service-issued per-setting revisions; check-in order and unrelated edits do not change precedence. `inherit` withdraws that connection’s rule and reveals the remaining rules. An active administrator’s emergency pause blocks automatic updates until that administrator releases it. Losing an owner’s capabilities or revoking their connection removes its rules; other connections continue. With no valid connected policy, managed updates remain off. Overridden receipts report `local_policy_override` without another network’s identity.

**Hosted transport:** set `SUNRISE_CONTROL_URL` to the exact trusted HTTPS Control origin in `wp-config.php`. Hosted requests use WordPress’s safe HTTP API, TLS verification, no redirects, bounded responses and an honest Sunrise User-Agent. Credentials are bound to that origin; changing it requires fresh enrollment. The HTTP loopback exception remains local-only. Hosted staging is live at https://sunrise-staging.elod.in/clerk. Enrollment, outbound reporting, policy acknowledgment, independent administrators and credential revocation are verified through the deployed service. Hosting-provider compatibility is not yet certified.

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

The central-service agent pushes inventory outbound about every twelve hours through WP-Cron; manual **Sync with Sunrise Control**, CLI sync, and authenticated wake requests remain available. Newly delivered policies are acknowledged in the same synchronization. The WordPress panel reads local state on load; opening Sunrise Control reads the central API’s stored network reports. Local/private sites can report without accepting inbound requests, but must be running with working cron. See [Sunrise Control README](</Users/jonschroeder/Local Sites/Sunrise Control/README.md>) for the local pilot configuration and its limitations.

## Central installation pilot

Central exact-version jobs now run through the outbound agent, independently of automatic-update policy. The service first advertises support, then receives offered versions and can queue a specific installed component/version. WordPress obtains a scoped start authorization, persists its execution record, validates local access and the current offer, and uses its native updater. A lost result is retried without repeating installation.

Interrupted work retains `sunrise_remote_job_fence`, pauses managed automatic updates and blocks other Sunrise installations until the service records explicit reconciliation. The process-held `wp-content/.sunrise-execution.php` lock supplements WordPress's expiring locks; hosts without usable file locking refuse execution. This file and all `sunrise_` state are installation-local and must not be pushed/pulled as content. Identity recovery also acquires this lock before clearing copied state.

The real Cove/API check installed only a disposable synthetic plugin on WordPress 6.6/PHP 8.1 and verified duplicate suppression and cleanup. No live plugin/core/theme updates were installed. Run `tests/agent-job-recovery-check.php` with WP-CLI on an enrolled disposable local site for offline recovery checks. The agent supports plugin/theme/core job types through the existing native updater; actual central theme/core replacements and target-host behavior still need certification. Bulk jobs and the Update now UI remain separate work.
