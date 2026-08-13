#!/usr/bin/env bun
/**
 * Renders the WordPress.org plugin banners from the master logo.
 *
 * Without these the plugin page header is a flat colour block, so they are not
 * decoration — they are the first thing anyone sees on the listing. Required
 * filenames and sizes are fixed by the directory: banner-772x250.png and its
 * exact-double retina variant banner-1544x500.png.
 *
 * Drawn in the plugin's own design language — the same ground, display face and
 * brand accent as a signage render — so the listing and the product look like one
 * thing. The webfont is inlined as a data URI because a rendered-from-string page
 * has no base URL to resolve a relative font path against.
 */

import { readFile } from 'node:fs/promises'
import { chromium } from '@playwright/test'

const OUT = '.wordpress-org'
const LOGO = `${OUT}/icon.svg`
const DISPLAY_FONT = 'screenly-cast/assets/dist/fonts/bricolage-grotesque-latin-standard-normal.woff2'
const BODY_FONT = 'screenly-cast/assets/dist/fonts/hanken-grotesk-latin-wght-normal.woff2'

/** The directory fixes these; the retina one must be exactly double. */
const SIZES = [
  { width: 1544, height: 500 },
  { width: 772, height: 250 }
] as const

async function dataUri(path: string): Promise<string> {
  const bytes = await readFile(path)
  return `data:font/woff2;base64,${bytes.toString('base64')}`
}

async function markup(): Promise<string> {
  const logo = await readFile(LOGO, 'utf8')
  const display = await dataUri(DISPLAY_FONT)
  const body = await dataUri(BODY_FONT)

  return `<!doctype html>
<html><head><meta charset="utf-8" /><style>
  @font-face {
    font-family: 'Bricolage Grotesque';
    font-weight: 200 800;
    src: url('${display}') format('woff2');
  }
  @font-face {
    font-family: 'Hanken Grotesk';
    font-weight: 100 900;
    src: url('${body}') format('woff2');
  }

  html, body { margin: 0; height: 100%; }
  body {
    /* The same ground a signage render uses, with a wash of the logo's own
       gradient so the banner and the mark share a palette. */
    background:
      radial-gradient(120% 160% at 88% 12%, rgb(151 46 255 / 0.30) 0%, rgb(49 83 252 / 0.16) 42%, rgb(11 13 18 / 0) 72%),
      #0b0d12;
    color: #f4f1ec;
    display: flex;
    align-items: center;
    gap: 4.2%;
    padding: 0 6%;
    box-sizing: border-box;
    font-family: 'Hanken Grotesk', sans-serif;
    overflow: hidden;
  }

  .mark { flex: none; height: 46%; }
  .mark svg { height: 100%; width: auto; display: block; }

  .stack { display: flex; flex-direction: column; gap: 0.5em; min-width: 0; }

  .name {
    font-family: 'Bricolage Grotesque', sans-serif;
    font-weight: 700;
    /* Sized from the viewport so both banner sizes are the same composition. */
    font-size: 11.6cqw;
    line-height: 0.94;
    letter-spacing: -0.022em;
    margin: 0;
    white-space: nowrap;
  }

  .rule { width: 3.2em; height: 0.34em; background: #6b4eff; }

  .tagline {
    margin: 0;
    color: #d9d5ce;
    font-size: 3.5cqw;
    line-height: 1.3;
    white-space: nowrap;
  }
</style></head>
<body>
  <div class="mark">${logo}</div>
  <div class="stack">
    <p class="name">Screenly Cast</p>
    <div class="rule"></div>
    <p class="tagline">WordPress content, composed for screens</p>
  </div>
</body></html>`
}

async function main(): Promise<void> {
  const html = await markup()
  const browser = await chromium.launch()

  for (const { width, height } of SIZES) {
    const page = await browser.newPage({ viewport: { width, height }, deviceScaleFactor: 1 })
    // cqw units need a container query context; the root gets one from the
    // viewport only if something declares it, so set it on <body> per size.
    await page.setContent(html, { waitUntil: 'load' })
    await page.addStyleTag({ content: `body { container-type: size; }` })
    await page.evaluate(() => document.fonts.ready)

    const target = `${OUT}/banner-${width}x${height}.png`
    await page.screenshot({ path: target })
    await page.close()
    console.log(`✓ ${target}`)
  }

  await browser.close()
}

await main()
