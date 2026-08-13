/**
 * The fixture corpus.
 *
 * Each entry is a case that has broken, or could plausibly break, a signage
 * render. They are defined here as data so the seeder and the spec agree on one
 * list, and so adding a case is a one-line change.
 */

export type Fixture = {
  /** Stable slug; also the screenshot filename. */
  key: string
  title: string
  /** Post type to create. `attachment` means "render the image's own page". */
  type: 'post' | 'page' | 'attachment'
  content: string
  /** Attach the sample image as a featured image. */
  featured?: boolean
  /** What this case is actually probing, shown on the contact sheet. */
  probes: string
}

const LOREM =
  'The rooftop terrace reopens for the season with seating for forty, a small ' +
  'bar, and the long view north over the goods yard. Service begins at noon and ' +
  'runs until dusk, weather allowing. Last orders are called thirty minutes ' +
  'before close, and the east stair is the only step-free route while the lift ' +
  'is being replaced. Staff can hold a table for groups of six or more if you ' +
  'ask at the counter on the way up. '

export const FIXTURES: Fixture[] = [
  {
    key: 'photo-post',
    title: 'The roof garden is open again',
    type: 'post',
    featured: true,
    content: '<p>Weekdays, noon till dusk. Come up via the east stair.</p>',
    probes: 'Featured image composition: full-bleed photo, scrim, text set low.'
  },
  {
    key: 'text-only-post',
    title: 'Closed Monday',
    type: 'post',
    content: '<p>Back open Tuesday at the usual time.</p>',
    probes: 'No image: the type becomes the image. Short title should hit the largest tier.'
  },
  {
    key: 'long-title',
    title:
      'The rooftop terrace and garden bar will reopen for the summer season on the first of June',
    type: 'post',
    content: '<p>Seating for forty. No booking needed.</p>',
    probes: 'Long title must step down a tier and still fit, never overflow.'
  },
  {
    key: 'long-content',
    title: 'Season opening',
    type: 'post',
    // The tail marker makes "was this actually cut?" assertable rather than
    // inferred from a length.
    content:
      `<p>${LOREM}</p><p>${LOREM}</p><p>${LOREM}</p>` +
      `<p>TAIL_MARKER_MUST_NOT_RENDER ${LOREM}</p>`,
    probes: 'Far past the character budget: server clamps, then the fitter trims to the viewport.'
  },
  {
    key: 'rich-markup',
    title: 'What changes this week',
    type: 'post',
    content:
      '<h2>Opening hours</h2><ul><li>Monday: closed</li><li>Tuesday to Sunday: noon till dusk</li></ul>' +
      '<blockquote>The lift is out of service until the end of the month.</blockquote>' +
      '<p>Use the <strong>east stair</strong> for step-free access.</p>',
    probes:
      'Headings, lists, blockquote and bold all styled rather than falling back to browser defaults.'
  },
  {
    key: 'links-and-embeds',
    title: 'Notice',
    type: 'post',
    content:
      '<p>See <a href="https://example.com/schedule">the full schedule</a> for details.</p>' +
      '<figure class="wp-block-image"><img src="https://example.com/remote.png" alt="remote"/></figure>' +
      '<script>window.SHOULD_NOT_RUN = true</script>' +
      '<iframe src="https://example.com/embed"></iframe>',
    probes:
      'Links flatten to text; remote image, script and iframe removed entirely. No external requests.'
  },
  {
    key: 'unicode',
    title: 'Åpningstider · 営業時間 · ساعات العمل',
    type: 'post',
    content: '<p>Café Åbo — 日本語のテキスト, emoji 🎉🎊, and naïve façade détails.</p>',
    probes: 'Multi-byte text and emoji must not be mangled by the clamp or the fitter.'
  },
  {
    key: 'page',
    title: 'Visit us',
    type: 'page',
    content: '<p>Third floor, above the bindery. The entrance is on Neal Street.</p>',
    probes: 'A page has no meaningful date, so the eyebrow shows the site name instead.'
  },
  {
    key: 'attachment',
    title: 'Terrace at dusk',
    type: 'attachment',
    content: '<p>Looking north over the goods yard.</p>',
    probes: 'Attachment page: the image is the subject rather than a backdrop.'
  },
  {
    key: 'empty-content',
    title: 'Back soon',
    type: 'post',
    content: '',
    probes:
      'Title alone, with no body. Must not leave an empty content block or collapse the layout.'
  }
]

/** The supported resolution matrix (signage-kit's stated contract). */
export const RESOLUTIONS = [
  { key: '4k-landscape', width: 3840, height: 2160 },
  { key: '4k-portrait', width: 2160, height: 3840 },
  { key: '1080p-landscape', width: 1920, height: 1080 },
  { key: '1080p-portrait', width: 1080, height: 1920 },
  { key: '720p-landscape', width: 1280, height: 720 },
  { key: '720p-portrait', width: 720, height: 1280 },
  { key: 'pi-touch-landscape', width: 800, height: 480 },
  { key: 'pi-touch-portrait', width: 480, height: 800 }
] as const

/**
 * Resolutions every fixture is checked at.
 *
 * The full matrix runs against the cases most likely to break under it (see
 * STRESS_KEYS) rather than against all ten fixtures, which would be eighty
 * renders for little added signal.
 */
export const BASELINE_RESOLUTIONS = RESOLUTIONS.filter(
  (resolution) => resolution.key === '1080p-landscape' || resolution.key === '1080p-portrait'
)

/** Fixtures worth running against every resolution in the matrix. */
export const STRESS_KEYS = ['long-content', 'long-title', 'photo-post']
