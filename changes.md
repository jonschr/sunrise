# Changes

Release history starts with 0.2.0. Earlier development commits are not backfilled.

## 0.2.9 — 2026-09-09

- Fill the WordPress content area on Sunrise pages and suppress unrelated WordPress notices there. Move installation and connection controls into Site settings.
- Select migration peers directly from an authenticated, paginated dropdown. Preserve the selected site and direction without opening a Control window.
- Show the one-time network permission link only when needed. Exact migration approvals still require authenticated Control access.

## 0.2.8 — 2026-09-09

- Implement plugin and theme file selection: all, active, selected, or all except selected. Include active parent themes and exclude Sunrise itself.
- Transfer media files since the last successful transfer, since a UTC date, or all files. Hash checkpoints detect changed files even when timestamps are backdated. Attachment records still require the upcoming database/content handler.
- Freeze private ZIP archives and resume bounded, authenticated uploads/downloads through a compatible Control service. Require both exact transfer approvals before downloading to the destination.
- Verify archive and file hashes, protect destination edits, reject unsafe paths/symlinks and executable uploads, and honor host file-modification controls. Retain destination installation identity and administrator connections.
- Add per-owner file recovery, seven-day cleanup for completed copies, progress reporting, and bounded continuation jobs. Interrupted writes remain fenced; invalid downloads report failure without writing.
- Fix catalog requests on sites using plain permalinks and save migration preferences immediately.
- Pilot limits: ZipArchive and private temporary storage required; at most 5,000 files, 10,000 scanned entries and 512 MiB per transfer. Posts, full databases and arbitrary tables remain in development.

## 0.2.7 — 2026-09-09

- Bring network-wide updates, failures, site details, activity and automatic-update policies into WordPress using the shared Control dashboard.
- Keep access tied to the connected administrator and a separately approved network update permission. Other WordPress administrators still need their own connection.
- Add direct Control administration and permission links; move local connection settings below the dashboard.
- Keep credentials on the server and isolate the dashboard in a sandboxed service frame. Migration and account administration authority are never delegated through this dashboard.

## 0.2.6 — 2026-09-09

- Add a WordPress migration workbench with persistent per-administrator source/destination, scope and preset selections, explicit push/pull direction and live transfer status.
- Choose the counterpart through a signed-in Control window. Only the selected name, URL and ID return; network credentials remain outside WordPress.
- Prepare selected native settings with exact source/destination approval in Control. Transfer-only synchronization uses the existing durable writer and recovery journal; it does not install unrelated queued updates.
- Show content, media, plugin/theme file and table scopes as in development. Incremental/date modes are retained as preferences but cannot execute until their handlers exist.
- Clear saved migration preferences after identity recovery or uninstall.

## 0.2.5 — 2026-09-09

- Report open native WordPress automatic-update failures as distinct components, with bounded, classified reasons and no raw messages, API keys or download URLs.
- Clear failures after successful automatic updates or a reported installed version reaches the failed target, including updates completed outside Sunrise.
- Negotiate failure reporting and resolution checks with Control; send native failure snapshots only when changed and preserve exact retries.
- Clear local failure reports when reconnecting a cloned/replaced installation or uninstalling Sunrise.

## 0.2.4 — 2026-09-09

- Report the site's name and a small local favicon thumbnail to compatible Control services. Send the profile only when changed; omit large, unsupported or unavailable icons.
- Negotiate profile support before sending new fields, preserving older Control compatibility and exact report retries.
- Use the existing bounded follow-up check-in to populate a newly negotiated profile without waiting for the next routine cycle.

## 0.2.3 — 2026-09-09

- Keep Sunrise itself selected for native automatic updates, independent of network plugin policies, using the existing published-release feed.
- Respect provider-disabled offers and host restrictions. Preserve symlinked development installations and Git checkouts.
- Leave other plugins' automatic-update settings unchanged. WordPress cron, filesystem checks and native upgrade recovery still apply.

## 0.2.2 — 2026-09-09

- Shorten routine outbound check-ins from twelve hours to thirty minutes, plus up to thirty seconds of jitter, for the testing pilot.
- Reschedule existing connections on upgrade while retaining earlier pending work and honoring longer future server Retry-After delays. Reactivation restores connection scheduling.
- Enable location-only fatal summaries by default for connected sites, preserving explicit opt-outs. Retain at most 100 local groups for three days.
- Update the WordPress connection panel to show the new cadence. WP-Cron still requires site traffic or an external scheduler.

## 0.2.1 — 2026-09-09

- Default new connections to `https://sunrise-staging.elod.in`, so the testing plugin works immediately after installation.
- Keep the optional `SUNRISE_CONTROL_URL` override for local development and preserve existing connection origin checks.

## 0.2.0 — 2026-09-09

### Added

- Amber Sunrise Control mark for the WordPress menu and plugin update details.

- GitHub release update checking using bundled Plugin Update Checker 5.7, with WordPress's normal update notices and manual “Check for updates” action.
- Automatic `sunrise.zip` packaging and GitHub release publication when a matching version tag is pushed.
- Numbered release notes and checks that the plugin header, runtime version, stable tag and release tag agree.

### Changed

- Completed remote updates schedule a near-term check-in for remaining queued work. Routine reporting remains every twelve hours.
- Sunrise keeps its own update source and rejects cached offers from the unrelated WordPress.org plugin.

### Testing scope

- Initial release for the hosted staging pilot and SiteDistrict compatibility testing.
- Existing functionality includes per-administrator connections, clone detection/reconnection, outbound inventory, automatic-update policies, exact-version update jobs, and opt-in error summaries.
- Single-site WordPress only. Local checks cover WordPress 6.6 and 6.9.7 on PHP 8.1; live host compatibility is still being tested.
- Migration previews and local native-setting recovery are available; remote migration execution remains gated by the service. This release does not provide general post, media, plugin/theme file or database-table transfers.
