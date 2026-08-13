#!/usr/bin/env bun
/**
 * Captures the WordPress.org listing screenshots from the running wp-env site.
 *
 * These are the images a prospective user judges the plugin by, and the ones that
 * were most out of date: the committed pair predated the rewrite entirely, so they
 * advertised a design the plugin no longer has. Generating them from the real site
 * means they cannot drift again — re-run after a design change.
 *
 * Deliberately uses the same post for the "before" and "after" pair, because the
 * comparison is the point. The rich-markup fixture is the subject: it has a
 * heading, a list and a quote, so both renders have something to show.
 *
 * Prerequisites: `bun run env:start` and `bun run env:seed`.
 */

import { chromium, type Page } from '@playwright/test'

const BASE = process.env['SRLY_BASE_URL'] ?? 'http://localhost:8888'
const OUT = '.wordpress-org'
const MANIFEST = 'tests/e2e/.artifacts/fixtures.json'

/** The subject of the before/after pair. */
const SUBJECT = 'rich-markup'

type SeededFixture = { key: string; id: number; url: string }

async function subjectUrl(): Promise<string> {
  const file = Bun.file(MANIFEST)
  if (!(await file.exists())) {
    throw new Error(`No fixture manifest at ${MANIFEST}. Run \`bun run env:seed\` first.`)
  }
  const fixtures = (await file.json()) as SeededFixture[]
  const match = fixtures.find((fixture) => fixture.key === SUBJECT)
  if (!match) {
    throw new Error(`Fixture "${SUBJECT}" is not seeded.`)
  }
  return match.url
}

/** Wait for the signage fitter to settle and the entrance animation to finish. */
async function settle(page: Page): Promise<void> {
  await page.evaluate(() => document.fonts.ready)
  await page.waitForSelector('[data-srly-fitted]', { timeout: 15_000 })
  await page.evaluate(() =>
    Promise.all(document.getAnimations().map((animation) => animation.finished))
  )
}

async function main(): Promise<void> {
  const url = await subjectUrl()
  const browser = await chromium.launch()

  // 1. The same post as an ordinary visitor sees it, theme and all.
  const before = await browser.newPage({
    viewport: { width: 1920, height: 1080 },
    deviceScaleFactor: 1
  })
  await before.goto(url, { waitUntil: 'load' })
  await before.evaluate(() => document.fonts.ready)
  await before.screenshot({ path: `${OUT}/screenshot-1.png` })
  await before.close()
  console.log(`✓ ${OUT}/screenshot-1.png  (without Screenly Cast)`)

  // 2. The signage render, landscape.
  const after = await browser.newPage({
    viewport: { width: 1920, height: 1080 },
    deviceScaleFactor: 1
  })
  await after.goto(`${url}?srly`, { waitUntil: 'load' })
  await settle(after)
  await after.screenshot({ path: `${OUT}/screenshot-2.png` })
  await after.close()
  console.log(`✓ ${OUT}/screenshot-2.png  (with Screenly Cast, landscape)`)

  // 3. Portrait, because supporting both orientations with no configuration is a
  //    large part of the point and is invisible in a landscape-only pair.
  const portrait = await browser.newPage({
    viewport: { width: 1080, height: 1920 },
    deviceScaleFactor: 1
  })
  await portrait.goto(`${url}?srly`, { waitUntil: 'load' })
  await settle(portrait)
  await portrait.screenshot({ path: `${OUT}/screenshot-3.png` })
  await portrait.close()
  console.log(`✓ ${OUT}/screenshot-3.png  (portrait)`)

  // 4. The settings screen, so the listing shows there is nothing to configure.
  const admin = await browser.newPage({
    viewport: { width: 1600, height: 1000 },
    deviceScaleFactor: 1
  })
  await admin.goto(`${BASE}/wp-login.php`, { waitUntil: 'load' })
  await admin.fill('#user_login', 'admin')
  await admin.fill('#user_pass', 'password')
  await admin.click('#wp-submit')
  await admin.waitForLoadState('load')
  await admin.goto(`${BASE}/wp-admin/options-general.php?page=screenly-cast`, {
    waitUntil: 'load'
  })
  await admin.screenshot({ path: `${OUT}/screenshot-4.png` })
  await admin.close()
  console.log(`✓ ${OUT}/screenshot-4.png  (settings)`)

  await browser.close()
}

await main()
