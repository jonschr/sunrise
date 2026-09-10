=== Sunrise ===
Contributors: jonschroeder
Requires at least: 6.6
Requires PHP: 7.4
Stable tag: 0.2.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Private WordPress update inventory, policies, remote jobs, and an optional WordPress-based controller.

== Installation ==

Upload sunrise.zip through Plugins > Add New > Upload Plugin, then activate Sunrise.
Sunrise connects to https://sunrise-staging.elod.in by default. No wp-config.php change is needed.

Open Sunrise as the administrator who will own the connection, choose Connect this site,
and approve the connection in Sunrise Control. Return to WordPress to finish and sync.
Sunrise keeps itself updated through the native WordPress automatic updater, independently of network plugin policies. Other plugins retain their existing settings. No credential is generated on activation.

== Requirements ==

Single-site WordPress 6.6+, PHP 7.4+. The controller additionally needs PHP OpenSSL.
HTTPS is required except for explicitly local WordPress environments. See README.md for local setup, API details, security, and host limitations.

Updates come from published GitHub releases at github.com/jonschr/sunrise. Use the sunrise.zip release asset. WordPress.org updates are disabled to prevent replacement by an unrelated plugin with the same name.

== Changelog ==

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
