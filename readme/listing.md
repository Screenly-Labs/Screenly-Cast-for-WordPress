# Screenly Cast

Turn WordPress posts, pages and images into digital signage for any TV, kiosk or
notice board. Works with Screenly and Anthias.

## Description

Screenly Cast turns WordPress into a simple content creation tool for digital
signage, and for [Screenly](https://www.screenly.io) in particular.

It deliberately does not try to make WordPress into a digital signage CMS. There
are no zones, no feeds and no playlists. It does one thing: it takes a post, page
or image you have already published and renders it so it reads well on a screen
nobody is standing next to.

That covers most of what a screen on a wall is actually for: a digital notice
board in a school or an office, opening hours and a menu board in a café, a lobby
display, or a Raspberry Pi in kiosk mode showing whatever you published this
morning.

Point a screen at an ordinary page URL and it shows the signage view. Recognized
signage players are detected from their request and sent to the signage version
of whatever they asked for, so there is nothing to remember and nothing to edit.
Appending `?srly` still works, and still wins, which is what you want for
previewing on your own machine.

A signage render is the title, the date, a short body, and the featured image if
there is one. Everything that assumes a person with a mouse is removed:
navigation, sidebars, comments, links, sharing buttons.

**Your theme is never touched.** A signage request renders through the plugin's
own template and leaves your site's active theme exactly as it was. Earlier
versions of this plugin switched the whole site's theme when a signage URL was
requested; that is gone. See the changelog.

Some specifics, because they are the difference between "works" and "works on a
wall":

* **Fits the screen.** Content is trimmed to what actually fits, at the
  resolution the screen actually is. A screen cannot scroll and nobody is there
  to try.
* **Every resolution, both orientations.** Correct from an 800x480 Raspberry Pi
  Touch Display up to 4K, in landscape and portrait, with no configuration.
* **Works on old players.** Signage hardware is often years behind the desktop.
  The stylesheet is compiled down to a Chrome 87 baseline, and players below that
  get a reduced-motion mode rather than a broken layout.
* **No external requests.** Fonts are bundled with the plugin. Nothing is fetched
  from a CDN, which matters both for privacy and because a player is frequently
  offline.
* **Not indexed.** Signage renders are marked noindex, so they do not compete
  with your real pages in search results.

Works with [Screenly](https://www.screenly.io) and
[Anthias](https://anthias.screenly.io/) out of the box. Because a signage render
is just a URL, it works the same way on Yodeck, Xibo, OptiSigns, ScreenCloud,
Rise Vision, Signagelive, BrightSign, IAdea and any other player, wallboard or
Chrome kiosk that can open a web page. Those are other companies' products and
are not affiliated with Screenly; they are listed because the plugin genuinely
works with them.

The source code is on [GitHub][repo]. Contributions and bug reports are welcome.

[repo]: https://github.com/Screenly-Labs/Screenly-Cast-for-WordPress/

## Installation

1. Install and activate the plugin.
2. Optionally, go to **Settings → Screenly Cast** and choose a logo to show in
   the corner of every render.
3. Open any post, page or image and append `?srly` to its URL.
4. Add that URL as content in Screenly, or whichever signage system you use.

## Frequently Asked Questions

### When do I use "?srly" and when do I use "&srly"?

Use `?srly` when the URL has no other parameters:

* `https://www.mydomain.com/some-page?srly`

Use `&srly` when it already has some:

* `https://www.mydomain.com/?somevar=1&anothervar=2&srly`

The parameter needs no value. `?srly` on its own is enough.

### How are signage players recognized?

From their request: the `X-Screenly-*` metadata headers a Screenly player can
send, the Android package name several other players identify themselves with,
and user agent tokens that name a player and nothing else: Screenly, Anthias,
BrightSign, IAdea, Slideshow and Unifi Connect.

Detection is deliberately cautious, because the two kinds of mistake are not
equally bad. A player it misses still works if you add `?srly`. A device it
wrongly claims would show a signage render to somebody who was just browsing,
with no explanation and no way back.

So a signal has to name a player and nothing else. Notably absent: LG webOS and
Samsung Tizen, which run signage displays *and* every LG and Samsung smart TV,
and QtWebEngine, which signage players embed *and* so does any desktop Qt
application. Search engines, signed-in users and meeting-room devices are
excluded too: a room calendar is not signage.

A detected player is sent to the same URL with `?srly` added rather than being
shown signage on the original URL, so that a page cache or CDN never has to serve
two different pages for one URL. Only the redirect itself is uncacheable.

Turn it off under **Settings → Screenly Cast** if you would rather add `?srly`
yourself, or use the `screenly_cast_is_signage_player` filter to widen or narrow
which requests count.

### Does this change my site's theme?

No. A signage request renders through the plugin's own template for that one
request. Your active theme, and what every other visitor sees, is unaffected.

Versions up to 1.0.5 did switch the site's theme, and could leave a site stuck on
the bundled Screenly Cast theme. On upgrade this plugin detects that, switches
your original theme back, and tells you it has done so.

### How much text will be shown?

Assume a couple of hundred characters will be comfortably read at a distance.
Longer content is trimmed to what fits rather than overflowing or scrolling.

If you need a different limit, filter it:

```php
add_filter( 'screenly_cast_character_budget', fn() => 900 );
```

### Can I allow more HTML tags in signage content?

Yes, via the `screenly_cast_allowed_tags` filter. The default set is small on
purpose, and links are reduced to their text because there is nothing to click on
a screen.

### Which image is used?

The featured image if the post has one. Failing that, the first image in the post
content that comes from your media library, so adding a picture with the "Add
Media" button is enough, and you do not have to set a featured image as well.
Images hotlinked from other sites are ignored.

On an image's own media URL, that image is the subject of the render.

### Can I hide the date?

Yes:

```php
add_filter( 'screenly_cast_show_date', '__return_false' );
```

The line above the title then shows your site name instead, so the render still
says where it came from.

### Can I change the colors and typography?

The design is built on CSS custom properties, so overriding a handful of them is
usually enough. Add your own stylesheet after ours by attaching it to the
`screenly-cast` handle:

```php
add_action( 'wp_enqueue_scripts', function () {
  wp_add_inline_style( 'screenly-cast', ':root{--color-ground:#101820;}' );
}, 100 );
```

The tokens worth knowing are `--color-ground`, `--color-ink`,
`--color-ink-soft`, `--color-ink-dim` and `--color-brand`, plus `--root-min`,
`--root-gain` and `--root-max`, which together drive the whole type scale.

Over a photograph there are two more: `--srly-ground-deep-rgb`, the scrim color
written as `R G B` channels, and `--srly-text-shadow`.

### Why does my image media page work here but redirect normally?

Since WordPress 6.4, attachment pages are disabled by default and redirect to the
image file itself. This plugin keeps them renderable for signage requests only,
so casting a media URL still works without re-enabling attachment pages for
ordinary visitors.

### Does it scroll long content automatically?

No, by design. On a large, non-interactive display, fixed unmoving content reads
better than anything that moves.

## Support

For support, feature requests and bug reports, please use the
[GitHub Issues page][issues].

[issues]: https://github.com/Screenly-Labs/Screenly-Cast-for-WordPress/issues

## Screenshots

1. An ordinary post as a visitor sees it: navigation, byline, comments, next and
   previous links.
2. The same post rendered for a screen. Same content, nothing that assumes
   somebody is holding a mouse.
3. The same layout in portrait. Both orientations work from 800x480 up to 4K with
   nothing to configure.
4. The whole settings screen. A logo, and whether to recognize signage players.
