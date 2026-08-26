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

**Stage 3, post editor controls (this release)**

* An Article Broadcast panel on the post editor, Posts post type only, working
  in both editors: a document setting panel in Gutenberg and a side meta box in
  the Classic editor.
* Send to subscribers checkbox, unticked by default on every new post.
* Email subject field, pre-filled from the post title and updating live until
  it is edited, after which it stops tracking.
* Optional preview text field and optional teaser override field.
* Status readout: not sent, queued, held, sent with the date and time, or
  failed with the reason.
* Unlock and resend button, shown only once a post has been sent, with a
  confirmation prompt.
* The post meta keys are registered once and shared by both editors, and are
  fixed so the future Klaviyo edition can carry across send history.

**Stage 4, content assembly engine (this release)**

* Reads article content from JetEngine fields, not post_content. The intro
  field, the article_sections repeater and its body, heading and image
  sub-fields are chosen in settings dropdowns populated from the site's real
  fields, so nothing is hard-coded and a field rename needs no developer.
* JetEngine repeater JSON is decoded and walked in stored row order.
* Teaser starts from the intro and only continues into the first repeater row
  body copy if the configured word count is not met, with markup and shortcode
  stripping and an optional trim to the nearest full sentence.
* Registered trademark symbols, curly quotes and apostrophes, ellipses, dashes
  and non-breaking spaces are converted to HTML entities so they render in
  Outlook and Gmail rather than as garbled characters.
* Three level image fallback: WordPress featured image, then the first section
  image, then a configurable placeholder, at a dedicated email image size.
* Read time is read from KDNA Reading Time and never recalculated. If that
  plugin is inactive or returns nothing, the read time is omitted cleanly.
* Category, author, published date in a configurable format, global CTA label
  with a per-post override, and a UTM builder with {slug} and {date} tokens.
* A settings preview panel assembles a chosen or the most recent post, and a
  post with no intro and no repeater rows is blocked with a clear warning.

**Stage 5, single article send (this release)**

* Publishing a flagged post creates a campaign in Campaign Monitor from the
  mapped single article template, hooked on transition_post_status so manual
  and scheduled publishing behave identically.
* Guard conditions: post type is post, the send checkbox is ticked, the post
  has no campaign yet, and the plugin is fully configured. A misconfiguration
  is reported on the post rather than failing silently.
* Three global send modes: draft only and notify (the default), auto-send
  immediately, or auto-send after a hold window.
* Hold window, configurable, default 30 minutes, implemented as a single cron
  event with a working cancel link in the notification email that leaves the
  draft intact.
* A send lock is written to post meta on success (campaign ID, status, mode
  and timestamp), so updating an already sent post never creates a second
  campaign.
* Admin notification email on creation, with the campaign ID and an open in
  Campaign Monitor link, plus the cancel link for a held send.
* A Sending settings card for the mode, hold window and notification address.

**Stage 6, test sends (this release)**

* A Send test button in the Article Broadcast panel, in both editors, always
  available including on an unpublished draft.
* Recipients are the editing user plus up to four standing test addresses set
  in the Sending card.
* Uses the Campaign Monitor campaign preview endpoint, which delivers the real
  rendered email. A temporary draft is created, previewed to the recipients,
  then deleted, so nothing is left behind.
* A test never sets the send lock and never counts as a real send, so a tested
  post can still be broadcast normally.
* Inline AJAX feedback in the panel, no page reload.

**Stage 7, send log (this release)**

* A custom database table, created on activation, one row per broadcast
  attempt, with post ID, post title, type, status, campaign ID, list ID,
  recipients estimate, mode, attempt number, message and timestamp.
* Every send, hold, test and failure is logged with the correct status.
* An admin screen at Settings > Article Broadcast Log built on WP_List_Table,
  with filtering by status and type, search, sortable columns, pagination and
  bulk delete.
* Row actions: view in Campaign Monitor, retry a failed row, cancel a held
  row, and view the full API response for a failure.
* Configurable retention, default keep everything, with an optional purge of
  rows older than a set number of months, run by a daily cron.
* The table is removed on uninstall only when the delete on uninstall setting
  is on, never on deactivate.

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
* Stage 3: the Article Broadcast post editor panel, Posts only, in both
  Gutenberg and Classic, with the send checkbox, title tracking subject,
  preview and teaser fields, status readout and unlock and resend.
* Stage 4: the JetEngine content assembly engine, field mapping dropdowns,
  repeater decoding, teaser generation, entity conversion, three level image
  fallback, KDNA Reading Time lookup, UTM builder, and the settings preview
  panel with an empty-content block.
* Stage 5: single article send on publish, three send modes with draft as the
  default, cron hold window with a working cancel, the post meta send lock,
  and the admin notification email.
* Stage 6: test sends via the Campaign Monitor preview endpoint, a Send test
  button in both editors, standing test addresses, no send lock, inline AJAX
  feedback.
* Stage 7: the send log, a custom table logging every attempt, and a
  WP_List_Table admin screen with filtering, search, pagination, retention and
  the view, retry, cancel and view response row actions.
