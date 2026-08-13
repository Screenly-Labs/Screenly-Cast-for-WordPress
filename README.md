# Screenly Cast for WordPress

[![CI](https://github.com/Screenly-Labs/Screenly-Cast-for-WordPress/actions/workflows/ci.yml/badge.svg)](https://github.com/Screenly-Labs/Screenly-Cast-for-WordPress/actions/workflows/ci.yml)
[![OpenGrep](https://github.com/Screenly-Labs/Screenly-Cast-for-WordPress/actions/workflows/opengrep.yml/badge.svg)](https://github.com/Screenly-Labs/Screenly-Cast-for-WordPress/actions/workflows/opengrep.yml)

Render posts, pages and image media in a layout built for digital signage.
Append `?srly` to any URL.

A WordPress plugin for casting content onto
[Screenly](https://www.screenly.io) digital signage devices, and onto most
other systems that can display a URL.

Without Screenly Cast for WordPress:
![Without Screenly Cast for WordPress](/.wordpress-org/screenshot-1.png)

With Screenly Cast for WordPress:
![With Screenly Cast for WordPress](/.wordpress-org/screenshot-2.png)

## Installing

* Search for *Screenly Cast* in the WordPress plugin directory, or install it
  from
  [the Screenly Cast listing on WordPress.org](https://wordpress.org/plugins/screenly-cast/)
* Activate the plugin

## Usage

Point a screen at an ordinary page URL. Recognised signage players are detected
from their request and sent to the signage view of whatever they asked for, so
there is nothing to append and nothing to remember.

Adding `?srly` yourself still works and always wins, which is how you preview a
signage render from your own browser.

1. Make sure the **plugin is activated**.
1. Optionally set a logo under **Settings → Screenly Cast**, shown in the
   corner of every render.
1. To request a signage render explicitly, add an `srly` parameter to the URL:
    * `https://www.mydomain.com/?srly`
    * `https://www.mydomain.com/my-post-url?srly`
    * `https://www.mydomain.com/my-page-url?srly`
    * `https://www.mydomain.com/my-attachment-url?srly`
    * `https://www.mydomain.com/?somevar=1&anothervar=2&srly` — when the URL
      already has parameters

    The parameter needs no value. `?srly` on its own is enough.

1. [Log in to Screenly](https://login.screenlyapp.com), go to **Content**, and
   add the URL as new **URL** content. Give it a recognisable title, add it to
   a playlist, and pick a duration that suits the amount of text.

Works with [Screenly](https://www.screenly.io) and
[Anthias](https://anthias.screenly.io/), and with most other digital signage
systems.

## How it works

A request carrying `srly` is rendered by the plugin's own template, with its
own stylesheet. **The site's active theme is never modified** — not for that
request, and not for anyone else's.

### Player detection

A recognised player is **redirected** to the same URL with `?srly` added, rather
than being served signage on the original URL. That choice is about caching, and
it is the whole reason the feature is safe to have on by default: returning two
different pages for one URL depending on request headers means any page cache or
CDN serves whichever it cached first to everybody — a screen gets the normal
theme, or a visitor gets the signage render. `Vary` is not a dependable fix, as
CDNs routinely strip or ignore it. Two URLs cache independently; only the
redirect itself is marked uncacheable, and it is a few bytes.

Signals used, strongest first: the `X-Screenly-*` metadata headers, the Android
`X-Requested-With` package name, then user agent tokens that name a player and
nothing else — Screenly, Anthias, BrightSign, IAdea, Slideshow, Unifi Connect.

**Detection is deliberately cautious, because the two failure modes are not
symmetric.** A missed player still works with `?srly`. A wrongly-claimed device
shows a signage render to somebody who was only browsing, with no explanation
and no way back. So a signal has to be conclusive.

That rules out signals signage-kit rates medium or low confidence, which is
right for a classifier — you filter on confidence afterwards — and wrong for
something that changes what a person sees:

| Excluded | Because |
| --- | --- |
| LG `WebAppManager` / `NetCast` | webOS runs LG signage *and* LG smart TVs |
| Samsung `Tizen` | Tizen runs Samsung signage *and* Samsung smart TVs |
| `QtWebEngine` | Players embed it, and so does any desktop Qt app |

Real Screenly and Anthias players carry their own tokens, so excluding bare
QtWebEngine only loses players that identify as nothing in particular. Also
never redirected: crawlers (a stripped `noindex` page served to Googlebot is
cloaking), signed-in users, non-`GET` requests, feeds, and meeting-room devices
such as Zoom Rooms and Google Meet displays.

This is detection, not authentication. Every signal is a request header and can
be set by anyone; a false positive only ever grants a different view of already
public content, so none of it gates access to anything.

```php
// Opt into the broader engines, if you know your own fleet.
add_filter(
    'screenly_cast_is_signage_player',
    function ( bool $detected ): bool {
        return $detected || PlayerDetector::matches_ambiguous_engine();
    }
);
```

That is worth stating plainly because it was not true before. Versions up to
1.0.5 called `switch_theme()` on the whole site whenever a `?srly` URL was
requested, and switched it back when a normal request arrived. Any anonymous
visitor could change the active theme for every visitor, and a site could be
left stuck on the bundled theme. On upgrade, this version detects that state,
restores the theme you were using, and says so.

What a render contains: the title, the date for posts (the site name for
pages), a short body, and the featured image if there is one. What it does
not: navigation, sidebars, comments, sharing buttons, or links — links are
reduced to their text, because there is nothing to click.

Design decisions that follow from the medium:

* **The composition responds to the content.** With a featured image it is a
  full-bleed photograph with a scrim and the text set low. Without one, the
  type becomes the image.
* **Content is fitted, not scrolled.** The server trims to a character budget,
  then the browser trims to what actually fits at that resolution. A screen
  cannot scroll and nobody is there to try.
* **One fluid scale, no breakpoints.** Correct from 800×480 up to 4K in both
  orientations.
* **Old players are expected.** CSS is compiled down to a Chrome 87 baseline,
  and players below it get a reduced-motion mode rather than a broken layout.
* **No external requests.** Fonts ship with the plugin.

Styling is built on
[`@screenly-labs/signage-kit`](https://github.com/Screenly-Labs/signage-kit),
which provides the browser-support floor, the CSS down-levelling recipe, the
degraded-mode gate and the shared webfont set.

### Extending it

```php
// How much text survives (default 600 characters).
add_filter( 'screenly_cast_character_budget', fn() => 900 );

// Hide the publication date. The eyebrow then shows the site name instead.
add_filter( 'screenly_cast_show_date', '__return_false' );

// Which HTML tags survive. Deliberately small; `a` is absent on purpose.
add_filter( 'screenly_cast_allowed_tags', function ( array $tags ): array {
    $tags['mark'] = array();
    return $tags;
} );
```

The palette and type scale are CSS custom properties, so restyling usually means
overriding a few tokens rather than writing a stylesheet. Attach to the
`screenly-cast` handle so your rules land after ours:

```php
add_action( 'wp_enqueue_scripts', function (): void {
    wp_add_inline_style(
        'screenly-cast',
        ':root{--color-ground:#101820;--color-ink:#fff;--color-brand:#e2001a;}'
    );
}, 100 );
```

The tokens worth knowing:

| Token | What it controls |
| --- | --- |
| `--color-ground` | The page background on text-only renders |
| `--color-ink` | Title and heading colour |
| `--color-ink-soft` | Body copy |
| `--color-ink-dim` | The eyebrow on text-only renders |
| `--color-brand` | The one accent: the eyebrow rule, list markers, quote rule |
| `--srly-ground-deep-rgb` | Scrim over photos, as `R G B` channels |
| `--srly-text-shadow` | Legibility shadow used over photos |
| `--root-min` / `--root-gain` / `--root-max` | The whole type scale |

`--srly-ground-deep-rgb` is a channel triplet rather than a colour so the scrim
can apply a different alpha at each gradient stop. `color-mix()` would be the
obvious tool, but it is Chrome 111 — above this project's Chrome 87 floor — and
Lightning CSS cannot down-level it when an operand is a variable.

### Which image is used

The featured image, or failing that the first image in the post content that
comes from the media library. Hotlinked images are ignored. On a media URL, that
image is the subject of the render.

## Development

### Requirements

* [Bun](https://bun.sh) 1.3 or later
* Docker, for `wp-env`
* PHP 8.2+ and Composer, for the PHP tooling

### Setup

```bash
bun install
composer install
bun run build      # compile signage CSS/JS into screenly-cast/assets/dist
bun run env:start  # WordPress at http://localhost:8888 (admin / password)
```

The plugin is mounted into the environment, so PHP edits are live. Re-run
`bun run build` after changing anything under `src/`.

### Checks

```bash
bun run lint          # Biome (JS/CSS) + markdownlint
bun run typecheck     # tsc
bun run version:check # header and readme.txt agree with package.json

composer run lint:php # PHP_CodeSniffer, WordPress standards
composer run analyse  # PHPStan, level 8

bun run test:php      # PHPUnit, inside wp-env
bun run env:seed      # seed the browser-test fixture corpus
bun run test:e2e      # Playwright
```

`bun run test:e2e` renders a corpus of fixtures across the supported
resolution matrix and asserts the things that matter: nothing overflows, no
links survive, no external requests are made, the webfonts load, and nothing
paints over the headline. It also writes
`tests/e2e/.artifacts/contact-sheet.html` — every render on one page, which is
the artifact to open when judging how the design *looks*, as opposed to
whether it is correct.

Note that the compiled assets under `screenly-cast/assets/dist/` are
**committed**: WordPress.org runs no build step, so the plugin has to carry
them. CI fails if they differ from a fresh build.

### Releasing

The version lives in `package.json` and is propagated everywhere else:

```bash
# 1. Set the new CalVer version in package.json (YYYY.M.MICRO), then:
bun run version:sync   # writes the header, SRLY_VERSION and the stable tag
bun run build          # rebuild the committed assets
# 2. Add a changelog entry in screenly-cast/readme.txt
# 3. Commit, then tag with the exact same version — no `v` prefix
git tag 2026.8.1 && git push origin 2026.8.1
```

Pushing the tag deploys to WordPress.org and creates a GitHub release. The
workflow refuses to publish when the tag, the plugin header and the stable tag
disagree, so a tag pushed without running `version:sync` fails loudly instead
of shipping a mislabelled release.

To build an installable zip by hand:

```bash
./bin/build.sh   # writes dist/screenly-cast.zip
```

### Versioning

CalVer, `YYYY.M.MICRO` — month unpadded so it stays a valid version string,
and `MICRO` counting releases within the month. This matches signage-kit and
the other Screenly projects.

## License

This project is licensed under the [AGPLv3](/LICENSE).
