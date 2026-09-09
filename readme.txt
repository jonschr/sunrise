=== Sunrise ===
Contributors: jonschroeder
Requires at least: 6.6
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Private WordPress update inventory, policies, remote jobs, and an optional WordPress-based controller.

== Installation ==

Install and activate Sunrise. Open Sunrise to manage this site or connect another installation of Sunrise.
Create a WordPress application password on the target site under Users > Profile, then enter it in the controller's connection form.
Activation preserves existing automatic-update settings. No credential is generated on activation.

== Requirements ==

Single-site WordPress 6.6+, PHP 7.4+. The controller additionally needs PHP OpenSSL.
HTTPS is required except for explicitly local WordPress environments. See README.md for local setup, API details, security, and host limitations.

This is a private plugin. WordPress.org updates are disabled to prevent replacement by an unrelated plugin with the same name.

== Changelog ==

= 0.1.0 =
* Authenticated, cached inventory of core, plugins, themes, must-use plugins, and drop-ins.
* Site and per-item automatic-update policies.
* Serialized WordPress update jobs with request IDs and status reporting.
* Optional controller in Sunrise, with encrypted per-user connections.
