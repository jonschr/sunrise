# Sunrise platform direction

Status: architecture and roadmap. A local PostgreSQL/API pilot now implements enrollment, policy delivery/acknowledgment, and tested tenant/site isolation; see [Sunrise Control README](</Users/jonschroeder/Local Sites/Sunrise Control/README.md>). The Cloudflare API and policy worker also build and pass local Workers-runtime/database tests, with Clerk SDK signature verification and explicit identity mapping. Clerk development sign-in now works locally with explicit identity linking. Cloud staging and hosted agent enrollment/reporting are verified. Network creation, existing-member access administration, and default-on location-only fatal summaries have tested APIs. Self-service account admission/invitations remain unimplemented. The experimental peer-directory and migration implementations have been removed.

The first implementation contract is [CONTROL-CONTRACT.md](CONTROL-CONTRACT.md). It fixes the initial identities, permission matrix, enrollment flow, logical tables, policy precedence, API surface, and job transitions. Its dependency-free reference checks run with `node --test tests/control-contract.test.mjs` from the Sunrise Control directory; they do not replace service integration or security testing.

## Product priorities

1. Manage whether WordPress core, plugins, and themes update automatically, across networks, with explicit exceptions and evidence that each site applied the policy.
2. Report site errors with enough context to investigate, without adding significant request overhead or exposing sensitive data.

Manual update jobs support the policy system; they are not the primary product. Custom update timing, billing integration, and UI polish follow the API and authorization foundation.

## Deployment and ownership

Use PHP and native WordPress APIs for the site agent. Use one TypeScript service on Cloudflare Workers, managed PostgreSQL (initially Neon), and Cloudflare Queues for the control plane. Use Clerk for human authentication; keep Sunrise authorization in the application database. Add Stripe Billing when needed. Start with one backend codebase, not separate microservices.

An account owns networks and a future subscription. People hold explicit account and network memberships. A network contains sites. Account, network, site, and operation IDs are generated identifiers, independent of URLs and WordPress user IDs. Each WordPress administrator may own a separate connection, including into a different network. Connections remain separate tenant records. Moving membership requires an explicit re-enrollment procedure.

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
