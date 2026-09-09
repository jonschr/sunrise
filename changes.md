# Changes

Release history starts with 0.2.0. Earlier development commits are not backfilled.

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
