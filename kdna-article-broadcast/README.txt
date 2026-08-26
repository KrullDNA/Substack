=== KDNA Article Broadcast (Campaign Monitor) ===
Contributors: krulldna
Tags: campaign monitor, newsletter, email, elementor, broadcast
Requires at least: 6.3
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects a WordPress blog to Campaign Monitor so publishing an article can create a ready to send email campaign.

== Description ==

KDNA Article Broadcast connects a WordPress blog to Campaign Monitor so that
publishing an article can create a ready to send email campaign, without anyone
rebuilding the same email by hand every week. It supports two send modes, a
single article at publish and a weekly digest, and provides Elementor widgets
for signup and for a newsletter archive.

The plugin does not design emails. The design lives inside Campaign Monitor as a
template, and the plugin fills that template with content pulled from the post.

This build is delivered in stages. Each stage is individually testable.

**Stage 1, plugin foundation and API connection**

* Main plugin file with activation and deactivation hooks and class autoloading.
* Settings page at Settings > KDNA Article Broadcast, built with Alpine.js for
  the interactive parts.
* Campaign Monitor API key field, stored encrypted at rest.
* A shared API wrapper class handling authentication, timeouts, error codes and
  rate limit responses, used by every later stage.
* A Test connection button that performs a real GET request to the clients
  endpoint and reports the actual API response, not a format check.
* A Save that only writes settings once the key has been validated against the
  live API.

**Stage 2, client, list and template selection (this release)**

* AJAX driven dropdowns: pick the agency client, then its subscriber list and
  the two templates, single article and weekly digest, with no page reload.
* API responses cached in a one hour transient, with a manual Refresh button
  that clears the cache and refetches.
* Template screenshot shown as a visual reference for each chosen template.
* From name, from email and reply to fields, validated by format. Campaign
  Monitor does not list verified sender addresses through its API, so the
  address is confirmed at send time.
* Template region mapping panel. Campaign Monitor matches content to a template
  by position, so each KDNA field is assigned an Include flag and a position
  within its region type, single line, multi line or image. A live order
  preview shows the resulting region order. The mapping is saved and survives a
  reload, so changing a template later means adjusting numbers, not code.

== Installation ==

1. Upload the kdna-article-broadcast folder to /wp-content/plugins/.
2. Activate the plugin through the Plugins screen in WordPress.
3. Go to Settings > KDNA Article Broadcast.
4. Enter your Campaign Monitor API key, press Test connection, then Save.

== Frequently Asked Questions ==

= Where do I get an API key? =

Log in to Campaign Monitor, open Account settings, then API keys. The key needs
permission to create and send campaigns and to import subscribers.

= Is my API key stored safely? =

Yes. The key is encrypted at rest using a key derived from your site
authentication salts, and it is never sent back to the browser.

== Changelog ==

= 1.0.0 =
* Stage 1: plugin foundation, settings page, encrypted API key storage, shared
  Campaign Monitor API wrapper, and a genuine round trip Test connection button.
* Stage 2: client, list and template selection with AJAX dropdowns, one hour
  transient caching with a manual refresh, and the positional template region
  mapping panel.
