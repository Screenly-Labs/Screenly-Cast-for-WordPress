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

function applyTier(root: HTMLElement, tier: number): void {
  for (const name of TITLE_TIERS) {
    root.classList.remove(name)
  }
  const name = TITLE_TIERS[tier]
  if (name !== undefined) {
    root.classList.add(name)
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
  const entry = document.querySelector('.srly-entry')
  const title = document.querySelector('.srly-entry__title')

  if (!(entry instanceof HTMLElement)) {
    return
  }

  const titleLength = title instanceof HTMLElement ? (title.textContent ?? '').trim().length : 0

  let tier = tierForLength(titleLength)
  applyTier(root, tier)

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

  // Step the title down before touching the author's words.
  while (overflows() && tier < TITLE_TIERS.length - 1) {
    tier += 1
    applyTier(root, tier)
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
