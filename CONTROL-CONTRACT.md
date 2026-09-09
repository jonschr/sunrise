# Central control contract, v1

Status: target implementation specification. The local account-onboarding/enrollment/policy/inventory slice and durable policy work queue are now implemented; [Sunrise Control README](</Users/jonschroeder/Local Sites/Sunrise Control/README.md>) explicitly lists implemented endpoints and remaining differences. `~/Local Sites/Sunrise Control/contracts/control-v1.mjs` is an executable reference for permissions, policy resolution, and legal job transitions; it is not authentication middleware. Run its checks with `node --test tests/control-contract.test.mjs` from the Sunrise Control directory.

## 1. Boundary and identities

The central API lives at `/v1` on the Sunrise service origin, separate from the existing WordPress `/sunrise/v1` API. WordPress never connects directly to PostgreSQL. Production requires verified HTTPS. Local development uses explicit local configuration, an isolated database, and loopback/trusted local TLS; local authentication cannot be enabled on the hosted deployment.

All resource IDs are UUIDs, and timestamps are UTC RFC 3339. Document revisions, generations, and report sequences are positive integers no larger than 2^53-1. Cross-connection setting revisions are PostgreSQL sequence values encoded as decimal strings. A person has a stable internal ID mapped to a unique identity-provider `(issuer, subject)`, never matched by email alone. A site has a server-issued ID and an enrollment generation; its URL and local administrator ID do not establish global identity. Each administrator connection belongs to exactly one network; one WordPress installation can hold multiple independently approved connections. Moving it to another network or changing its installation URL requires re-enrollment and revokes its old credentials and unstarted work.

There are two external principal kinds: human and site. Authenticate first, load current grants/revocation from server storage, then authorize the resource. Account/network IDs in a request are selectors, not evidence of permission. A site principal is always bound by its credential to one account, network, site, and enrollment generation. Reject mixed human/site credentials. Workers use an internal runtime identity with explicit job scope, never a member site's identity to administer another site.

## 2. Membership and permissions

Account roles are `owner`, `billing`, and `member`. Owners create networks, manage account membership, and assign network administrators; billing members see billing only. At least one active owner must remain, enforced transactionally. Owner power includes assigning themselves network privileges, but that change must be explicit and audited; account roles do not silently grant site operations.

Network roles are expanded into these server-owned grants:

| Network role | Grants |
| --- | --- |
| viewer | `inventory.read`, `policy.read`, `jobs.read` |
| maintainer | viewer plus `policy.write`, `jobs.create`, `jobs.cancel`, `jobs.reconcile`, `errors.read` |
| administrator | maintainer plus `sites.manage`, `members.manage` |

Network administrators may manage viewer/maintainer memberships; only account owners assign/remove network administrators. Revoking account membership also invalidates every network grant in that account. Authorization uses current membership, not long-lived role claims in a login token. A person can hold different roles in different networks/accounts.

Transfer permissions are additive, explicit grants, scoped to a site: `transfer.export`, `transfer.import_content`, `transfer.import_code`, and `transfer.import_tables`. No role automatically receives them. Only an account owner can issue/revoke them on the trusted origin with fresh authentication. These names reserve the future authorization boundary; no transfer execution endpoint ships in this milestone. A transfer needs export permission at its source and every relevant import permission at its destination, plus fresh approval bound to the exact manifest. Same-network membership by itself is never sufficient.

| Site credential operation | Allowed scope |
| --- | --- |
| Publish inventory, local overrides, errors and check-in | Its own site only |
| Fetch resolved policy, obtain job, send acknowledgment/result | Its own site only |
| Revoke its enrollment | Its own site only |
| List network inventory, edit desired policy, create jobs, invite members, approve transfer | Never |

Local WordPress administrators retain ordinary local controls; their login does not authenticate a human to the central service. Human sessions stay on the Sunrise origin, using secure HttpOnly cookies and CSRF/origin checks for mutations. Opening Sunrise from wp-admin must not deliver those credentials to WordPress PHP, JavaScript, query strings, or postMessage handlers.

## 3. Enrollment and revocation

1. A local administrator explicitly starts enrollment with a WordPress nonce and appropriate capabilities. The agent generates a random 256-bit secret, stores it locally without autoload, and sends only its SHA-256 digest plus installation metadata to `POST /v1/enrollments`. The secret is never placed in the approval URL. Initial enrollment requests are untrusted, rate-limited, and create no network membership.
2. The service returns a pending enrollment ID, a one-time approval URL on its own origin, and a ten-minute expiry. The plugin displays a random verification phrase also shown by the service. On the trusted origin the human authenticates, compares the phrase/URL, and selects a network where they have `sites.manage`. The service checks membership at approval, not just when the page opened. No callback supplied by the site can receive a human session.
3. Approval atomically creates the site and credential record bound to that network and marks the enrollment approved. The agent polls `POST /v1/enrollments/{id}/exchange` with the secret as a bearer credential. It receives the assigned IDs/generation; the same secret becomes its site credential. Repeating the exchange returns the same assignment while active, never another site. This avoids storing a recoverable secret on the server or losing it if a response times out. Pending/expired/revoked enrollment credentials cannot call agent routes.
4. The honest agent binds enrollment locally to the approving WordPress user ID and validates that user's current capabilities before privileged actions. Password changes are irrelevant to this separate credential. Deleted/demoted users disable privileged execution locally and report suspension when possible. Central approval proves operator consent, not the trustworthiness of PHP running on the site.

Use cryptographically random secrets, constant-time digest comparisons, no token logging, and separate credential parsing for enrollment and agent routes. Store only credential digests centrally. No automatically created WordPress user or retained WordPress login password is needed.

`DELETE /v1/accounts/{a}/networks/{n}/sites/{s}/enrollment` revokes the credential generation immediately and cancels unstarted jobs. `DELETE /v1/agent/enrollment` permits self-disconnection. Lost credentials require the same fresh enrollment flow; self-service credential rotation is deferred for the first milestone. Preserve an audit tombstone and retain the former site record as disconnected rather than reusing its ID for an unrelated installation.

Revocation prevents new central authorization. It cannot recall a write already running or instantly notify an offline site. Previously applied automatic-update policy persists locally during an ordinary outage; explicit local disconnect removes the managed policy and restores native decisions after displaying the consequence. A remotely revoked offline site may keep its last policy until it next contacts the service. Never promise instant remote auto-update disabling on an unreachable site.

## 4. Relational model

### Multiple administrator connections on one installation

Implemented in the local pilot, with hosted transport prepared for staging. Each WordPress administrator may create a separate Sunrise connection. The plugin must scope enrollment credentials, pending approval details, report sequences, revocation state, and received policy documents to that local user ID. Its UI and manual API operations use the authenticated user's connection; a submitted user ID must never select another administrator's credential. Existing enrollment migrates to its recorded owner without changing its secret or central IDs.

Background reporting must service connections independently, using each owner's current capabilities. Disconnecting, deleting, or demoting one owner must not disconnect another owner's connection. The central schema already records local user IDs and allows separately approved enrollments for the same URL; URL equality does not authorize access or justify merging records across tenants.

Policy arbitration is evaluated locally across the installed, independently approved connections. No server endpoint merges records by URL or UUID or grants one connection access to another tenant. Each connection first resolves its normal site-over-network inheritance. Across connections, component/core rules outrank category defaults, which outrank broad defaults; at equal specificity the greatest service-issued per-setting revision wins. `inherit` withdraws that connection’s rule, allowing the other remaining rules to apply. Component identity drift and local emergency pauses retain their safety precedence.

Migration 006 stores per-field change revisions in PostgreSQL. Only changed field values advance the global sequence; normalized missing/inherit values are equivalent. Revisions use decimal strings on the wire so JavaScript/PHP integer widths cannot change their ordering. Policy schema 2 adds decision revision/specificity metadata for core, every installed component and category defaults. A check-in, policy re-resolution, retry, unrelated-field change or identical save cannot give an unchanged rule a newer revision. Legacy schema-1 receipts may continue alone until synchronization upgrades them; unordered and ordered receipts are never merged.

Each connection reports `rejected` with `local_policy_override` when its received policy differs from the installation-wide decision. This can replace an earlier applied acknowledgment for the same generation. Status reflects the latest report, so another network’s dashboard learns about a subsequent local conflict on that connection’s next check-in. Native provider restrictions are reported separately and still take precedence. Conflict reports contain no other network names, memberships or authors. Local connection state and its credentials are cleared together by clone/database recovery; the public installation UUID is never an authorization credential.

These are logical tables for the next migration, not untested SQL shipped as a migration. UUID primary keys, `created_at`, and current state/revision fields are implicit where appropriate.

| Table | Main fields and constraints |
| --- | --- |
| people | Unique `(issuer, subject)`; disabled state |
| accounts | Name, active state |
| account_memberships | Unique `(account_id, person_id)`; role; active state |
| networks | `account_id`, name; unique `(account_id, id)` |
| network_memberships | `(account_id, network_id, person_id)` unique; role; references active account membership for authorization |
| sites | `account_id`, `network_id`, installation URLs, environment, generation, enrollment state, last-seen time; unique `(account_id, network_id, id)` |
| enrollments | Credential digest, phrase/approval-token digest, metadata, expiry, state, approving person, assigned site; pending records have no tenant until approval |
| site_credentials | Credential ID/digest, full site scope, generation, revoked_at; unique digest; no plaintext secret |
| policy_revisions | Account/network, optional site, monotonic scope revision, validated document, author, timestamp; immutable; unique revision per scope |
| site_sync | Full site scope, inventory sequence, resolved policy generation/hash, applied generation/hash, local pause, last report, rejection reason |
| inventory_items | Full site scope, type, installed ID, verified catalog identity if known, version, active flag, offer summary, freshness, observed auto-update state; unique `(site, type, installed_id)` within tenant scope |
| jobs | Full site scope, actor, action, exact payload, idempotency key/hash, state/version, execution token digest, deadlines, outcome; one reserved/running/uncertain mutation per site |
| audit_events | Account/network/site scope where applicable, actor, action, resource, sanitized change summary, time; append-only for runtime users |
| outbox | Scoped event ID/type, resource ID, due time, delivery state; written in same transaction as the change it dispatches |

Use composite foreign keys including account/network scope on every tenant relationship. An id-only foreign key is insufficient. Include scope in indexes used for site listings, component aggregation, pending jobs, and audit queries. A network-level policy's nullable site scope needs separate unique constraints for network and site revisions; do not rely on ordinary NULL uniqueness. Pending enrollments have restricted access separate from tenant APIs.

Use default-deny PostgreSQL RLS for tenant rows and a non-owner runtime role without BYPASSRLS. Derive transaction-local tenant/person/site context from authenticated server state, never raw body fields; reset automatically at transaction end for pooled connections. RLS must constrain the site principal to its site and human reads to permitted networks, not only to an account. Cross-scope errors return a generic not-found response without exposing constraint details. Migration and tightly bounded dispatch functions use separate privileges. No authorization result may be served from a stale query cache.

Audit and detailed job retention initially target 90 days. Retain a compact idempotency tombstone for the lifetime of the site enrollment so an aged-out result cannot cause replay; a retry with purged details returns `410 result_expired`. The WordPress agent must persist processed central job IDs beyond its prototype's last-20-results limit. Detailed error groups, transfer records/baselines, and subscriptions are added when their feature is implemented; they follow the same tenant constraints.

## 5. Policy document and precedence

Network and site policy use the same full-document format. `PUT` replaces a validated document with `If-Match` optimistic concurrency; clients preserve exceptions when changing a broad toggle. Missing fields default to `inherit` and missing item maps to empty. Unknown fields/values are rejected atomically.

```json
{
  "default": "inherit",
  "core": "minor",
  "plugins": {"default": "on", "items": {}},
  "themes": {"default": "inherit", "items": {}}
}
```

Defaults and item policies accept `inherit|on|off`. Core accepts `inherit|off|minor|all`; a broad `on` means all stable core releases, and `off` means none. Development core releases remain outside explicit Sunrise enabling. `minor` refers to WordPress's release classification, not an independent vulnerability assessment. Item keys at site scope are exact installed IDs; at network scope they are server catalog IDs whose provenance has been resolved. Display names cannot be keys. Each item map is limited to 5,000 entries with nonempty IDs of at most 255 characters and no control characters. An unresolved catalog identity skips the network item rule but still receives broader defaults and reports the identity limitation.

The local pilot implements explicit administrator-approved per-installation groups; see [component approval endpoints and limits](</Users/jonschroeder/Local Sites/Sunrise Control/README.md#component-approval-api>). Its fingerprints are metadata hints, not code/publisher verification. Previously approved identity drift forces that item off until review, taking precedence over the normal rule order below. Never silently match a new installation by display name.

For a plugin/theme, choose the first non-inherit value:

1. Site component exception.
2. Network component exception.
3. Site plugin/theme category default.
4. Network plugin/theme category default.
5. Site whole-site default.
6. Network whole-network default.
7. Native WordPress decision.

For core: site core rule, network core rule, site broad default, network broad default, native WordPress. Thus broad toggles preserve component exceptions even across scopes. “Disable absolutely everything” is an explicit local emergency pause, separate from defaults and exceptions. The pause can only be cleared locally in this milestone; a site credential can report its pause but cannot clear another site's pause or edit the authoritative policy.

Resolve desired policy before applying hard restrictions. A native/provider prohibition, emergency pause, missing capability, or file-modification restriction cannot be converted into permission by `on`. Report the requested mode, source, known restrictions, and observed decision separately; a provider disabling updates is different from a user's `off` preference. Unknown observed state remains null.

Network and site edits create immutable revisions. A resolver locks/updates each affected `site_sync` row, reads current committed policy documents, and publishes a new per-site generation only if the resolved document/hash changed. The transactional outbox requests this work (the current local slice uses a coalescing `policy_work` row per site); repeated or out-of-order events resolve current state rather than replaying old policy content. Until resolution/acknowledgment catches up, the policy is visibly pending. The agent atomically persists a complete policy generation before acknowledging its hash; it rejects lower generations and conflicting hashes for the same generation.

Updating policy is not an installation job. Acknowledgment means persisted and consistent with the currently effective Sunrise policy, not that every update is allowed or that any update ran. Incoming acknowledgments for obsolete generations cannot mark the latest generation applied. Policy resolution includes revisions and local installed-ID mappings in its canonical document; inventory changes that affect mappings trigger resolution.

## 6. API surface for the local-service milestone

All human routes require a central human session. Prefix below: `N = /v1/accounts/{a}/networks/{n}`. Resource scope is checked before returning any data. Collection responses use `{items, next_cursor}`, default limit 50, maximum 100. Cursors bind query and tenant scope. Mutations return structured `{error: {code, message}, request_id}` failures, never credentials or raw provider packages. Responses containing private data use no-store.

| Method and route | Permission / behavior |
| --- | --- |
| GET `/v1/accounts` | Active memberships only |
| GET `/v1/accounts/{a}/networks` | Networks with explicit grants; owners may list metadata for administration |
| POST `/v1/accounts/{a}/networks` | Account owner; creates network and explicit administrator membership for creator |
| GET `N/members` | Network administrator; paged list, ETag and `can_manage_administrators` hint |
| PUT `N/members/{person}` | Membership rules in section 2; conditional write; `active:false` removes access; administrator changes also require account ownership |
| GET `N/sites`, `N/inventory` | `inventory.read`; cached site list and installed-ID aggregates; aggregate pages up to 500 with tuple cursor |
| GET `N/inventory-members?type=plugins&installed_id=...` | `inventory.read`; cached per-component connection reports, at most 100 per page with UUID cursor; exact offered versions, restrictions and freshness |
| GET `N/policy`, `N/sites/{s}/policy` | `policy.read`; document, revision/ETag, reconciliation status |
| PUT `N/policy`, `N/sites/{s}/policy` | `policy.write`; full document, required If-Match; returns saved revision with pending application |
| POST / GET `N/sites/{s}/refresh` | `jobs.create` / `jobs.read`; coalesced provider check and status, without installing updates |
| POST `N/sites/{s}/jobs` | `jobs.create`; required Idempotency-Key UUID; queued job, HTTP 202 |
| GET `N/sites/{s}/jobs/{j}` | `jobs.read`; HTTP 200 plus Retry-After while pending |
| POST `N/sites/{s}/jobs/{j}/cancel` | `jobs.cancel`; only before start authorization |
| POST `N/sites/{s}/jobs/{j}/reconcile` | `jobs.reconcile`; inspected outcome/evidence, required job version; never silently rerun |
| DELETE `N/sites/{s}/enrollment` | `sites.manage`; disconnect and revoke |
| POST `/v1/enrollments` | Unauthenticated, bounded enrollment initiation only |
| POST `/v1/enrollments/{e}/approve` | Human `sites.manage` at selected network plus approval token/phrase |
| POST `/v1/enrollments/{e}/exchange` | Matching enrollment secret; returns pending/assigned/expired |
| POST `/v1/agent/check-in` | Site credential; own reports only, receives own policy/work hints |
| POST `/v1/agent/jobs/claim` | Site credential and persisted random execution token; reserve eligible own job, or `{job:null}` with HTTP 200 |
| POST `/v1/agent/jobs/{j}/start` | Own reserved job, execution token, expected state version; fresh server authorization |
| POST `/v1/agent/jobs/{j}/status` | Own job plus execution token; bounded status for recovery |
| POST `/v1/agent/jobs/{j}/events` | Own job, execution token and event sequence; progress/result, never arbitrary state writes |
| DELETE `/v1/agent/enrollment` | Self-disconnect |

First-account creation is an explicit local bootstrap operation, not an open production registration endpoint. Account invitations, billing, diagnostics browsing, and transfer routes follow later; never mount placeholders that return success without enforcement.

The refresh extension is implemented separately from the planned installation-job routes. Check-in may return `refresh_request:{id,expires_at}` with a 60-second execution window; the site reports `refresh_ack:{id,code}` with full inventory. Codes are `refresh_attempted`, `refresh_throttled`, or `refresh_interrupted`, never proof that provider data is fresh. Requests coalesce while queued, expire after 24 hours, and recheck the human actor before delivery. The agent persists the request before work, retains its ID to prevent repeat execution, and retries an unacknowledged report unchanged. After an accepted receipt, subsequent reports omit that acknowledgment. Upgrade the service before agents; do not roll back to a service that rejects this extension while agents have pending refresh acknowledgments.

Policy writes without If-Match return 428; stale versions return 412. Invalid schemas return 400, unauthenticated 401, insufficient permission on a visible resource 403, hidden resources 404, conflicting state/idempotency 409, oversized body 413, unsupported API version 426, and rate limits 429 with Retry-After. Policy/resource ETags are opaque; clients must not manufacture revisions. A create-only policy request uses the advertised initial revision rather than last-write-wins.

Initial budgets: a check-in is at most 2 MiB decoded JSON and 5,000 inventory entries; error batches at most 50 summaries of 2 KiB each within that body. Full inventory is an atomic snapshot; oversized inventories are rejected without dropping the prior snapshot, pending a paged-upload extension. Check-ins use a monotonically increasing sequence per enrollment generation and a payload hash: duplicate same-sequence/same-hash requests return the prior receipt, conflicts return 409, and older sequences cannot replace newer data. Use server receipt time as the authoritative last-seen clock. The service supplies `next_check_in_after_seconds`; the agent applies jitter and obeys Retry-After. This is synchronization cadence, not a new automatic-update schedule.

Check-in request fields: `protocol_version: 1`, `sequence`, `inventory` (optional full snapshot), `policy_ack` (optional `{generation, hash, status, reason_code}`), `local_pause`, and optional bounded `errors`. No account/site selector is accepted in this body; derive scope from the credential. The response contains `receipt_sequence`, `server_time`, next-check-in delay, optional resolved `policy` with generation/hash, and `work_available`. GETs and check-ins never start an installation.

An inventory component includes `type`, `installed_id`, reported provenance, version, active state, `update_available: true|false|null`, optional offered version, provider-check attempt time, observed auto-update decision, and known restriction codes. Catalog identity is assigned/validated by the service, not accepted as authoritative from the reporting site. Preserve unknown/stale information; no package URLs, licenses, arbitrary paths, or HTML are trusted.

For example, a site reporting a policy acknowledgment sends the following alongside its ordinary check-in. The policy response carries `document_json`, the immutable UTF-8 JSON text stored by the service, together with its lowercase hexadecimal SHA-256 hash. That document includes schema version, generation, source revisions, and local component mappings. The agent hashes the exact received string bytes before decoding/validation, rather than independently reserializing JSON. The service serializes resolved documents with fixed schema field order and item maps sorted by ID; compare resolved values before allocating a generation. Shared PHP/TypeScript vectors must verify byte/hash handling, including Unicode, before enrollment is enabled.

```json
{
  "protocol_version": 1,
  "sequence": 17,
  "policy_ack": {
    "generation": 8,
    "hash": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
    "status": "applied",
    "reason_code": null
  },
  "local_pause": false
}
```

Acknowledgment status is `applied` or `rejected`; rejection leaves the preceding applied policy intact and supplies a bounded machine-readable reason code. A successful human policy read reports `desired_revision`, `resolved_generation`, `applied_generation`, and `sync_status: pending|applied|rejected`, with `last_seen_at` and restrictions separately. A bulk network read returns counts for each status plus stale/offline counts; it must not call a site “applied” merely because some older generation was acknowledged.

## 7. Jobs and failure semantics

Planned actions: `refresh_inventory`, `run_auto_updates`, and `install_update`. The current extension implements only `install_update`; refresh has the separate coalesced endpoint above, and `run_auto_updates` is rejected until its policy/start semantics are implemented. The last requires type, installed ID, and exact offered version; no download URL, shell command, PHP, SQL, or arbitrary cron hook is accepted. Job creation checks current operator permission and site state; execution also checks current permissions of that original actor, enrollment generation, local user capabilities, restrictions, and current update offer. `run_auto_updates` uses the latest applied policy and refuses to start while a newer resolved policy remains unapplied. Do not preserve an old broad enabling decision as a queued command.

Valid initial job bodies are `{"action":"refresh_inventory"}`, `{"action":"run_auto_updates"}`, or `{"action":"install_update","type":"plugin","installed_id":"example/example.php","version":"2.1.0"}`. Type is `plugin|theme|core`; core uses installed ID `wordpress`. Unknown fields are rejected, and item/version fields are only accepted for install_update. The server supplies job IDs, enqueue time, and an initial 24-hour start deadline; a later intentional operation needs a new idempotency key. Claim reservations last 60 seconds and return the job and state version. The implemented agent generates and persists a random 256-bit execution token before claiming; the server stores only its digest. A repeated claim with that token retrieves the same reservation, avoiding token loss after an uncertain HTTP response. The start response gives a 60-second start-authorization window; once started, expiry never implies permission to rerun.

Idempotency key uniqueness is `(account, network, site, actor, key)` with a canonical request hash. Same key/body returns the same job; different body conflicts. Record job/audit/outbox in one transaction. A bulk operation later becomes explicit per-site jobs, never an implicit all-sites target that changes during execution.

| From | Allowed next state | Guard |
| --- | --- | --- |
| queued | reserved | Site claim, active enrollment/actor, no other reserved/running/uncertain mutation |
| queued | cancelled / expired | Cancel before claim, or deadline passed |
| reserved | running | Matching execution token and version, lease valid, fresh authorization |
| reserved | queued | Reservation expired before start; invalidate old token atomically |
| reserved | cancelled / expired | No start authorization issued; serialize race with start |
| running | succeeded / failed | Agent result with matching execution token and next event sequence |
| running | uncertain | Missing result, interrupted worker, or lost heartbeat |
| uncertain | succeeded / failed | Late authenticated result or explicit reconciliation evidence |
| uncertain | closed_unverified | Explicit operator inspection confirms worker stopped but outcome cannot be established |

Terminal states never return to queued. `failed` may include partial changes; it does not mean rollback succeeded. `closed_unverified` never displays as success. A lost start response may mean running: retry the same start/token to obtain the prior authorization, never obtain a new job ID. A running timeout does not authorize another worker to execute. Reconciliation must establish the former worker cannot still mutate before releasing the site's slot; a cloud timeout alone cannot prove that. Persistent local execution records, local mutation locking, and version/token checks fence duplicates. Cloud leases alone cannot stop PHP already executing filesystem work.

Event sequences and payload hashes make duplicate progress/results safe and prevent older results overwriting newer ones. Revoked agents cannot submit new events; unresolved jobs remain uncertain for inspection. Every authorization has an expiry; a delayed agent must reauthorize before starting, and resumable operations recheck at stage boundaries. Revocation after authorization still has an unavoidable in-flight window, documented rather than hidden.

## 8. Verification and migration gates

Reference checks cover scope isolation, absence of implicit site/owner authority, transfer grant direction, policy precedence and unknown identities, invalid policy rejection, emergency pause, and terminal/uncertain job semantics. These are executable contract examples, not evidence that HTTP authentication, RLS, cryptography, or concurrency is implemented securely.

Before claiming the next milestone complete, integration tests must exercise: two networks in one account plus another account; direct IDs and aggregate endpoints; revoked human/site credentials; fake enrollment and replay; pooled RLS contexts; enrollment approval races; policy-write/ack races; queue/database publication failures; duplicate claim/start/result delivery; late worker outcomes; site offline during policy changes; host overrides; local password change/deletion; and 1,000 synthetic inventories with bounded queries.

Remove the unactivated peer-directory/custom-signature prototype before connecting the central service. Disable legacy saved peer credentials in managed mode; require explicit re-enrollment rather than silently importing them. Preserve the tested native WordPress inventory, policy filters, and updater execution, but replace peer authorization and last-20 job deduplication. Existing local credentials are not the production network security model. No remote database or hosted service is provisioned by this specification.

References: [PostgreSQL RLS](https://www.postgresql.org/docs/current/ddl-rowsecurity.html), [WordPress auto-update controls](https://developer.wordpress.org/reference/functions/wp_is_auto_update_enabled_for_type/), [Cloudflare delivery guarantees](https://developers.cloudflare.com/queues/reference/delivery-guarantees/).


### Implemented installation milestone

The first central executor accepts exact-version installation only and limits each connection to one outstanding job. Human inventory/history reads are paged. Creation snapshots the reported installed version and identity; start rechecks the original human and enrollment. Claim/start/result and conditional cancellation/reconciliation are implemented with PostgreSQL transactions, RLS and audit records. No separate queue service is required for this outbound-only path.

A local durable execution fence and process-held file lock protect multiple connections to the same WordPress installation. Interrupted workers report uncertainty with stopped-worker evidence; a human may then close the operation unverified. Timed WordPress locks alone cannot establish stopped-worker evidence. Native automatic updates and other Sunrise installation jobs remain paused while the fence is present. Identity recovery takes the same process lock; generic transfers must exclude this installation-local lock and Sunrise state. Manual WordPress/host updates are outside Sunrise's locks.

The implementation returns agent job responses as `{job}`, and result events use `{execution_token,sequence,status,code,worker_stopped:true}`. Only final/uncertain outcomes are supported; progress heartbeats, automatic success/failure reconciliation, bulk runs and retention are deferred. The trusted Control dashboard exposes exact-version installation, cancellation and inspected stopped-worker reconciliation; the primary network dashboard also exposes aggregate reports, conditional policy forms and sequential selected-site job submission. Each selected job retains its own authorization, exact version and idempotency key; unsent work stops when the user closes the view or changes network. Embedding network controls in WordPress remains separate work. Actual end-to-end central installation is verified with a synthetic plugin; core/theme replacement and provider-host certification remain outstanding.
