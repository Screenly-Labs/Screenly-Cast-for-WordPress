#!/usr/bin/env bun
/**
 * Captures the WordPress.org listing screenshots from the running wp-env site.
 *
 * These are the images a prospective user judges the plugin by, and they were the
 * most out of date thing in the repository: the committed pair predated the
 * rewrite, so they advertised a design the plugin no longer has. Generating them
 * from the real site means they cannot drift again, re-run after a design change.
 *
 * The content is its own thing, seeded and removed around the capture, rather than
 * reused from the browser-test corpus. Test fixtures should stay deliberately
 * awkward (overlong titles, emoji, empty bodies) and none of that belongs in a
 * listing image. This is a small narrative instead: an office notice board, which
 * is the most common real use of the plugin.
 *
 * Prerequisites: `bun run env:start`.
 */

import { $ } from 'bun'
import { chromium, type Page } from '@playwright/test'

const BASE = process.env['SRLY_BASE_URL'] ?? 'http://localhost:8888'
const OUT = '.wordpress-org'
const SCRATCH = 'tests/e2e/.artifacts/screenshot-content'
const CONTAINER_ROOT = '/var/www/html/srly'

type Notice = {
  key: string
  title: string
  content: string
}

/**
 * The notice board.
 *
 * Written as the kind of thing that actually goes on a wall in an office: a status
 * line, a couple of house rules, one quotable, one instruction.
 */
const NOTICES: Notice[] = [
  {
    key: 'landscape',
    title: 'Anton is back online',
    content:
      '<h2>Server room</h2>' +
      '<ul>' +
      '<li>Anton: restored overnight, no data lost</li>' +
      '<li>Rack 2 stays powered. Ask before touching anything.</li>' +
      '</ul>' +
      '<blockquote>Compression is not a metaphor. It is the product.</blockquote>' +
      '<p>All-hands at four, in the <strong>incubator</strong>.</p>'
  },
  {
    key: 'portrait',
    title: 'Standup moved to 10:30',
    content:
      '<p>The whiteboard wall is being repainted, so standup is in the garage until Thursday.</p>' +
      '<ul><li>Demo build freezes at noon</li><li>Latency dashboard is on the big screen</li></ul>'
  }
]

async function wp(args: string[]): Promise<string> {
  const result = await $`bunx wp-env run cli wp ${args}`.quiet()
  return result.stdout
    .toString()
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => line !== '' && !/^[ℹ✔✖⚠]/.test(line))
    .join('\n')
    .trim()
}

async function createNotice(notice: Notice): Promise<{ id: number; url: string }> {
  const path = `${SCRATCH}/${notice.key}.html`
  await Bun.write(path, notice.content)

  const created = await wp([
    'post',
    'create',
    `${CONTAINER_ROOT}/${path}`,
    `--post_title=${notice.title}`,
    '--post_status=publish',
    '--porcelain'
  ])
  const id = Number.parseInt(created, 10)
  if (!Number.isInteger(id) || id <= 0) {
    throw new Error(`Could not create the "${notice.key}" notice: ${created}`)
  }

  const url = await wp(['eval', `echo get_permalink(${id});`])
  return { id, url }
}

/** Wait for the fitter to settle and the entrance animation to finish. */
async function settle(page: Page): Promise<void> {
  await page.evaluate(() => document.fonts.ready)
  await page.waitForSelector('[data-srly-fitted]', { timeout: 15_000 })
  await page.evaluate(() =>
    Promise.all(document.getAnimations().map((animation) => animation.finished))
  )
}

/**
 * The site name shown in both images.
 *
 * Without this the listing images carry the wp-env directory name, which reads as
 * a bug rather than as a site. Deliberately an invented company rather than one of
 * the show's: putting somebody else's fictional trademarks on a public directory
 * listing is a different thing from using them in a local fixture.
 */
const SITE_NAME = 'Pipeline Labs'
const SITE_TAGLINE = 'Fourth floor, above the loading bay'

async function main(): Promise<void> {
  const landscape = NOTICES[0]
  const portrait = NOTICES[1]
  if (!landscape || !portrait) {
    throw new Error('Both notices must be defined.')
  }

  const created: number[] = []
  const originalName = await wp(['option', 'get', 'blogname'])
  const originalTagline = await wp(['option', 'get', 'blogdescription'])

  await wp(['option', 'update', 'blogname', SITE_NAME])
  await wp(['option', 'update', 'blogdescription', SITE_TAGLINE])

  const browser = await chromium.launch()

  try {
    const main = await createNotice(landscape)
    created.push(main.id)
    const second = await createNotice(portrait)
    created.push(second.id)

    // 1. The same post as an ordinary visitor sees it, theme and all. The pair is
    //    the whole argument, so it has to be the same post.
    const before = await browser.newPage({
      viewport: { width: 1920, height: 1080 },
      deviceScaleFactor: 1
    })
    await before.goto(main.url, { waitUntil: 'load' })
    await before.evaluate(() => document.fonts.ready)
    await before.screenshot({ path: `${OUT}/screenshot-1.png` })
    await before.close()
    console.log(`✓ ${OUT}/screenshot-1.png  without Screenly Cast`)

    // 2. The signage render, landscape.
    const after = await browser.newPage({
      viewport: { width: 1920, height: 1080 },
      deviceScaleFactor: 1
    })
    await after.goto(`${main.url}?srly`, { waitUntil: 'load' })
    await settle(after)
    await after.screenshot({ path: `${OUT}/screenshot-2.png` })
    await after.close()
    console.log(`✓ ${OUT}/screenshot-2.png  with Screenly Cast, landscape`)

    // 3. Portrait, because both orientations with no configuration is a large part
    //    of the point and is invisible in a landscape-only pair.
    const tall = await browser.newPage({
      viewport: { width: 1080, height: 1920 },
      deviceScaleFactor: 1
    })
    await tall.goto(`${second.url}?srly`, { waitUntil: 'load' })
    await settle(tall)
    await tall.screenshot({ path: `${OUT}/screenshot-3.png` })
    await tall.close()
    console.log(`✓ ${OUT}/screenshot-3.png  portrait`)

    // 4. The settings screen, so the listing shows how little there is to set up.
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
    console.log(`✓ ${OUT}/screenshot-4.png  settings`)
  } finally {
    await browser.close()

    // Leave the dev site as it was found.
    if (created.length > 0) {
      await $`bunx wp-env run cli wp post delete ${created.map(String)} --force`.quiet().nothrow()
    }
    await wp(['option', 'update', 'blogname', originalName])
    await wp(['option', 'update', 'blogdescription', originalTagline])
  }
}

await main()
