# Sunrise platform direction

Status: architecture and roadmap. A local PostgreSQL/API pilot now implements enrollment, policy delivery/acknowledgment, and tested tenant/site isolation; see [Sunrise Control README](</Users/jonschroeder/Local Sites/Sunrise Control/README.md>). The Cloudflare API and policy worker also build and pass local Workers-runtime/database tests, with Clerk SDK signature verification and explicit identity mapping. Clerk development sign-in now works locally with explicit identity linking. Cloud staging and hosted agent enrollment/reporting are verified. Network creation, existing-member access administration, and opt-in location-only fatal summaries have tested APIs. Self-service account admission/invitations and transfer execution remain unimplemented. The experimental peer-directory implementation has been removed.

The first implementation contract is [CONTROL-CONTRACT.md](CONTROL-CONTRACT.md). It fixes the initial identities, permission matrix, enrollment flow, logical tables, policy precedence, API surface, and job transitions. Its dependency-free reference checks run with `node --test tests/control-contract.test.mjs` from the Sunrise Control directory; they do not replace service integration or security testing.

## Product priorities

1. Manage whether WordPress core, plugins, and themes update automatically, across networks, with explicit exceptions and evidence that each site applied the policy.
2. Selectively synchronize posts, media, plugins, themes, selected tables, and individual WordPress options between connected sites, including local-to-hosted transfers and changes since the last successful transfer. Whole-site replacement is a separate, broader operation.
3. Report site errors with enough context to investigate, without adding significant request overhead or exposing sensitive data.

Manual update jobs support the policy system; they are not the primary product. Custom update timing, transfer execution, billing integration, and UI polish follow the API and authorization foundation.

## Deployment and ownership

Use PHP and native WordPress APIs for the site agent. Use one TypeScript service on Cloudflare Workers, managed PostgreSQL (initially Neon), and Cloudflare Queues for the control plane. Use Clerk for human authentication; keep Sunrise authorization in the application database. Add private R2 storage for transfers and Stripe Billing when those features are built. Start with one backend codebase, not separate microservices.

An account owns networks and a future subscription. People hold explicit account and network memberships. A network contains sites. Account, network, site, and operation IDs are generated identifiers, independent of URLs and WordPress user IDs. Each WordPress administrator may own a separate connection, including into a different network. Connections remain separate tenant records; cross-network transfers are denied. Moving membership requires an explicit re-enrollment procedure.

All tenant-owned rows and queries carry account scope, and network scope where applicable. Composite constraints prevent cross-account references. PostgreSQL row-level security supplements API authorization; runtime roles cannot bypass it. Background jobs, object storage access, and billing webhooks require the same isolation. Billing permissions do not imply permission to change sites.

## Update policy is desired state

Network policy provides defaults for core, plugins, and themes and exceptions for particular components. Specificity wins first, with the site rule winning ties: site component, network component, site category, network category, site default, network default, then native WordPress. Thus broad toggles preserve component exceptions. Core follows site core, network core, site default, network default, then native WordPress. `inherit` delegates to the next rule. Core supports off, minor release families, and all stable releases; development releases require a future explicit decision.

Use installed plugin basenames and theme stylesheet IDs for local targeting. Cross-site grouping must also consider available provenance such as an update URI/provider; matching display names alone cannot establish component identity. Unknown or conflicting identities must not silently receive a network component rule.

Each policy revision records its author, scope, exceptions, and creation time. Sites retain the last valid applied policy locally, so native automatic updates continue under that policy when the service is unreachable. A versioned policy write prevents an older controller tab from overwriting newer decisions. Local emergency disabling takes effect immediately and is reported as a local override, rather than silently overwritten during synchronization.

The API distinguishes:

- Desired policy and the scope it came from.
- Last policy revision acknowledged by the site.
- Observed WordPress decision for an available offer, plus known host/provider restrictions.
- Update availability and freshness, including unknown results.

An accepted cloud write is pending until the site acknowledges it. Offline sites remain pending. Enabling an automatic-update policy does not itself install an update. Native WordPress performs eligible updates; manual install and run-now requests are separate authorized actions. Site-wide on/off controls preserve stored exceptions unless the operator explicitly chooses to clear them.

The local central service implements this precedence, with administrator-approved component groups and a local/header-drift pause. See the separate Sunrise Control README for endpoints and tested limits. Release-specific exclusions remain outside the first contract, which controls components and core release categories.

## Site credentials cannot administer the network

Enrollment requires an authorized network operator and local WordPress authorization. The resulting revocable site credential can publish only that site's inventory, acknowledgments, and error reports, and retrieve only its assigned work. A site stores no other site's credential and no reusable human network session. Enrollment remains bound to a local authorizing user; deletion or lost capabilities disables privileged work. Normal WordPress password changes do not invalidate the separate enrollment credential.

Human sessions and sensitive approval live on a Sunrise-controlled origin. WordPress provides access to the dashboard, but PHP and JavaScript controlled by a member site must not receive reusable network administrator tokens. An embedded view is acceptable only if its origin/session boundary is preserved; cross-origin messages cannot authorize mutations. Fresh sensitive approval opens the trusted origin and displays the actual operation being approved.

A site report is untrusted input, not an instruction. Bound request sizes and rates per site/account, validate schemas, and escape diagnostic content. A compromised site can falsify its own reports and consume its own quotas; its identity cannot grant authority over another site. The central service remains a privileged trust boundary and requires separate operational protection.

## Synchronization and execution

Sites primarily make outbound HTTPS check-ins, batch changed inventory/error summaries, and retrieve pending work. This supports local sites behind NAT and reduces dependence on inbound host firewall rules. Optional authenticated wake requests improve responsiveness; they do not replace outbound polling. User-Agent identifies Sunrise honestly and is not authentication.

Dashboards query stored, indexed aggregates, never every WordPress origin during page rendering. Stale and unavailable sites remain visible. Jitter check-ins and bound per-site, per-account, and per-host concurrency. Routine site reports start at twelve-hour intervals, plus explicit manual sync. That is about 200 routine reports per day for 100 sites, or 2,000 for 1,000 sites, before enrollment, policy acknowledgments, and manual requests. The site initiates the outbound request, so inbound reachability is unnecessary. Sleeping/offline machines cannot report; traffic-driven WP-Cron runs overdue work at its next opportunity. Opening Sunrise Control reads stored reports, not live inventories from every member. This cadence reduces traffic but does not by itself guarantee free database hosting: central worker polling and dashboard use also consume compute.

Persist jobs in PostgreSQL; use queues to dispatch work, not as the only record of an operation. A transactional outbox and reconciliation prevent committed jobs being lost between database writes and queue publication. Delivery may repeat: stable IDs, durable results, and one active mutating job per site prevent duplicate execution. Uncertain outcomes require reconciliation before retry. Bulk actions record their exact target set and per-site outcomes.

WP-Cron is traffic-driven. An idle site with blocked inbound access cannot offer guaranteed execution timing using WordPress alone. Report delayed check-ins and work explicitly. One offline WordPress site affects its own work. A central outage pauses new network operations; cached local policy and ordinary local WordPress management continue.

## Push/pull authorization and data handling

The primary transfer unit is a reviewed selection, not a site clone. A transfer manifest identifies the exact records/settings/tables/files, dependencies, intended adds/updates, and conflicts. Default to added and changed data; deletions require a separate explicit selection and approval. The manifest is immutable after approval; new source changes require a new plan or remain outside that operation.

Support these scopes deliberately:

- **Posts and related content:** select individual posts or query by post type and changes since a prior synchronization. Include only reviewed dependencies such as required parent posts, terms, post meta, attachments, and featured images. Preview the expanded dependency set before approval. Do not silently copy users; map authors explicitly to destination users. Plugin-specific relationships and custom storage require declared support, not a claim that all post meta is portable.
- **Media library:** select all media, individual items, or additions/changes since the last successful transfer. Transfer attachment records, supported metadata, originals, and referenced derivatives as one logical unit, remapping attachment IDs and URLs. Preview filename collisions, destination edits, and missing source files. Unchanged files can be reused when verified. Offloaded media requires an explicit provider integration; never copy storage credentials or assume a local uploads file exists. Media synchronization does not grant permission to upload executable code.
- **Plugins and themes:** select individual components or changed components since the last successful transfer. Compare file manifests, not just version labels, so locally edited code is detected. Transfer only changed file bytes where possible, but stage and validate a coherent component before replacement rather than leaving a mixture of versions in the live directory. Include required file removals in the approved component diff. Code replacement is a separate privileged scope from media/content transfer. Preserve destination activation state by default; activation, theme switching, settings, and license migration are distinct choices. Validate runtime requirements, dependencies, host restrictions, and writable storage before replacement. Must-use plugins, drop-ins, WordPress core, and Sunrise itself are outside generic component transfers and need dedicated handling.
- **Options:** select exact wp_options keys and preview safe value differences. Preserve serialized types through native WordPress APIs. Exclude credentials, enrollment/policy state, cron, transients, cache entries, user roles, and site/environment identity from generic option transfers. Handle URL changes through a dedicated validated mapping, not arbitrary text replacement. Unknown plugin options may contain secrets or environment-specific IDs: mark them unsupported until their handling is defined. Never send secret values to the diff UI.
- **Tables:** distinguish whole-table replacement from selected row synchronization. Row synchronization requires a stable primary/unique key and known relationship semantics; tables without these can only use an explicitly approved replacement workflow. Schema differences block generic row sync. Preview affected rows/tables and backup/maintenance requirements. WordPress core content uses the content path instead of raw table copying. Core identity, credential, and Sunrise state tables cannot bypass protection through this scope.

Numeric WordPress IDs are local to an installation and cannot identify corresponding content across sites. Maintain explicit source-to-destination identity mappings and synchronization baselines outside transferable content. Unrelated pre-existing sites require reviewed matching; titles and slugs alone cannot silently establish identity. First sync must distinguish “create new” from “map to existing.” Remap supported references in parents, terms, media, blocks, and plugin data, and report unsupported relationships before writes.

### Automatic URL remapping

Every transfer plan includes source-to-destination URL mappings, derived from each site's reported home, site, content, and uploads URLs rather than assuming these share one root. Include schemes, ports, and subdirectory paths; require explicit mappings for additional aliases/CDN origins. Apply the mapping automatically to supported transferred data before destination writes. Show the mappings in the preview and bind them, together with the transformation version, to the approved manifest. Protect destination home/siteurl and environment identity from generic option replacement.

Transform decoded string values within supported PHP serialized data, JSON, block attributes, and content, then encode them correctly; never run raw SQL REPLACE on serialized bytes. Preserve data types and valid serialized byte lengths, handle supported escaped URL representations, and avoid double serialization. Do not instantiate PHP classes from transferred serialized objects: use a non-executing structural transformation or reject unsupported object/custom-serialization formats. Bound nesting, sizes, and parsing work, and fail affected items visibly on malformed or unsupported data rather than importing corrupted values.

Match URL boundaries and apply the most-specific mappings without cascading replacements, so a source domain does not match a similarly named domain and a custom uploads mapping takes precedence over a broad home mapping. Use per-attachment destination mappings where filename collisions change the resulting URL. Do not perform a destination-wide search/replace, change unrelated external URLs or identifiers, or rewrite plugin/theme source code and binary media as ordinary content. Treat GUID/identity fields by their field-specific import semantics, not a blanket URL replacement. Filesystem paths and plugin-specific encoded data need separate declared handling.

Record both the source snapshot and the expected transformed destination state in synchronization baselines. Compare against the corresponding side on later transfers so an intentional URL/ID translation does not appear as a fresh edit on every sync. Verification must cover serialized multibyte strings, nested arrays, JSON escaping, block data, overlapping URL roots, local ports/subdirectories, malformed input, and repeat/resumed transfers.

Detect changes from normalized selected content, including related data, rather than relying only on post_modified; metadata, terms, and options can change independently. Compare both sides to the last successful baseline to detect conflicts. Present keep-destination, apply-source, or skip per conflict; do not silently use last-write-wins. Revalidate the destination against the approved baseline before each write. Where concurrent edits cannot be excluded with a conditional write, require a short write freeze or stop for re-planning. Approval does not permit overwriting changes made after the preview.

“Since last transfer” uses persistent per-item baselines scoped to the site pair, direction, and selected data scope. Advance a baseline only after verifying that item's successful application; failed, skipped, or unselected items remain pending candidates. A first transfer requires a full selected-scope comparison. File hashes establish content identity; timestamps and sizes are scan hints, not proof of equality. Perform bounded, resumable scans during transfer preparation/background work, never whole-library hashing on normal page requests. Pin or stage the approved source content and reject a hash mismatch before applying it. Partial retries reuse the same manifest and item IDs.

Validate file paths and archive entries against the approved destination roots, reject traversal and escaping symlinks, and bound expanded size and file count. Never allow a media or component selection to become arbitrary filesystem access. Component replacement shares the site's mutation lock with updates and other transfers; backup and recovery are scoped to the component. Database migrations and third-party side effects triggered by replacement may require additional recovery and cannot be undone merely by restoring old code.

Build the transfer foundation around manifests, scoped grants, baselines, and resumable application. Implement native posts/pages with media dependencies and portable options, then independent media-library selection and plugin/theme replacement on the same protocol. Generic custom-table row synchronization follows schema-aware handling; ecommerce/order tables require a dedicated consistency strategy. All are requested transfer scopes; sequencing does not imply that arbitrary database merging is supported by the first transfer release.

Network enrollment makes sites discoverable, not mutually trusted for copying. An operator needs source export and destination overwrite permissions. Fresh approval creates a short-lived, single-operation grant bound to the source, destination, direction, exact scope, plan revision, and expiry. Approval consumption is atomic; a consumed grant can support the same resumable operation but cannot start another. Recheck revocation and authorization at destructive stage boundaries.

A grant for A to B does not allow B to A or B to C. A compromised source can nevertheless place malicious code or content in a transfer to an authorized destination; authorization limits reach, it cannot make imported content trustworthy. There are no standing pairwise transfer credentials.

Transfer artifacts are private, scoped to the operation, checksummed, size-limited, and expire. Move large data directly through object storage with resumable parts, not through one long API request. Destination preflight and backup precede replacement. Active-site writes require an explicit consistency/maintenance plan; database replacements require serialization-aware transformations.

Record durable per-item outcomes and resume the same operation without duplicating posts or attachments. Apply WordPress content changes through native APIs to keep caches and hooks consistent, while accounting for hooks that send mail, call webhooks, or start third-party workflows. Report unsupported side effects before execution. A multi-step content transfer is not globally atomic; partial failure must identify completed and pending items and provide a bounded recovery plan. Restoring a backup can overwrite later edits, so recovery cannot be an unconditional automatic full-database rollback.

Keep enrollment secrets, Sunrise identity/state, environment configuration, and destination-specific secrets out of copied data. User-table replacement must explicitly address the destination's authorizing user. A clone must never acquire its source's network identity. Local sites use the same outbound transfer protocol.

An A-to-B content transfer leaves B as B. Preserve B's installation ID, URL/salt bindings, administrator connections, credentials, policy/report state, jobs, and synchronization baselines. The transfer planner and importer must exclude Sunrise-owned options (including `sunrise_installation`, every `sunrise_*` state option and Sunrise transients), future Sunrise user metadata/tables, the destination `wp-content/sunrise-installation.php` anchor, and destination configuration even when selecting `wp_options` or whole tables. Restoring these after a destructive table replacement is insufficient: imported credentials or jobs must never become active during the transfer. No content transfer may call installation identity recovery. This is a required transfer invariant; the transfer engine is not yet implemented.

## Error visibility

Start with PHP fatal errors observable after the agent loads, Sunrise operation failures, and missing check-ins. Add deduplicated PHP warnings and deprecations as a subsequent opt-in capability after compatibility testing. Browser JavaScript monitoring and full request tracing are separate future features.

Store bounded local error summaries and upload them asynchronously during check-ins. Do not send HTTP requests from an error handler, read entire logs on each page load, enable public error display, or turn on production WP_DEBUG automatically. Preserve existing PHP/WordPress error handling; collectors must not replace recovery behavior or swallow errors. A regular plugin cannot observe failures before it loads, hard process kills, or every memory-exhaustion failure. Report this coverage explicitly. Missed check-ins indicate unknown health, not proof of an outage; optional external HTTP checks provide a separate signal and are not available for private local sites without connectivity.

Group errors by a normalized fingerprint and store severity, sanitized message, relative file/line, first/last occurrence, count, and observed component/version context. Link nearby policy changes and update jobs for investigation without claiming causation. Mark new, recurring, and resolved groups; a recurrence can reopen a group. Apply bounded retention, unique-group limits, sampling, and storm suppression so an error loop cannot exhaust the site or service.

Redact and truncate before upload. Exclude request bodies, cookies, authorization headers, SQL, query strings, absolute paths, and stack argument values. Error text itself may contain secrets or personal data, so normalization/redaction must cover the message too. Diagnostic viewing has its own network permission. Automated pausing or rollback in response to error reports is deferred until an explicit policy and trustworthy health checks exist; telemetry alone cannot command another site.

## Implementation gates

First remove the unactivated peer-to-peer prototype and preserve the tested native local executor. Define the versioned policy, enrollment, inventory, job, and error-report API contracts and their authorization matrix before adding screens. Implement tenant isolation and revocation tests, policy reconciliation tests, and duplicate-delivery/error-storm checks alongside the service. Validate on disposable Cove sites and hosted staging at SiteDistrict, Flywheel, and WP Engine, then load-test the 1,000-site model. Use an isolated headless browser for UI checks.

## References

- [PostgreSQL row security](https://www.postgresql.org/docs/current/ddl-rowsecurity.html)
- [Cloudflare PostgreSQL connectivity](https://developers.cloudflare.com/hyperdrive/examples/connect-to-postgres/postgres-drivers-and-libraries/)
- [Cloudflare queue delivery guarantees](https://developers.cloudflare.com/queues/reference/delivery-guarantees/)
- [WordPress production/debugging behavior](https://developer.wordpress.org/advanced-administration/debug/debug-wordpress/)
- [PHP shutdown callbacks](https://www.php.net/manual/en/function.register-shutdown-function.php)
- [PHP last-error inspection](https://www.php.net/manual/en/function.error-get-last.php)
