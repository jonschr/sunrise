=== Sunrise ===
Contributors: jonschroeder
Requires at least: 6.6
Requires PHP: 7.4
Stable tag: 0.2.25
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Private WordPress update inventory, policies, remote jobs, and an optional WordPress-based controller.

== Installation ==

Upload sunrise.zip through Plugins > Add New > Upload Plugin, then activate Sunrise.
Sunrise connects to https://sunrise-staging.elod.in by default. No wp-config.php change is needed.

Choose Connect this site from the WordPress notice or Sunrise page. When your signed-in
Sunrise account has one eligible network, the connection and first update report finish automatically.
Sunrise keeps itself updated through the native WordPress automatic updater, independently of network plugin policies. Other plugins retain their existing settings. No credential is generated on activation.

== Requirements ==

Single-site WordPress 6.6+, PHP 7.4+. The controller additionally needs PHP OpenSSL.
HTTPS is required except for explicitly local WordPress environments. See README.md for local setup, API details, security, and host limitations.

Updates come from published GitHub releases at github.com/jonschr/sunrise. Use the sunrise.zip release asset. WordPress.org updates are disabled to prevent replacement by an unrelated plugin with the same name.

== Changelog ==

= 0.2.25 =
* Avoid shared-host GitHub API limits by checking one static, release-validated metadata file.

= 0.2.24 =
* Refresh GP Premium and GenerateBlocks Pro's private updater caches after restoring a license without clearing unrelated plugin updates.

= 0.2.23 =
* Include standard Plugin Update Checker offers in automated check-ins so Control matches WordPress's Plugins screen.

= 0.2.22 =
* Restore GP Premium, GenerateBlocks Pro, and Admin Columns Pro licenses from write-only Control settings when a license is missing or an update fails.

= 0.2.21 =
* Replace gradient navigation backgrounds with the Sunrise sky image and improve sidebar navigation states and spacing.

= 0.2.20 =
* Show one centered maintenance message when the central database service is unavailable.

= 0.2.19 =
* Add the Sunrise gradient sidebar to the native WordPress migration screen.
* Choose one other connected site first, then select a clear Push or Pull direction.

= 0.2.18 =
* Keep migration selection, preparation, exact-plan review, approval, cancellation, and status inside WordPress.
* Remove the migration handoff to the hosted Control interface; Control remains the private transfer coordinator and file relay.

= 0.2.17 =
* Use the plugin REST path for remote requests, with WordPress's root query route as the non-pretty-permalink fallback instead of `/index.php`.
* Prepare settings and file scopes together as separate recoverable plans.
* Add an end-to-end, read-only whole-database inventory preflight. Database writes remain disabled.

= 0.2.16 =
* Offer connection from every WordPress admin page and send the first update report immediately after approval.
* Remove the redundant Sunrise page header and present unconnected sites in a compact setup card.

= 0.2.15 =
* Complete WordPress's database upgrade after a remote core update, including local HTTPS test sites.

= 0.2.14 =
* Classify update failures into safe, useful reasons without transmitting provider messages, package URLs, or credentials.

= 0.2.13 =
* Report the freshly installed WordPress core version immediately after a successful remote update.

= 0.2.12 =
* Stop remote activity after Control revokes a connection and offer reconnection in WordPress.
* Keep unconnected installations idle without changing native auto-update selections or other administrators' connections.

= 0.2.10 =
* Check in every five minutes for testing, with existing short jitter and retry backoff.
* Move existing routine schedules forward on upgrade and retain earlier queued-work events.
* Open Control at its canonical root URL.

= 0.2.8 =
* Add selected/all/active/excluded plugin and theme file transfers, and incremental media files.
* Use private resumable archives, exact pair approvals, hash checks, destination recovery and live progress.
* File transfers require compatible Control storage, ZipArchive and private temporary storage; database/content handlers are still in development.

= 0.2.2 =
* Use a thirty-minute routine check-in interval for testing and reschedule existing connections on upgrade.
* Enable error summaries by default, with an off switch, three-day retention, and a 100-group cap.
* Preserve early queued-work check-ins and longer Retry-After delays.

= 0.2.1 =
* Use Sunrise staging as the default Control service; no configuration is required to connect.

= 0.2.0 =
* Add GitHub release updates using the bundled Plugin Update Checker library.
* Package installable releases automatically from matching version tags.
* Start numbered release notes in changes.md.
* Continue remaining queued updates after a completed job without changing routine reporting.
* Prepare the existing network inventory, policy, update and recovery features for SiteDistrict pilot testing.

See changes.md for release notes and current testing limits.

Sunrise self-updates respect host restrictions and require working WordPress cron. Development checkouts and symlinked plugin directories are never replaced automatically.
