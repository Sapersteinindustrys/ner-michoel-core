=== Ner Michoel Core ===
Contributors: tomo
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Site functionality for the Ner Michoel rebuild — custom post types, forms,
and integrations. Kept as a plugin, separate from the ner-michoel-child
theme, so content and data structures survive a future redesign or theme
change.

== Installation ==

1. Upload the `ner-michoel-core` folder to `/wp-content/plugins/`.
2. Activate through the 'Plugins' menu in WordPress.
3. Install and activate the `ner-michoel-child` theme separately (requires
   Astra as the parent theme).

== Changelog ==

= 0.3.0 =
* Add Contact-submission records (private `nm_submission` post type) and a
  "Recent Submissions" panel view, so a submission survives even if its
  email never arrives.
* Add an optional Dedication field on Shiur, rendered on the single-shiur
  page in both layouts.
* Add a Bulk Upload Shiurim flow — pick multiple audio/video files at once,
  then assign a speaker and series to the whole batch in one step.
* Add a one-time "Import Sample Content" screen to seed real sample
  shiurim/speakers/series.
* Add a "Post a Mazal Tov" quick-add form (4 fields, no full post editor).
* Add a "Post News" shortcut that files the new post under the News
  category automatically.
* Add a Live Shiur / Zoom link manager (Zoom link, meeting ID, schedule).
* Add a "Missing Audio" filter view on the Shiurim list table.
* Add a "Content Editor" role — everything an Editor can do, minus
  plugin/theme/settings/user-management capabilities.
* Add per-shiur play/completion tracking (`Plays` / `Completed` columns on
  the Shiurim list, sortable) via a new REST endpoint the player pings.
* Add a self-hosted Site Statistics page — pageviews, approximate unique
  visitors, top pages, top referrers, and real client-reported page load
  time, tracked entirely in this site's own database (no third-party
  analytics service).

= 0.2.0 =
* Add `gallery` CPT (photo/video/shiurim-video via `gallery_type` taxonomy).
* Add `mazal_tov` CPT for News & Events announcements.
* Add a "Site Control Panel" admin dashboard consolidating everything into
  one plain-language menu, plus a `/admin` URL shortcut into it.
* Add an admin-managed Homepage Slider (image + heading/subtext/button).
* Add purpose-specific image crops (speaker photo, series cover, gallery
  thumb/slide, hero slide), generated in the background via WP-Cron instead
  of blocking the upload/save request.
* Add a proper shiur download endpoint (clean "{Speaker} - {Title}" filename)
  instead of relying on the `download` attribute.
* Add Contact form and "Email a Magid Shiur" submission handlers, including
  a per-speaker forwarding email field.
* Add self-hosted auto-updates via GitHub releases.

= 0.1.0 =
* Initial scaffold.
