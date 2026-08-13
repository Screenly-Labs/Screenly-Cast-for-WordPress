#!/usr/bin/env bun
/**
 * Renders the WordPress.org plugin icons from the master SVG.
 *
 * The directory shows an SVG where it can and falls back to PNG elsewhere, so all
 * three have to agree, and regenerating them from one source is the only way that
 * stays true. Run this after changing .wordpress-org/icon.svg.
 *
 * Rendered through the browser Playwright already provides, so this adds no image
 * tooling to the project.
 *
 * Two details that matter for the directory:
 *
 * - The icons must be SQUARE, and the master artwork is not (744x900). It is
 *   scaled to fit and centred rather than stretched.
 * - The background stays transparent, because the plugin card is light in one
 *   theme and dark in another.
 */

import { readFile } from 'node:fs/promises'
import { chromium } from '@playwright/test'

const SOURCE = '.wordpress-org/icon.svg'
const SIZES = [128, 256] as const

async function main(): Promise<void> {
  const svg = await readFile(SOURCE, 'utf8')

  const browser = await chromium.launch()

  for (const size of SIZES) {
    const page = await browser.newPage({
      viewport: { width: size, height: size },
      deviceScaleFactor: 1
    })

    await page.setContent(
      `<!doctype html><html><head><meta charset="utf-8" /><style>
         html, body { margin: 0; height: 100%; background: transparent; }
         body { display: grid; place-items: center; }
         /* Scale to fit the square, preserving aspect ratio. */
         svg { width: 100%; height: 100%; max-width: 100%; max-height: 100%; }
       </style></head><body>${svg}</body></html>`,
      { waitUntil: 'load' }
    )

    const target = `.wordpress-org/icon-${size}x${size}.png`
    await page.screenshot({ path: target, omitBackground: true })
    await page.close()

    console.log(`✓ ${target}`)
  }

  await browser.close()
}

await main()
