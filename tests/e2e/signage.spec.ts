import { mkdir, readFile } from 'node:fs/promises'
import { type Page, expect, test } from '@playwright/test'
import { BASELINE_RESOLUTIONS, FIXTURES, RESOLUTIONS, STRESS_KEYS } from './fixtures'
import type { SeededFixture } from './seed'

const ARTIFACTS = 'tests/e2e/.artifacts'
const RENDERS = `${ARTIFACTS}/renders`

let seeded: SeededFixture[] = []

test.beforeAll(async () => {
  await mkdir(RENDERS, { recursive: true })

  try {
    seeded = JSON.parse(await readFile(`${ARTIFACTS}/fixtures.json`, 'utf8')) as SeededFixture[]
  } catch {
    throw new Error('No fixture manifest. Run `bun run env:seed` first.')
  }
})

function urlFor(key: string): string {
  const match = seeded.find((fixture) => fixture.key === key)
  if (!match) {
    throw new Error(`Fixture "${key}" was not seeded`)
  }
  // The manifest holds pretty permalinks, so `?srly` appends cleanly.
  return `${match.url}?srly`
}

type Diagnostics = {
  externalRequests: string[]
  consoleErrors: string[]
}

/**
 * Open a signage render and wait for the client-side fitter to settle.
 *
 * Waits on the fitter's own marker rather than a timeout, so the assertions below
 * measure a finished layout on a fast or a slow machine alike.
 */
async function openSignage(page: Page, key: string): Promise<Diagnostics> {
  const diagnostics: Diagnostics = { externalRequests: [], consoleErrors: [] }

  page.on('request', (request) => {
    const host = new URL(request.url()).host
    // Anything not served by the site under test is an external request, which a
    // WordPress.org plugin must not make and an offline player cannot reach.
    if (!request.url().startsWith('data:') && !host.startsWith('localhost')) {
      diagnostics.externalRequests.push(request.url())
    }
  })

  page.on('console', (message) => {
    if (message.type() === 'error') {
      diagnostics.consoleErrors.push(message.text())
    }
  })

  const response = await page.goto(urlFor(key), { waitUntil: 'load' })
  expect(response?.status(), `${key} should render with 200`).toBe(200)

  await page.evaluate(() => document.fonts.ready)
  await page.waitForSelector('[data-srly-fitted]', { timeout: 10_000 })

  /*
   * Let the entrance animation finish before anything is measured. The fitter
   * itself is transform-immune (it uses offsetHeight), but scrollHeight is not, so
   * assertions taken mid-animation would report an overflow that does not exist.
   * Waiting on the animations rather than sleeping keeps this deterministic.
   */
  await page.evaluate(() =>
    Promise.all(document.getAnimations().map((animation) => animation.finished))
  )
  await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(() => resolve(null))))

  return diagnostics
}

/**
 * Assertions that must hold for every fixture at every resolution.
 */
async function assertSignageInvariants(page: Page, key: string, diagnostics: Diagnostics) {
  /*
   * A screen cannot scroll and nobody is there to try, so the composition must
   * fit. Measured with offsetHeight for the same reason the fitter uses it: the
   * entry animates in from a translateY, and scrollHeight includes transforms, so
   * a scrollHeight assertion would fail purely because it ran mid-animation.
   */
  const overflow = await page.evaluate(() => {
    const stage = document.querySelector('.srly-stage')
    const entry = document.querySelector('.srly-entry')
    if (!(stage instanceof HTMLElement) || !(entry instanceof HTMLElement)) {
      return null
    }
    return {
      entryHeight: entry.offsetHeight,
      stageHeight: stage.clientHeight,
      entryWidth: entry.offsetWidth,
      stageWidth: stage.clientWidth,
      documentOverflowsX:
        document.documentElement.scrollWidth > document.documentElement.clientWidth,
      documentOverflowsY:
        document.documentElement.scrollHeight > document.documentElement.clientHeight
    }
  })

  expect(overflow, `${key}: stage and entry should exist`).not.toBeNull()
  if (overflow) {
    expect(
      overflow.entryHeight,
      `${key}: the entry is taller than the screen (${overflow.entryHeight} > ${overflow.stageHeight})`
    ).toBeLessThanOrEqual(overflow.stageHeight + 2)

    expect(overflow.entryWidth, `${key}: the entry is wider than the screen`).toBeLessThanOrEqual(
      overflow.stageWidth + 2
    )

    expect(overflow.documentOverflowsX, `${key}: the document scrolls horizontally`).toBe(false)
    expect(overflow.documentOverflowsY, `${key}: the document scrolls vertically`).toBe(false)
  }

  // Exactly one h1: the entry's title.
  await expect(page.locator('h1'), `${key}: should have exactly one h1`).toHaveCount(1)

  /*
   * Nothing may paint over the headline.
   *
   * The scrim is a ::after on the stage, and a pseudo-element hit-tests as its
   * originating element — so if the scrim ends up above the content, the topmost
   * element at the title's centre becomes .srly-stage instead of the title. That
   * is precisely the regression this catches: the text was still the right colour
   * and the right size, and simply invisible underneath a gradient.
   */
  const topmost = await page.evaluate(() => {
    const title = document.querySelector('.srly-entry__title')
    if (!(title instanceof HTMLElement)) {
      return null
    }
    const box = title.getBoundingClientRect()
    const hit = document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2)
    return hit
      ? { tag: hit.tagName, className: hit.className, inEntry: !!hit.closest('.srly-entry') }
      : null
  })

  expect(topmost, `${key}: the title should be hit-testable`).not.toBeNull()
  expect(
    topmost?.inEntry,
    `${key}: something paints over the headline (topmost element is ${topmost?.tag}.${topmost?.className})`
  ).toBe(true)

  // No interaction exists, so no links should survive anywhere in the document.
  await expect(page.locator('a'), `${key}: links must be flattened to text`).toHaveCount(0)

  // The theme must play no part in the render.
  const stylesheets = await page.evaluate(() =>
    Array.from(document.querySelectorAll('link[rel="stylesheet"]')).map(
      (link) => (link as HTMLLinkElement).href
    )
  )
  expect(
    stylesheets.some((href) => href.includes('screenly-cast')),
    `${key}: signage CSS`
  ).toBe(true)
  expect(
    stylesheets.filter((href) => href.includes('/themes/')),
    `${key}: no theme stylesheets`
  ).toHaveLength(0)

  // Every asset is local: WordPress.org forbids undisclosed external requests and
  // signage players are frequently offline.
  expect(diagnostics.externalRequests, `${key}: made external requests`).toEqual([])

  expect(diagnostics.consoleErrors, `${key}: console errors`).toEqual([])

  // The design's own webfonts must actually be in use.
  const fontLoaded = await page.evaluate(() => document.fonts.check('1rem "Bricolage Grotesque"'))
  expect(fontLoaded, `${key}: display webfont should be loaded`).toBe(true)

  // Exactly one title tier, so the fitter's decision is unambiguous.
  const tiers = await page.evaluate(() =>
    Array.from(document.documentElement.classList).filter((name) => name.startsWith('srly--title-'))
  )
  expect(tiers, `${key}: exactly one title tier`).toHaveLength(1)
}

async function shoot(page: Page, key: string, resolution: string): Promise<void> {
  await page.screenshot({ path: `${RENDERS}/${key}__${resolution}.png` })
}

for (const fixture of FIXTURES) {
  const resolutions = STRESS_KEYS.includes(fixture.key) ? RESOLUTIONS : BASELINE_RESOLUTIONS

  for (const resolution of resolutions) {
    test(`${fixture.key} @ ${resolution.key}`, async ({ page }) => {
      await page.setViewportSize({ width: resolution.width, height: resolution.height })

      const diagnostics = await openSignage(page, fixture.key)
      await assertSignageInvariants(page, fixture.key, diagnostics)
      await shoot(page, fixture.key, resolution.key)
    })
  }
}

test.describe('content shaping', () => {
  test('scripts and remote embeds leave no trace', async ({ page }) => {
    await page.setViewportSize({ width: 1920, height: 1080 })
    await openSignage(page, 'links-and-embeds')

    // The script body must not have executed, nor been printed as text.
    const ran = await page.evaluate(() => 'SHOULD_NOT_RUN' in window)
    expect(ran, 'the inline script must not run').toBe(false)

    const text = (await page.locator('.srly-entry__content').innerText()).trim()
    expect(text).not.toContain('SHOULD_NOT_RUN')
    expect(text).toContain('the full schedule')

    await expect(page.locator('iframe')).toHaveCount(0)
    await expect(page.locator('.srly-entry__content img')).toHaveCount(0)
  })

  test('a page shows the site name where a post shows its date', async ({ page }) => {
    await page.setViewportSize({ width: 1920, height: 1080 })

    await openSignage(page, 'page')
    const pageEyebrow = await page.locator('.srly-entry__eyebrow').innerText()

    await openSignage(page, 'text-only-post')
    const postEyebrow = await page.locator('.srly-entry__eyebrow').innerText()

    // A page has no meaningful publication date, so it names its source instead.
    expect(pageEyebrow.trim()).not.toEqual(postEyebrow.trim())
    await expect(page.locator('time.srly-entry__eyebrow')).toHaveCount(1)
  })

  test('a long post is clamped rather than shown in full', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 720 })
    await openSignage(page, 'long-content')

    const text = await page.locator('.srly-entry__content').innerText()

    // Four paragraphs of filler went in; a screenful comes out.
    expect(text.length).toBeLessThan(700)
    expect(text, 'the tail of a long post must not reach the screen').not.toContain(
      'TAIL_MARKER_MUST_NOT_RENDER'
    )

    const blocks = await page.locator('.srly-entry__content > *').count()
    expect(blocks, 'fewer blocks should survive than were authored').toBeLessThan(4)
  })

  test('multi-byte text and emoji are not mangled', async ({ page }) => {
    await page.setViewportSize({ width: 1920, height: 1080 })
    await openSignage(page, 'unicode')

    const title = await page.locator('h1').innerText()
    const body = await page.locator('.srly-entry__content').innerText()

    expect(title).toContain('Åpningstider')
    expect(title).toContain('営業時間')
    expect(body).toContain('🎉')
    // Mojibake signature of a UTF-8 string decoded as ISO-8859-1.
    expect(body).not.toContain('Ã')
  })

  test('a post with no body still composes', async ({ page }) => {
    await page.setViewportSize({ width: 1920, height: 1080 })
    await openSignage(page, 'empty-content')

    await expect(page.locator('h1')).toHaveText('Back soon')
    // An empty content block would occupy a line in a layout with no room to spare.
    const blocks = await page.locator('.srly-entry__content > *').count()
    expect(blocks).toBe(0)
  })

  test('right-to-left layout mirrors, via logical properties', async ({ page }) => {
    await page.setViewportSize({ width: 1920, height: 1080 })
    await openSignage(page, 'photo-post')

    const ltr = await page.locator('.srly-logo').boundingBox()

    await page.evaluate(() => {
      document.documentElement.setAttribute('dir', 'rtl')
    })
    await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(() => resolve(null))))

    const rtl = await page.locator('.srly-logo').boundingBox()

    expect(ltr, 'logo should be present').not.toBeNull()
    expect(rtl, 'logo should be present in RTL').not.toBeNull()
    if (ltr && rtl) {
      // The stylesheet uses inset-inline-end, so the logo must swap sides.
      expect(rtl.x).toBeLessThan(ltr.x)
    }
  })
})

test.describe('the site itself', () => {
  test('a signage request never changes the active theme', async ({ page }) => {
    // The regression that motivated the rewrite. Rendered here through a real
    // browser, in addition to the PHPUnit coverage.
    const themeOf = async (): Promise<string> => {
      const response = await page.goto('/', { waitUntil: 'domcontentloaded' })
      const html = (await response?.text()) ?? ''
      return html.match(/wp-content\/themes\/([^/]+)\//)?.[1] ?? 'unknown'
    }

    const before = await themeOf()
    expect(before).not.toBe('unknown')

    await openSignage(page, 'photo-post')

    const after = await themeOf()
    expect(after, 'the active theme changed during a signage request').toBe(before)
  })

  test('an ordinary request still renders the theme', async ({ page }) => {
    const target = seeded.find((fixture) => fixture.key === 'photo-post')
    expect(target).toBeDefined()

    const response = await page.goto(target?.url ?? '/', { waitUntil: 'load' })
    expect(response?.status()).toBe(200)

    // No signage markup on a normal page view.
    await expect(page.locator('.srly-stage')).toHaveCount(0)
  })
})
