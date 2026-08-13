#!/usr/bin/env bun
/**
 * Generates the sample photograph the fixtures use.
 *
 * Deliberately bright, busy and high-key: a dark photograph flatters any overlay,
 * so testing against one proves nothing. Real signage photos are frequently
 * washed-out skies and pale interiors, and that is the case where a scrim either
 * works or the headline becomes unreadable at three metres.
 *
 * Rendered through the browser we already depend on, so this adds no image
 * tooling to the project.
 */

import { mkdir } from 'node:fs/promises'
import { chromium } from '@playwright/test'

const OUT_DIR = 'tests/e2e/.artifacts'
export const SAMPLE_PHOTO = `${OUT_DIR}/sample-photo.png`

const SCENE = `
<!doctype html>
<html><head><meta charset="utf-8" /><style>
  html, body { margin: 0; height: 100%; }
  body {
    background:
      radial-gradient(120% 90% at 22% 12%, #fffdf6 0%, #ffeec9 34%, #ffd79a 58%, #f2b071 78%, #d98f5a 100%),
      linear-gradient(180deg, #fff 0%, #ffe9c4 100%);
    position: relative; overflow: hidden;
  }
  .sun { position:absolute; left:18%; top:14%; width:22vw; height:22vw; border-radius:50%;
    background: radial-gradient(circle, #fff 0%, #fff6dc 45%, rgba(255,246,220,0) 72%); }
  .haze { position:absolute; inset:0;
    background: linear-gradient(200deg, rgba(255,255,255,.65) 0%, rgba(255,255,255,0) 45%); }
  .ridge { position:absolute; left:0; right:0; bottom:0; height:38%;
    background: linear-gradient(180deg, #cfae86 0%, #b78f66 60%, #9d7550 100%); }
  .ridge::before { content:''; position:absolute; inset:0;
    background: repeating-linear-gradient(96deg, rgba(255,255,255,.22) 0 3px, rgba(0,0,0,.05) 3px 9px); }
  .panes { position:absolute; left:6%; right:6%; top:26%; height:34%;
    display:grid; grid-template-columns: repeat(7, 1fr); gap: 1.2vw; }
  .panes div { background: linear-gradient(160deg, rgba(255,255,255,.92), rgba(255,255,255,.45));
    border: 2px solid rgba(255,255,255,.75); }
  .panes div:nth-child(3n) { background: linear-gradient(160deg, #ffe7b0, rgba(255,255,255,.5)); }
</style></head>
<body>
  <div class="ridge"></div>
  <div class="panes">${'<div></div>'.repeat(14)}</div>
  <div class="sun"></div>
  <div class="haze"></div>
</body></html>
`

/**
 * Render the sample photograph.
 *
 * The output is generated rather than committed, so callers must be able to make
 * it on demand — CI has no artifacts directory until something creates one.
 *
 * @param force Re-render even if the file already exists.
 */
export async function makeSampleImage(force = false): Promise<string> {
  await mkdir(OUT_DIR, { recursive: true })

  if (!force && (await Bun.file(SAMPLE_PHOTO).exists())) {
    return SAMPLE_PHOTO
  }

  const browser = await chromium.launch()
  const page = await browser.newPage({
    viewport: { width: 2400, height: 1350 },
    deviceScaleFactor: 1
  })
  await page.setContent(SCENE, { waitUntil: 'load' })
  await page.screenshot({ path: SAMPLE_PHOTO })
  await browser.close()

  return SAMPLE_PHOTO
}

// Allow running this file directly to regenerate the image.
if (import.meta.main) {
  const path = await makeSampleImage(true)
  console.log(`Sample photograph written to ${path}`)
}
