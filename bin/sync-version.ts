#!/usr/bin/env bun
/**
 * Propagate the single source of truth for the plugin version.
 *
 * `package.json` owns the version (CalVer, `YYYY.M.MICRO`, matching signage-kit).
 * Everything else is derived:
 *
 *   - the `Version:` plugin header, which is what WordPress shows and compares
 *   - the `SRLY_VERSION` constant, used to cache-bust enqueued assets
 *   - `readme.txt`'s `Stable tag:`, which WordPress.org treats as the release
 *
 * Run with `--check` to verify without writing; CI uses that to refuse a release
 * whose git tag, plugin header and stable tag disagree.
 *
 * The constant matters more than it looks. It previously shipped as the literal
 * string `VERSION_PLACEHOLDER` — nothing ever substituted it — so every release
 * enqueued its CSS and JS under the same version and signage players happily
 * served stale assets after an update.
 */

import { readFileSync, writeFileSync } from 'node:fs'
import { dirname, join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'

const ROOT = dirname(dirname(fileURLToPath(import.meta.url)))
const PLUGIN_FILE = join(ROOT, 'screenly-cast/screenly-cast.php')
const README_FILE = join(ROOT, 'screenly-cast/readme.txt')
const PACKAGE_FILE = join(ROOT, 'package.json')

/** CalVer: four-digit year, unpadded month, micro. */
const CALVER = /^\d{4}\.(?:[1-9]|1[0-2])\.\d+$/

type Rule = {
  file: string
  label: string
  pattern: RegExp
  replace: (version: string) => string
}

const RULES: Rule[] = [
  {
    file: PLUGIN_FILE,
    label: 'plugin header Version',
    pattern: /^(\s*\*\s*Version:\s*)(.+)$/m,
    replace: (v) => `$1${v}`
  },
  {
    file: PLUGIN_FILE,
    label: 'SRLY_VERSION constant',
    pattern: /^(\s*define\(\s*'SRLY_VERSION',\s*')([^']*)('\s*\);)$/m,
    replace: (v) => `$1${v}$3`
  },
  {
    file: README_FILE,
    label: 'readme.txt Stable tag',
    pattern: /^(Stable tag:\s*)(.+)$/m,
    replace: (v) => `$1${v}`
  }
]

function readVersion(): string {
  const pkg = JSON.parse(readFileSync(PACKAGE_FILE, 'utf8')) as { version?: unknown }
  const version = pkg.version
  if (typeof version !== 'string' || version === '') {
    throw new Error('package.json has no "version"')
  }
  if (!CALVER.test(version)) {
    throw new Error(
      `Version "${version}" is not CalVer (YYYY.M.MICRO, unpadded month), e.g. 2026.8.0`
    )
  }
  return version
}

function main(): number {
  const check = process.argv.includes('--check')
  const version = readVersion()
  const problems: string[] = []
  const updated: string[] = []

  // Group by file so a file with two rules is read and written once.
  const byFile = new Map<string, Rule[]>()
  for (const rule of RULES) {
    const list = byFile.get(rule.file)
    if (list) {
      list.push(rule)
    } else {
      byFile.set(rule.file, [rule])
    }
  }

  for (const [file, rules] of byFile) {
    const shortName = relative(ROOT, file)
    let contents: string
    try {
      contents = readFileSync(file, 'utf8')
    } catch {
      problems.push(`${shortName}: cannot be read`)
      continue
    }

    let next = contents
    for (const rule of rules) {
      const match = rule.pattern.exec(next)
      if (!match) {
        problems.push(`${shortName}: no ${rule.label} found`)
        continue
      }
      const current = match[2]
      if (current === version) {
        continue
      }
      if (check) {
        problems.push(`${shortName}: ${rule.label} is "${current}", expected "${version}"`)
        continue
      }
      next = next.replace(rule.pattern, rule.replace(version))
      updated.push(`${shortName}: ${rule.label} ${current} -> ${version}`)
    }

    if (!check && next !== contents) {
      writeFileSync(file, next)
    }
  }

  if (problems.length > 0) {
    for (const problem of problems) {
      console.error(`✗ ${problem}`)
    }
    if (check) {
      console.error(`\nRun \`bun run version:sync\` to write version ${version}.`)
    }
    return 1
  }

  if (updated.length === 0) {
    console.log(`Version ${version} is already in sync.`)
  } else {
    for (const line of updated) {
      console.log(`✓ ${line}`)
    }
  }
  return 0
}

process.exit(main())
