=== Ner Michoel Core ===
Contributors: tomo
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.8.13
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

= 0.8.12 =
* Add color palette presets to the Appearance settings screen — six
  curated one-click palettes (Forest, Royal Blue, Burgundy, Gold, Slate,
  Deep Purple) that fill in the color fields, previewable before saving.
* Name newly-sideloaded shiur audio/video files after the shiur's title
  (slugified) instead of the source URL's filename, for the sample
  content importer and the full-library import — scoped to brand-new
  sideloads only, never a rename of an already-existing attachment.

= 0.8.0 =
* Fix "Add"/"Choose" buttons across the admin (Homepage Slider, Gallery
  Images, Shiur audio/video, Speaker/Series cover image, Bulk Upload
  Shiurim) not responding to clicks. Homepage Slider's bug was a
  confirmed real one (a script ran before its target element existed,
  which skipped binding the click handler entirely). The meta-box
  pickers (Gallery, Shiur) moved off inline `<script>` tags entirely, in
  favor of one shared, properly-enqueued file using event delegation —
  more robust regardless of when/how the block editor injects a classic
  meta box's markup.

= 0.7.0 =
* Reorganize the Homepage Slider into a "Home Page" settings screen with
  tabs (Hero Slider is the first tab) — future homepage settings now
  have a place to live without adding more top-level menu items.

= 0.6.0 =
* Add a diagnostic hook for the GitHub update checker — captures the
  actual API error (if any) the next time a check fails, instead of a
  failed check silently looking identical to "already up to date".
  Readable via `GET /wp-json/ner-michoel/v1/update-check-debug`
  (`manage_options`), which also forces a fresh check and reports
  whatever update it finds.

= 0.5.0 =
* Add an "Admin Login Shortcut" — /admin can now be reached with a single
  shared password instead of the full WordPress login, once configured
  from the Site Control Panel. Falls back to the normal login unchanged
  until set up; the real WordPress login always still works either way.
* Add `ner_michoel_is_shiur_trending()` for the theme's "Trending" badge.
* Tune the full-library importer to run more slowly/politely (smaller
  batches, longer pauses between requests and between items) and add a
  REST control endpoint for starting/pausing/checking status
  programmatically.

= 0.4.0 =
* Add the ability to tag a shiur on "Email a Magid Shiur" submissions,
  including video shiurim (previously audio-only wasn't a restriction, but
  now explicitly supported end-to-end).
* Add an Appearance settings screen (colors + font) for the Shiurim/
  Galleries/News app and player bar.
* Add a self-hosted Bunny Storage adapter — new media uploads (including
  the library import below) offload to Bunny Storage instead of local
  disk, with every existing URL accessor updated transparently.
* Add a full-library import from nermichoel.org (background crawler +
  batched importer, ~16,756 items, pauseable/resumable) with a REST
  control endpoint for starting/pausing it programmatically.
* Extend the shiur media-type model with a 'video-embed' type
  (`_shiur_vimeo_id`) for externally-hosted (Vimeo) video shiurim, found
  during the library import to be a real, common case.

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
