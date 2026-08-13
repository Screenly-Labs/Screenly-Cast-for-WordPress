/**
 * Fits a signage render to the screen it is actually on.
 *
 * Two jobs. First, pick a title tier from the title's own length, then step it
 * down while the composition still overflows. Second, trim body content that does
 * not fit — because a screen cannot scroll and nobody is there to try.
 *
 * The server already clamps content to a character budget, but characters are a
 * proxy: only the browser knows what fits at this resolution, in this orientation,
 * with these fonts loaded. This is the precise pass.
 *
 * Replaces the previous theme's scripts.js, which walked the content one word at a
 * time and forced a layout on every iteration while comparing against a
 * getBoundingClientRect() captured *before* the mutations — so its measurements
 * went stale as it worked. This measures after each step and binary-searches, so a
 * 400-word paragraph costs ~9 reflows rather than 400.
 */

import '@screenly-labs/signage-kit/polyfills'

const TITLE_TIERS = [
  'srly--title-xl',
  'srly--title-lg',
  'srly--title-md',
  'srly--title-sm'
] as const

/** Title length thresholds, in characters, for each tier above. */
const TIER_THRESHOLDS = [18, 38, 70] as const

const ELLIPSIS = '…'

/** Overflow tolerance in pixels, to absorb sub-pixel layout rounding. */
const SLACK = 1

function tierForLength(length: number): number {
  for (let index = 0; index < TIER_THRESHOLDS.length; index++) {
    const threshold = TIER_THRESHOLDS[index]
    if (threshold !== undefined && length <= threshold) {
      return index
    }
  }
  return TITLE_TIERS.length - 1
}

/**
 * Put the tier class on the element that already carries the composition classes.
 *
 * It must be <body>, not <html>. The stylesheet has rules like
 * `.srly--text-only.srly--title-xl`, and `srly--text-only` is emitted on <body> by
 * body_class(). With the tier on <html> no single element ever carried both, so
 * every one of those compound rules silently matched nothing and text-only renders
 * quietly fell back to the generic tier sizes.
 */
function applyTier(target: HTMLElement, tier: number): void {
  for (const name of TITLE_TIERS) {
    target.classList.remove(name)
  }
  const name = TITLE_TIERS[tier]
  if (name !== undefined) {
    target.classList.add(name)
  }
}

/**
 * Trim a block's words to the most that fit, by binary search.
 *
 * Returns false when even a single word overflows, in which case the caller drops
 * the block entirely rather than leaving a one-word orphan.
 */
function trimBlock(block: HTMLElement, overflows: () => boolean): boolean {
  const original = (block.textContent ?? '').trim()
  const words = original.split(/\s+/).filter((word) => word.length > 0)

  if (words.length === 0) {
    return false
  }

  let low = 0
  let high = words.length

  while (low < high) {
    const mid = Math.ceil((low + high) / 2)
    block.textContent = `${words.slice(0, mid).join(' ')}${ELLIPSIS}`

    if (overflows()) {
      high = mid - 1
    } else {
      low = mid
    }
  }

  if (low === 0) {
    return false
  }

  block.textContent =
    low === words.length ? original : `${words.slice(0, low).join(' ')}${ELLIPSIS}`

  return true
}

/**
 * Drop and trim content until it fits.
 *
 * @param content   The content block.
 * @param overflows Whether the composition currently overflows.
 */
function fitContent(content: HTMLElement, overflows: () => boolean): void {
  if (!overflows()) {
    return
  }

  // Remove whole trailing blocks first: dropping a paragraph reads better than
  // truncating several.
  while (content.children.length > 1 && overflows()) {
    content.lastElementChild?.remove()
  }

  if (!overflows()) {
    return
  }

  const last = content.lastElementChild
  if (last instanceof HTMLElement && !trimBlock(last, overflows)) {
    last.remove()
  }
}

function fit(): void {
  const root = document.documentElement
  const body = document.body
  const entry = document.querySelector('.srly-entry')
  const title = document.querySelector('.srly-entry__title')

  if (!(entry instanceof HTMLElement) || !body) {
    return
  }

  const titleLength = title instanceof HTMLElement ? (title.textContent ?? '').trim().length : 0

  let tier = tierForLength(titleLength)
  applyTier(body, tier)

  /*
   * Two deliberate choices here, each of which was a bug first.
   *
   * offsetHeight, not scrollHeight or a bounding rect: offsetHeight is a layout
   * measurement and ignores transforms. The entry animates in from
   * translateY(1.1rem), so a transform-aware reading taken while that animation
   * runs reports the composition as taller than it is — and the fitter responded
   * by deleting body copy that fitted perfectly well.
   *
   * The viewport as the reference, not the stage: the stage is a grid container
   * sized to the viewport, but measuring a child against a parent that stretches
   * to fit that child is circular and always answers "it fits". documentElement's
   * clientHeight is the one height that does not move.
   */
  const available = (): number => document.documentElement.clientHeight
  const overflows = (): boolean => entry.offsetHeight > available() + SLACK

  /*
   * Width is a separate question from height, and it has to be asked of the title
   * itself.
   *
   * A tier is chosen from the title's character count, which knows nothing about
   * the aspect ratio of the screen. On a portrait player the fluid root is driven
   * by the long edge, so a 1080x1920 screen sets 31.5px — and a single word at the
   * largest tier is then 283px tall and over 1100px wide on a 1080px-wide screen.
   * It does not fit and it never could. The entry is a shrink-to-fit grid item, and
   * shrink-to-fit floors at min-content — no min-width or max-width changes that —
   * so the composition simply grew past the viewport and the stage's overflow:hidden
   * clipped the headline. Silently: the document itself never scrolled, so nothing
   * about the page looked wrong from the outside.
   *
   * Shrinking the type is therefore the only real remedy, which makes this the
   * fitter's job rather than the stylesheet's.
   *
   * Compared against the viewport, not against the title's own box: the entry grows
   * to fit the title, so the title is never overflowing its own container and
   * scrollWidth on it always reads clean. The overflow only exists relative to the
   * screen.
   */
  const tooWide = (): boolean => entry.offsetWidth > document.documentElement.clientWidth + SLACK

  // Step the title down before touching the author's words.
  while ((overflows() || tooWide()) && tier < TITLE_TIERS.length - 1) {
    tier += 1
    applyTier(body, tier)
  }

  /*
   * Last resort, and only once the tiers are exhausted: let the word break.
   *
   * A 40-character unbroken word does not fit on a narrow screen at any tier, and
   * of the two remaining outcomes — a broken word or a clipped one — the broken one
   * is legible. Deliberately not in the base stylesheet: with break-word always on,
   * the loop above would never see an overflow to correct, so every over-wide title
   * would be hyphen-free mid-word garbage instead of being made smaller first.
   *
   * Note this is checked, not merged into the loop condition, because `overflows()`
   * below drives content trimming. A permanently-true width predicate there would
   * have the fitter delete every paragraph in the post trying to fix the title.
   */
  if (tooWide() && title instanceof HTMLElement) {
    title.classList.add('srly-entry__title--break')
  }

  const content = document.querySelector('[data-srly-fit]')
  if (content instanceof HTMLElement) {
    fitContent(content, overflows)
  }

  /*
   * Mark the document once a pass has completed, and count the passes. The
   * browser tests wait on this rather than on a timeout, so a slow machine cannot
   * make them flaky.
   */
  const passes = Number.parseInt(root.getAttribute('data-srly-fitted') ?? '0', 10)
  root.setAttribute('data-srly-fitted', String((Number.isNaN(passes) ? 0 : passes) + 1))
}

function scheduleFit(): void {
  requestAnimationFrame(() => fit())
}

function start(): void {
  scheduleFit()

  // Metrics change once the webfonts land, so measure again then. Guarded because
  // document.fonts is absent on some below-floor players.
  if (document.fonts?.ready) {
    document.fonts.ready.then(scheduleFit).catch(() => {
      /* Measuring with fallback metrics is better than not measuring. */
    })
  }

  // Players are rotated and re-provisioned in place, so react to resize. Content
  // already trimmed cannot come back, but the title tier can still be corrected.
  let resizeTimer: number | undefined
  window.addEventListener('resize', () => {
    window.clearTimeout(resizeTimer)
    resizeTimer = window.setTimeout(scheduleFit, 150)
  })
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', start)
} else {
  start()
}
