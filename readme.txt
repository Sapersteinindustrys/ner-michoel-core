=== Ner Michoel Core ===
Contributors: tomo
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.9.22
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

= 0.9.22 =
* Site Control Panel redesigned to be easier for a beginner: a sidebar grouped
  by task (Shiurim, Announcements, Messages, Homepage & Look, Reports, and a
  collapsed Advanced group), a Home page with shortcuts, at-a-glance numbers,
  a Getting Started checklist, and the latest shiurim and messages.
* Help on every page (what it's for, step by step), a one-minute guided tour
  offered on the first visit, and Ctrl+K search to jump to any page.
* Lists: friendly status labels and dates, click a row to edit, empty states,
  and phone-friendly cards. Forms open in a panel from the right, with hints
  under the trickier fields and a warning before closing with unsaved changes.
* Settings screens have a save bar that shows unsaved changes, warn before
  leaving with unsaved changes, and number the homepage banner slides.
* Confirmations and "Saved" messages are in-page, not browser pop-ups.
* Quick Mazal Tov, Upload Many at Once and the import screens now return to
  the panel after submitting instead of opening wp-admin.
* Login Shortcut: empty password boxes now keep the current password; a new
  tick box turns the shortcut off. (Before, saving with the boxes empty
  removed the password.)
* The theme's styles, the WordPress toolbar and the audio player bar are kept
  off the panel. The /admin password page has a matching look.

= 0.9.21 =
* Bot and spam protection on the public forms (Contact, Email a Magid Shiur,
  Sign Up, Log In, Forgot Password): an invisible browser check (proof of
  work), optional Cloudflare Turnstile, per-address rate limits, repeat and
  spam-content checks (flagged messages kept under Submissions, not emailed),
  and a cap of 3 reset emails an hour per address.
* Login lockout after 10 wrong passwords from one address in 15 minutes,
  for every login. Application-password REST calls are not affected.
* Hides the site's login names from the public, and turns off XML-RPC.
* New Site Settings > Security screen: Turnstile keys, the two switches, and
  what's been turned away.

= 0.9.20 =
* Shiurim and Written Shiurim admin lists: a new Topics column, next to
  Series, showing every topic tag a shiur has — no need to open the edit
  form to see them.

= 0.9.0 =
* Shiurim search ranks by title: titles with the whole search come first,
  then titles with the most of its words, newest first within each group,
  each under its own heading. Only titles are searched, not content.
* Written Shiurim: a new post type for PDF lectures (title, speaker,
  series, description, one PDF), at /written-shiurim/. Shares the
  Speaker and Series taxonomies with Shiurim. Listed under Content in the
  Site Control Panel, and in the Written Shiurim dashboard shortcut.
* The full-library importer now imports the origin site's PDF shiurim as
  Written Shiurim, with the PDF attached. Rows parked as "skipped" by the
  earlier version are re-queued once, automatically.
* Site Settings > Menu: a switch (on by default) that lists Written
  Shiurim under the Shiurim item of the site header menu, on desktop and
  mobile. The menu in Appearance > Menus is not changed.
* Live Shiur / Zoom is finished: the Zoom link, meeting ID and schedule
  show in a block on the homepage and News & Events page (hidden until a
  link or schedule is set). The Site Control Panel tab now uses the same
  custom UI as the other settings screens.
* PDF downloads stream with a "{Speaker} - {Title}.pdf" filename. Media
  already offloaded to Bunny Storage now redirects to the CDN copy, instead
  of finding no local file and doing nothing.

= 0.8.22 =
* Restore two Hero Slider conveniences that didn't make the 0.8.21
  custom-UI conversion: a page-title search-as-you-type for each
  slide's button link, and a "set one button for every slide" bulk
  apply. Same custom-styled UI as everything else, not a wp-admin
  screen — the page search hits the existing nm_search_pages
  admin-ajax action directly.

= 0.8.21 =
* Extend the custom /admin UI's own-styled interface (added in 0.8.20)
  to the Settings screens too — Appearance (colors/font, palette
  presets, live preview), Layout Toggle, Hero Slider (slides,
  interval, button design), Admin Login Shortcut, and Storage (Bunny)
  now render as our own forms instead of wp-admin-styled ones. New
  includes/custom-admin-settings-api.php exposes a schema-driven
  REST read/save engine (reusing the existing Appearance/Storage REST
  routes, with three new ones added for Layout Toggle/Hero Slider/
  Admin Login); assets/custom-admin-settings.js is one generic
  single-form renderer covering all five, including a drag-to-reorder
  slide repeater for the Hero Slider. Native color inputs replace
  wp-color-picker for full visual independence from wp-admin.

= 0.8.20 =
* Replace the iframe-embedded wp-admin content screens (added in
  0.8.19) with a genuinely custom list + edit interface for Shiurim,
  Speakers, Series, Galleries, Mazal Tov, News Posts, Most Listened,
  Missing Audio, and Recent Submissions — our own markup/CSS, not
  WordPress's own list tables or block editor, talking to WordPress
  only through a new internal REST API (includes/custom-admin-api.php).
  One schema-driven engine covers every content type: search, taxonomy
  filters, pagination, add/edit/delete, image and audio/video pickers
  (still via wp.media(), the one native WP picker kept), and a
  drag-to-reorder multi-image picker for galleries.

= 0.8.19 =
* Embed real content-management screens directly inside the custom
  /admin UI instead of redirecting away — Shiurim, Speakers, Series,
  Galleries, Mazal Tov, News, Most Listened, Missing Audio, and Recent
  Submissions all now open in-place (same-origin iframe with WordPress's
  own admin chrome hidden, auto-sized to content, with an "Open in a new
  tab" fallback link). Quick-add forms (Post a Mazal Tov, Post News)
  remain separate tabs alongside their full list views.
* Redesign Site Statistics with visual charts instead of plain tables —
  gradient stat cards for Pageviews/Visitors/Avg. Load Time, a 14-day
  pageviews-vs-visitors bar chart, and horizontal bar charts for Top
  Pages and Top Referrers. Pure CSS, no external charting library.

= 0.8.18 =
* Replace /admin's old behavior (always redirecting into wp-admin's own
  Site Control Panel) with a genuinely custom admin UI rendered directly
  at /admin — categories across the top, sub-tabs within each, covering
  every settings screen (Appearance, Home Page, Live Shiur, Layout
  Toggle, Admin Login Shortcut, Storage, Import Sample Content, Full
  Library Import, Site Statistics) plus quick actions (Post a Mazal Tov,
  Post News, Bulk Upload). Editing individual posts (Shiurim, Speakers,
  Galleries, etc.) still opens WordPress's own editor/list tables — not
  reimplemented, that's a deliberate click-through, not the unwanted
  auto-redirect this replaces. All the previous wp-admin submenu pages
  still exist unchanged as a fallback.

= 0.8.14 =
* Fix the Appearance settings page's color pickers/palette swatches not
  reliably responding — same root cause as the earlier admin-wide button
  fix (a script ran before checking whether its dependencies/targets
  were ready). Wrapped in a DOM-ready handler.
* Add a live preview to the Appearance settings page — updates instantly
  as a palette is picked or a color is adjusted, before saving anything.

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
