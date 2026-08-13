#!/usr/bin/env bun
/**
 * Render the WordPress.org readme.txt and changelog.txt.
 *
 * readme.txt is a build artifact, not a source file. It used to be edited by hand,
 * which meant its header block could disagree with the plugin header, its stable tag
 * could disagree with package.json, and its changelog could disagree with the
 * release that was actually published. All three had to be caught by a checker
 * because all three were possible. Generating the file removes the possibility.
 *
 * Three inputs:
 *
 *   readme/listing.md      the listing itself, in markdown: description, install,
 *                          FAQ, screenshots. The part a human writes.
 *   GitHub releases        the changelog, per CalVer release. The release notes are
 *                          the changelog; there is no second copy to keep in step.
 *   readme/history.md      pre-CalVer releases, frozen, because four of those tags
 *                          were never published as releases at all.
 *
 * Two outputs: screenly-cast/readme.txt, kept under WordPress.org's 10,000-byte
 * limit by taking only as many changelog entries as fit, and
 * screenly-cast/changelog.txt with the complete history.
 *
 * The ordering problem this creates is worth stating, because it drives the release
 * workflow's trigger. The changelog for version X lives in X's GitHub release, which
 * does not exist at the moment X's tag is pushed. So the WordPress.org deploy runs on
 * `release: published`, not on the tag push. Before the release is cut,
 * readme/next-release.md stands in for it — that file is where the notes are
 * reviewed, and it is what `gh release create --notes-file` publishes.
 */

import { existsSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'

const ROOT = dirname(dirname(fileURLToPath(import.meta.url)))
const LISTING_FILE = join(ROOT, 'readme/listing.md')
const HISTORY_FILE = join(ROOT, 'readme/history.md')
const NEXT_RELEASE_FILE = join(ROOT, 'readme/next-release.md')
const PACKAGE_FILE = join(ROOT, 'package.json')
const README_OUT = join(ROOT, 'screenly-cast/readme.txt')
const CHANGELOG_OUT = join(ROOT, 'screenly-cast/changelog.txt')

/**
 * WordPress.org's parser reads at most 10,000 bytes of readme.txt and silently drops
 * the rest, so overshooting truncates the listing mid-sentence with no error
 * anywhere. The budget leaves room for the pointer to changelog.txt.
 */
const README_LIMIT = 10_000

/**
 * What the generator will actually fill. The gap to the limit is deliberate slack: the
 * header block grows by a few bytes whenever the version or the tested-up-to figure
 * gains a digit, and that must never be what pushes the file over.
 */
const README_BUDGET = 9_850

/** WordPress.org shows the short description as the listing's subtitle. */
const SHORT_DESCRIPTION_LIMIT = 150

/** WordPress.org indexes at most five tags and ignores the rest. */
const MAX_TAGS = 5

/** CalVer only. Legacy `v1.x` tags come from history.md — see the note there. */
const CALVER_TAG = /^\d{4}\.(?:[1-9]|1[0-2])\.\d+$/

/** Marks the paragraph WordPress.org shows as the update prompt. */
const UPGRADE_NOTICE_MARKER = '<!-- upgrade-notice -->'

type WordPressMeta = {
  contributors: string[]
  tags: string[]
  requiresAtLeast: string
  testedUpTo: string
  requiresPHP: string
  licenseURI: string
}

type PackageJson = {
  version: string
  license: string
  repository: { url: string }
  wordpress: WordPressMeta
}

type Release = {
  tag_name: string
  body: string | null
  draft: boolean
  prerelease: boolean
}

/** One changelog entry, whatever it was sourced from. */
type Entry = {
  version: string
  body: string
}

function fail(message: string): never {
  console.error(`✗ ${message}`)
  process.exit(1)
}

function readPackage(): PackageJson {
  const pkg = JSON.parse(readFileSync(PACKAGE_FILE, 'utf8')) as Partial<PackageJson>
  const { version, license, repository, wordpress } = pkg

  if (typeof version !== 'string' || typeof license !== 'string') {
    fail('package.json needs both "version" and "license"')
  }
  if (!repository || typeof repository.url !== 'string') {
    fail('package.json needs "repository.url"')
  }
  if (!wordpress) {
    fail('package.json needs a "wordpress" block for the readme.txt header fields')
  }
  return { version, license, repository, wordpress }
}

/** `git+https://github.com/owner/repo.git` -> `owner/repo`. */
function repoSlug(url: string): string {
  const match = /github\.com[/:]([^/]+\/[^/.]+)/.exec(url)
  if (!match?.[1]) {
    fail(`Cannot read an owner/repo out of repository.url "${url}"`)
  }
  return match[1]
}

/**
 * Compare version strings numerically, segment by segment.
 *
 * A string sort puts 2026.8.10 before 2026.8.9, and a changelog in the wrong order is
 * the kind of thing nobody notices until a user does.
 */
function compareVersions(a: string, b: string): number {
  const left = a.split('.').map(Number)
  const right = b.split('.').map(Number)
  for (let index = 0; index < Math.max(left.length, right.length); index++) {
    const diff = (left[index] ?? 0) - (right[index] ?? 0)
    if (diff !== 0) {
      return diff
    }
  }
  return 0
}

/**
 * Fetch published releases through the gh CLI.
 *
 * gh rather than a bare fetch because it carries authentication in both places this
 * runs: a maintainer's machine, where it is already logged in, and Actions, where
 * GH_TOKEN is in the environment. An unauthenticated call would work until the repo
 * hit the anonymous rate limit and then fail only sometimes, which is worse.
 */
function fetchReleases(slug: string): Release[] {
  const result = Bun.spawnSync([
    'gh',
    'api',
    `repos/${slug}/releases`,
    '--paginate',
    '--jq',
    '.[] | {tag_name, body, draft, prerelease}'
  ])

  if (!result.success) {
    const stderr = new TextDecoder().decode(result.stderr).trim()
    fail(
      `gh could not list releases for ${slug}.\n  ${stderr}\n` +
        '  Pass --releases <file.json> to render from a saved response instead, or ' +
        '--no-releases to render without a changelog.'
    )
  }

  // --jq streams one object per line rather than an array.
  return new TextDecoder()
    .decode(result.stdout)
    .split('\n')
    .filter((line) => line.trim() !== '')
    .map((line) => JSON.parse(line) as Release)
}

function releasesFromFile(path: string): Release[] {
  const parsed = JSON.parse(readFileSync(path, 'utf8')) as unknown
  if (!Array.isArray(parsed)) {
    fail(`${path} should contain a JSON array of releases`)
  }
  return parsed as Release[]
}

/**
 * Turn a markdown fenced code block into the form WordPress.org renders as code: the
 * lines wrapped in backticks on their own lines. The language tag is dropped, since
 * the readme parser has nowhere to put it.
 */
function fencedBlock(lines: string[]): string[] {
  return ['`', ...lines, '`']
}

type ConvertOptions = {
  /** Section headings become `== X ==` at the top level, bold inside an entry. */
  nested: boolean
}

/**
 * Convert markdown to readme.txt markup.
 *
 * WordPress.org's readme format is *almost* markdown: bold, italic, links, lists and
 * inline code are all shared. Only headings and code blocks differ, so this converts
 * those two and passes everything else through — which is what makes the markdown
 * source readable on its own rather than a template full of markers.
 *
 * Tables and h4 have no readme.txt equivalent. Rather than dropping them silently,
 * which would lose content between a reviewed markdown diff and the deployed listing,
 * they are an error.
 */
function toReadmeMarkup(markdown: string, options: ConvertOptions): string {
  const out: string[] = []
  let fence: string[] | null = null

  /*
   * Reference-style links are collected and inlined, because readme.txt understands
   * only the inline form and would print `[GitHub Issues][issues]` verbatim.
   *
   * They are worth supporting rather than banning: a repository URL can be 76
   * characters on its own, and the markdown here is linted at 80 columns like every
   * other document in the repo. Without references, a long URL and the line-length
   * rule cannot both be satisfied.
   */
  const definitions = new Map<string, string>()
  for (const line of markdown.split('\n')) {
    const definition = /^\[([^\]]+)\]:\s*(\S+)\s*$/.exec(line)
    if (definition?.[1] && definition[2]) {
      definitions.set(definition[1], definition[2])
    }
  }

  for (const raw of markdown.split('\n')) {
    const line = raw
      .replace(/\s+$/, '')
      .replace(/\[([^\]]+)\]\[([^\]]+)\]/g, (whole, text: string, label: string) => {
        const url = definitions.get(label)
        if (!url) {
          fail(`Link reference [${label}] is used but never defined: ${whole}`)
        }
        return `[${text}](${url})`
      })

    // The definitions themselves carry no content once they have been inlined.
    if (/^\[[^\]]+\]:\s*\S+\s*$/.test(line)) {
      continue
    }

    if (line.startsWith('```')) {
      if (fence === null) {
        fence = []
      } else {
        out.push(...fencedBlock(fence))
        fence = null
      }
      continue
    }
    if (fence !== null) {
      fence.push(line)
      continue
    }

    if (line.startsWith('<!--')) {
      continue
    }
    if (line.startsWith('|')) {
      fail(`Tables are not supported by readme.txt, and this one would be lost: ${line}`)
    }

    const heading = /^(#{1,6})\s+(.*)$/.exec(line)
    if (heading?.[1] && heading[2] !== undefined) {
      const level = heading[1].length
      const text = heading[2]
      if (level >= 4) {
        fail(`readme.txt has no heading level ${level}: ${line}`)
      }
      if (options.nested && level === 1) {
        /*
         * The entry's own title, dropped rather than bolded. next-release.md needs an
         * h1 to be a valid markdown document, and that same file is what
         * `gh release create --notes-file` publishes — so the h1 arrives here whether
         * the notes came from the file or from the release. Either way the version
         * heading has already been emitted above it.
         */
        continue
      }
      if (options.nested) {
        // Inside a `= version =` entry, a real heading would end the entry.
        out.push(`**${text}**`)
      } else if (level === 2) {
        out.push(`== ${text} ==`)
      } else {
        out.push(`= ${text} =`)
      }
      continue
    }

    out.push(line)
  }

  if (fence !== null) {
    fail('An unclosed code fence would swallow the rest of the file')
  }

  return out.join('\n')
}

/** Collapse runs of blank lines and trim, so section spacing is uniform. */
function tidy(text: string): string {
  return text.replace(/\n{3,}/g, '\n\n').trim()
}

/**
 * Split the listing into its title, short description and body.
 *
 * The h1 is the plugin name and the paragraph under it is the short description,
 * which is how they read in the markdown too — no separate metadata to keep in step
 * with the prose.
 */
function parseListing(markdown: string): { name: string; short: string; body: string } {
  const lines = markdown.split('\n')
  const titleIndex = lines.findIndex((line) => line.startsWith('# '))
  if (titleIndex === -1) {
    fail('readme/listing.md needs an `# Plugin Name` heading')
  }

  const name = (lines[titleIndex] ?? '').slice(2).trim()
  const rest = lines.slice(titleIndex + 1)
  const bodyIndex = rest.findIndex((line) => line.startsWith('## '))
  if (bodyIndex === -1) {
    fail('readme/listing.md needs at least one `## Section`')
  }

  /*
   * Unwrapped to a single line. readme.txt takes the short description as the one line
   * following the header block, but the markdown it comes from is linted at 80
   * columns, so it is wrapped at source and joined here.
   */
  const paragraphs = tidy(rest.slice(0, bodyIndex).join('\n')).split(/\n\s*\n/)
  if (paragraphs.length > 1) {
    fail('The short description must be a single paragraph')
  }
  const short = (paragraphs[0] ?? '').split('\n').join(' ').trim()

  if (short === '') {
    fail('readme/listing.md needs a short description between the title and the first section')
  }
  if (Buffer.byteLength(short) > SHORT_DESCRIPTION_LIMIT) {
    fail(
      `The short description is ${Buffer.byteLength(short)} bytes; WordPress.org allows ` +
        `${SHORT_DESCRIPTION_LIMIT}`
    )
  }

  return {
    name,
    short,
    body: toReadmeMarkup(rest.slice(bodyIndex).join('\n'), { nested: false })
  }
}

/** Pull the changelog entries out of history.md's `## version` sections. */
function parseHistory(markdown: string): Entry[] {
  const entries: Entry[] = []
  let current: Entry | null = null

  for (const line of markdown.split('\n')) {
    const heading = /^##\s+(.+)$/.exec(line)
    if (heading?.[1]) {
      current = { version: heading[1].trim(), body: '' }
      entries.push(current)
      continue
    }
    if (current) {
      current.body += `${line}\n`
    }
  }

  if (entries.length === 0) {
    fail('readme/history.md has no `## version` sections')
  }

  return entries.map((entry) => ({
    version: entry.version,
    body: tidy(toReadmeMarkup(entry.body, { nested: true }))
  }))
}

/**
 * The paragraph WordPress.org shows when an update is available.
 *
 * Taken from the marker rather than guessed at from the first paragraph: an upgrade
 * notice says what a person has to know before updating, which is not the same thing
 * as the first line of the release notes, and a heuristic that is right most of the
 * time is worse here than one that is explicit.
 */
function splitNotes(markdown: string): { notice: string | null; body: string } {
  const index = markdown.indexOf(UPGRADE_NOTICE_MARKER)
  if (index === -1) {
    return { notice: null, body: markdown }
  }

  const before = markdown.slice(0, index)
  const after = markdown.slice(index + UPGRADE_NOTICE_MARKER.length).replace(/^[ \t]*\n/, '')
  const [paragraph = '', ...remainder] = after.split(/\n\s*\n/)

  /*
   * The notice is lifted out rather than merely located. It is shown in its own
   * section, so leaving it in the body would print the same paragraph twice on the
   * listing — once as the update prompt and again as the head of the changelog entry.
   */
  return {
    notice: tidy(paragraph) === '' ? null : tidy(paragraph),
    body: `${before}${remainder.join('\n\n')}`
  }
}

function renderEntries(entries: Entry[]): string[] {
  return entries.map((entry) => `= ${entry.version} =\n\n${entry.body}`)
}

/**
 * The lead of an entry: everything before its first section heading.
 *
 * readme.txt and changelog.txt want different lengths of the same thing. Release
 * notes for a substantial release run to several thousand bytes of Added/Fixed/Changed
 * detail, and the whole listing has 10,000 bytes to live in — so readme.txt takes the
 * summary the notes already open with and changelog.txt keeps the detail.
 *
 * This is why the lead paragraph of a release is worth writing properly: it is what
 * most people read, on the listing's Changelog tab.
 *
 * Entries with no sections at all — the pre-CalVer ones, which are flat bullet lists —
 * are already short, so they are their own lead.
 */
function leadOf(body: string): string {
  const lines = body.split('\n')
  const firstSection = lines.findIndex((line) => line.startsWith('**'))
  if (firstSection <= 0) {
    return body
  }
  return tidy(lines.slice(0, firstSection).join('\n'))
}

function main(): number {
  const args = process.argv.slice(2)
  const releasesFlag = args.indexOf('--releases')
  const noReleases = args.includes('--no-releases')

  const pkg = readPackage()
  const { wordpress } = pkg

  if (wordpress.tags.length > MAX_TAGS) {
    fail(`WordPress.org indexes ${MAX_TAGS} tags; package.json lists ${wordpress.tags.length}`)
  }

  const listing = parseListing(readFileSync(LISTING_FILE, 'utf8'))

  // Newest first, and only CalVer: everything older is in history.md.
  let releases: Release[] = []
  if (releasesFlag !== -1) {
    const path = args[releasesFlag + 1]
    if (!path) {
      fail('--releases needs a path to a JSON file')
    }
    releases = releasesFromFile(path)
  } else if (!noReleases) {
    releases = fetchReleases(repoSlug(pkg.repository.url))
  }

  const published = releases.filter(
    (release) => !release.draft && !release.prerelease && CALVER_TAG.test(release.tag_name)
  )

  const fromReleases: Entry[] = published
    .map((release) => {
      const { body } = splitNotes(release.body ?? '')
      return {
        version: release.tag_name,
        body: tidy(toReadmeMarkup(body, { nested: true }))
      }
    })
    .sort((a, b) => compareVersions(b.version, a.version))

  /*
   * The release being prepared has no GitHub release yet, so its notes come from
   * next-release.md — but only while that is true. Once the release is published its
   * body is authoritative, and taking both would list the version twice.
   */
  let notesSource = ''
  const entries = [...fromReleases]
  if (fromReleases.some((entry) => entry.version === pkg.version)) {
    notesSource = published.find((release) => release.tag_name === pkg.version)?.body ?? ''
  } else if (existsSync(NEXT_RELEASE_FILE)) {
    notesSource = readFileSync(NEXT_RELEASE_FILE, 'utf8')
    const body = tidy(toReadmeMarkup(splitNotes(notesSource).body, { nested: true }))
    if (body !== '') {
      entries.unshift({ version: pkg.version, body })
    }
  }

  entries.push(...parseHistory(readFileSync(HISTORY_FILE, 'utf8')))

  const header = [
    `=== ${listing.name} ===`,
    `Contributors: ${wordpress.contributors.join(', ')}`,
    `Tags: ${wordpress.tags.join(', ')}`,
    `Requires at least: ${wordpress.requiresAtLeast}`,
    `Tested up to: ${wordpress.testedUpTo}`,
    `Stable tag: ${pkg.version}`,
    `Requires PHP: ${wordpress.requiresPHP}`,
    `License: ${pkg.license}`,
    `License URI: ${wordpress.licenseURI}`,
    '',
    listing.short
  ].join('\n')

  const { notice } = splitNotes(notesSource)
  const sections = [header, listing.body]
  if (notice) {
    sections.push(`== Upgrade Notice ==\n\n= ${pkg.version} =\n\n${notice}`)
  }

  const staticPart = `${sections.join('\n\n')}\n`
  if (Buffer.byteLength(staticPart) > README_BUDGET) {
    fail(
      `The listing is ${Buffer.byteLength(staticPart)} bytes before any changelog, against a ` +
        `${README_BUDGET}-byte budget. Shorten readme/listing.md.`
    )
  }

  /*
   * Take changelog entries newest-first while they fit. WordPress.org's own guidance
   * is to keep readme.txt small and move the history to changelog.txt, which it links
   * automatically — so the truncation here is the recommended shape, not a
   * compromise. It is still stated in the output rather than left to be inferred.
   */
  const pointer = 'The full detail for every release is in changelog.txt.'
  const kept: string[] = []
  let used = Buffer.byteLength(`${staticPart}\n== Changelog ==\n\n${pointer}\n`)

  const summarised = entries.map((entry) => ({ version: entry.version, body: leadOf(entry.body) }))
  for (const rendered of renderEntries(summarised)) {
    const cost = Buffer.byteLength(`${rendered}\n\n`)
    if (used + cost > README_BUDGET) {
      break
    }
    kept.push(rendered)
    used += cost
  }

  if (kept.length === 0) {
    console.warn('⚠ No changelog entry fits in readme.txt; only the pointer will be written.')
  }

  const changelogSection = ['== Changelog ==', '', ...kept.flatMap((entry) => [entry, '']), pointer]
  const readme = `${staticPart}\n${changelogSection.join('\n')}\n`

  if (Buffer.byteLength(readme) > README_LIMIT) {
    fail(
      `readme.txt came out at ${Buffer.byteLength(readme)} bytes, over the ${README_LIMIT} limit`
    )
  }

  const changelog = [
    '== Changelog ==',
    '',
    'The current release is summarised in readme.txt. This file holds the full history,',
    "per WordPress.org's guidance to keep the readme small.",
    '',
    ...renderEntries(entries).flatMap((entry) => [entry, ''])
  ].join('\n')

  writeFileSync(README_OUT, readme)
  writeFileSync(CHANGELOG_OUT, `${tidy(changelog)}\n`)

  console.log(
    `✓ ${relative(ROOT, README_OUT)} — ${Buffer.byteLength(readme)} bytes, ` +
      `${kept.length} of ${entries.length} changelog entries`
  )
  console.log(`✓ ${relative(ROOT, CHANGELOG_OUT)} — ${entries.length} entries`)
  if (!notice) {
    console.warn(
      `⚠ No upgrade notice: add a "${UPGRADE_NOTICE_MARKER}" line to the notes for ${pkg.version}.`
    )
  }
  return 0
}

process.exit(main())
