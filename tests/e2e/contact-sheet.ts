/**
 * Builds a contact sheet from the renders the browser tests produced.
 *
 * The assertions prove the renders are structurally correct; they cannot say
 * whether the design *looks* right. This is the artifact a human actually opens:
 * every fixture at every resolution it was tested at, on one page, with a note on
 * what each case is probing.
 *
 * Runs as Playwright's global teardown, so it is built whether or not the
 * assertions passed, a failing run is exactly when you want to look at the pixels.
 */

import { readdir, writeFile } from 'node:fs/promises'
import { FIXTURES } from './fixtures'

const ARTIFACTS = 'tests/e2e/.artifacts'
const RENDERS = `${ARTIFACTS}/renders`
const OUTPUT = `${ARTIFACTS}/contact-sheet.html`

type Render = {
  file: string
  fixtureKey: string
  resolution: string
}

function escapeHtml(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
}

async function collectRenders(): Promise<Render[]> {
  let files: string[]
  try {
    files = await readdir(RENDERS)
  } catch {
    return []
  }

  return files
    .filter((file) => file.endsWith('.png'))
    .map((file) => {
      const [fixtureKey = 'unknown', resolution = 'unknown'] = file
        .replace(/\.png$/, '')
        .split('__')
      return { file, fixtureKey, resolution }
    })
    .sort((a, b) => a.file.localeCompare(b.file))
}

function renderPage(renders: Render[]): string {
  const byFixture = new Map<string, Render[]>()
  for (const render of renders) {
    const list = byFixture.get(render.fixtureKey)
    if (list) {
      list.push(render)
    } else {
      byFixture.set(render.fixtureKey, [render])
    }
  }

  const sections = FIXTURES.filter((fixture) => byFixture.has(fixture.key))
    .map((fixture) => {
      const shots = byFixture.get(fixture.key) ?? []
      const cards = shots
        .map(
          (shot) => `
        <figure class="shot">
          <img src="renders/${encodeURIComponent(shot.file)}" alt="${escapeHtml(fixture.key)} at ${escapeHtml(shot.resolution)}" loading="lazy" />
          <figcaption>${escapeHtml(shot.resolution)}</figcaption>
        </figure>`
        )
        .join('')

      return `
      <section>
        <h2>${escapeHtml(fixture.title)} <code>${escapeHtml(fixture.key)}</code></h2>
        <p class="probes">${escapeHtml(fixture.probes)}</p>
        <div class="shots">${cards}</div>
      </section>`
    })
    .join('')

  const orphans = renders.filter(
    (render) => !FIXTURES.some((fixture) => fixture.key === render.fixtureKey)
  )

  return `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Screenly Cast: signage renders</title>
<style>
  :root { color-scheme: dark; }
  body {
    margin: 0; padding: 2.5rem clamp(1rem, 4vw, 4rem);
    background: #0b0d12; color: #f4f1ec;
    font: 16px/1.5 ui-sans-serif, system-ui, sans-serif;
  }
  h1 { font-size: 1.6rem; margin: 0 0 .3em; letter-spacing: -.02em; }
  .meta { color: #949aa6; margin: 0 0 2.5rem; }
  section { margin: 0 0 3rem; border-top: 1px solid rgb(255 255 255 / .12); padding-top: 1.4rem; }
  h2 { font-size: 1.1rem; margin: 0 0 .35em; font-weight: 600; }
  h2 code { color: #6b4eff; font-size: .85em; font-weight: 400; }
  .probes { color: #949aa6; margin: 0 0 1.2rem; max-width: 70ch; }
  .shots { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1.2rem; }
  figure { margin: 0; }
  img {
    display: block; width: 100%; height: auto;
    background: #05070a; border: 1px solid rgb(255 255 255 / .12);
  }
  figcaption { color: #949aa6; font-size: .82rem; margin-top: .45em; font-variant-numeric: tabular-nums; }
</style>
</head>
<body>
<h1>Screenly Cast: signage renders</h1>
<p class="meta">${renders.length} render(s) across ${byFixture.size} fixture(s). Assertions cover overflow, link flattening, external requests, webfont loading and the title tier; this sheet is for judging the design.</p>
${sections}
${orphans.length > 0 ? `<section><h2>Unrecognised renders</h2><p class="probes">${orphans.map((o) => escapeHtml(o.file)).join(', ')}</p></section>` : ''}
</body>
</html>
`
}

async function main(): Promise<void> {
  const renders = await collectRenders()

  if (renders.length === 0) {
    console.log('No renders found; skipping the contact sheet.')
    return
  }

  await writeFile(OUTPUT, renderPage(renders))
  console.log(`\nContact sheet: ${OUTPUT} (${renders.length} renders)`)
}

export default main
