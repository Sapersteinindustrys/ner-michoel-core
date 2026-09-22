=== Ner Michoel Core ===
Contributors: tomo
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.2.0
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
