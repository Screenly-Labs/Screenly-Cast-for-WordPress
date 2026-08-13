# Release notes for the next release

<!-- upgrade-notice -->
Signage rendering no longer changes your site's theme. If an earlier version left
your site on the bundled Screenly Cast theme, this release switches your own theme
back automatically. Requires PHP 8.2 and WordPress 6.8.

A rewrite. The plugin no longer installs or activates a theme at all, and a
signage request no longer switches your site's theme — which, before this
release, any anonymous visitor could do for every visitor at once. Signage
players are now recognised from their request and sent to the signage view
automatically, so a screen can be pointed at an ordinary page URL with no `?srly`
to add. The design is rebuilt to fit any screen from 800x480 to 4K in both
orientations, with the fonts bundled rather than fetched from a CDN. Requires
WordPress 6.8 and PHP 8.2.

## Breaking

* Requires WordPress 6.8 and PHP 8.2. Tested up to WordPress 7.1.
* The bundled Screenly Cast theme is no longer installed into
  `wp-content/themes`. Rendering is handled by the plugin. If you had customised
  that copied theme directory, those edits are not carried over.
* The unused `screenly_cast` post type and `screenly_cast_category` taxonomy have
  been removed. Existing posts of that type are left in the database untouched,
  but nothing renders them.
* Versioning moves to CalVer (`YYYY.M.MICRO`) to match Screenly's other projects.
* Licence changed to AGPL-3.0-only.

## Security

* A signage request no longer switches the site's active theme. Previously any
  anonymous visitor requesting a `?srly` URL changed the active theme for every
  visitor, and a site could be left serving the bundled theme to everyone. This
  is also the cause of the 2017 report that the plugin "cleared CSS
  customizations":
  Customizer CSS is stored per theme, so switching the theme took the site's
  custom CSS out of play.
* The upgrade repair now requires an administrator and refuses to run during AJAX
  or cron. `admin-ajax.php` fires `admin_init` before it checks `action` or any
  nonce and is reachable unauthenticated, so without that guard an anonymous
  request could have triggered a theme switch.

## Added

* Signage players are recognised automatically and sent to the signage view, so
  a screen can be pointed at an ordinary page URL with no `?srly` to add. Detection
  uses the `X-Screenly-*` headers, the Android package name and player user agent
  tokens. Crawlers, signed-in users and meeting-room devices are excluded, as are
  engines shared with consumer hardware (LG webOS, Samsung Tizen, bare
  QtWebEngine) — a missed player still works with `?srly`, but a wrongly claimed
  device would show signage to somebody who was only browsing.
* A post with no featured image now uses the first image in its content, so adding
  a picture with the "Add Media" button is enough. Reported in 2018 as "image with
  text overlay does not appear to work".
* The date can be hidden with the `screenly_cast_show_date` filter, and the
  colours and type scale can be overridden through the design's CSS custom
  properties. Both requested in 2018.
* Verified against the WordPress 7.1 release candidate. None of 7.1's breaking
  changes apply: the plugin adds nothing to the block editor, uses no
  `@wordpress/components`, adds no toolbar items, and does no image processing.

## Fixed

* Asset cache-busting never worked: the version constant shipped as the literal
  placeholder text, so browsers and players kept serving stale CSS and JS after
  an update.
* The theme was copied into `wp-content/themes` on *every page load*, attempting
  a recursive directory copy on each request.
* Theme switching and option deletion ran during ordinary front-end page views,
  with a redirect that could loop.
* Activation, deactivation and uninstall hooks were never registered, so plugin
  options were never cleaned up. There is now an uninstall routine.
* The logo setting could never work: it was registered under one option name,
  read under another, from a file that was never loaded, against a mismatched
  settings group. It is now a working media picker under Settings → Screenly Cast,
  and an existing logo value is migrated. Removing the logo also clears a
  migrated URL fallback, which previously reappeared on every render with no way
  to remove it.
* Requirement notices were double-escaped and displayed HTML entities as literal
  text.
* Casting an image media URL works again. WordPress 6.4 disabled attachment pages
  by default, which had silently broken it.
* Content that arrives without a wrapping element — a table or figure block, whose
  wrapper the tag allowlist removes while keeping its text — is now clamped like
  anything else. It previously bypassed the character budget entirely.
* A self-closing disallowed element such as `<svg />` no longer discards the rest
  of the post.
* A title too wide for the screen is no longer clipped in silence. On a portrait
  player the fluid type scale is driven by the long edge, so a single word at the
  largest size could be wider than the screen; the fitter now steps the title down
  on width as well as height.
* Signage requests to an archive or the blog index now compose against the entry's
  image, rather than silently rendering text-only.
* The `screenly_cast_show_date` and `screenly_cast_is_signage_player` filters now
  treat the string `'0'` as false, which is what `get_option()` returns for a
  saved-off boolean.
* PHP 8 warnings from the short-link helper, and invalid markup on attachment
  renders.
* The plugin's own upgrade no longer risks interrupting an administrator with an
  FTP credentials prompt: removing the old theme directory is attempted only when
  PHP can write directly, and is treated as best-effort.

## Changed

* Content shaping uses an explicit tag allowlist rather than `strip_tags`, and
  both the allowlist and the character budget are filterable.
* Content is fitted to the actual viewport, measured in the browser, rather than
  trimmed one word at a time against a stale measurement.
* Webfonts are bundled with the plugin. The Google Fonts CDN request is gone, as
  is the third-party QR code API.
* Signage renders are marked noindex, and the theme's stylesheets, fonts, emoji
  scripts, oEmbed discovery and speculation rules are all kept out of them.
