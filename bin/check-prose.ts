#!/usr/bin/env bun
/**
 * Fail on typographic dashes and British spelling in tracked files.
 *
 * Both rules are house style, and both apply to prose and comments alike. A rule
 * nothing enforces is only a preference, and preferences drift back: every one of
 * the hundred-odd dashes this replaced was written by someone who meant well at
 * the time.
 *
 * Dashes. The en dash goes with the em dash. There are no en dashes in the
 * repository today, and that is the point of checking, because a keyboard that
 * produces one produces the other. Both have plain equivalents that always work: a
 * comma, a colon, parentheses, or two sentences. A hyphen surrounded by spaces is
 * an em dash wearing a hat, and is not what this asks for.
 *
 * Spelling. US English, matching screenly.io. It is also what the platform expects:
 * WordPress core and WordPress.org are en_US, and this plugin ships untranslated
 * en_US strings, so a British spelling in the admin UI is off-convention twice over.
 * Only unambiguous pairs are listed. Words that are acceptable either way in US
 * English are not the checker's business.
 *
 * The dash characters are written as escapes so this file does not trip its own
 * check. The spelling list cannot be written that way and stay readable, so this
 * file exempts itself from that rule alone.
 *
 * Generated output is exempt, since it is not authored here. Fix the generator.
 * LICENSE is exempt because it is the AGPL verbatim, and altering licence text to
 * suit a style guide would be a good deal worse than a spelling inconsistency.
 */

const BANNED = [
  { char: '\u2014', name: 'em dash', instead: 'a comma, a colon, or two sentences' },
  { char: '\u2013', name: 'en dash', instead: 'a hyphen, or "to" in a range' }
]

/** British stem -> US stem. Stems, so the inflections are covered too. */
const BRITISH: [string, string][] = [
  ['recognis', 'recogniz'],
  ['sanitis', 'sanitiz'],
  ['serialis', 'serializ'],
  ['optimis', 'optimiz'],
  ['organis', 'organiz'],
  ['prioritis', 'prioritiz'],
  ['normalis', 'normaliz'],
  ['customis', 'customiz'],
  ['colour', 'color'],
  ['behaviour', 'behavior'],
  ['favour', 'favor'],
  ['centre', 'center'],
  ['licence', 'license']
]

/** Not authored in this repository; the generator or the dependency owns them. */
const EXEMPT = [/^screenly-cast\/assets\/dist\//, /^bun\.lock$/]

/** Exempt from the spelling rule only: see the note above. */
const SPELLING_EXEMPT = [/^bin\/check-prose\.ts$/, /^LICENSE$/]

const listed = Bun.spawnSync(['git', 'ls-files', '-z'])
if (!listed.success) {
  console.error('git ls-files failed; is this a git checkout?')
  process.exit(1)
}

const files = new TextDecoder()
  .decode(listed.stdout)
  .split('\0')
  .filter((name) => name !== '' && !EXEMPT.some((pattern) => pattern.test(name)))

let found = 0

for (const name of files) {
  let text: string
  try {
    text = await Bun.file(name).text()
  } catch {
    // Binary, or unreadable as text. Neither can hold prose.
    continue
  }

  const spellchecked = !SPELLING_EXEMPT.some((pattern) => pattern.test(name))

  text.split('\n').forEach((line, index) => {
    for (const banned of BANNED) {
      if (!line.includes(banned.char)) {
        continue
      }
      found += 1
      console.error(`${name}:${index + 1}: ${banned.name}, use ${banned.instead}`)
      console.error(`  ${line.trim()}`)
    }

    if (!spellchecked) {
      return
    }

    for (const [gb, us] of BRITISH) {
      if (!new RegExp(gb, 'i').test(line)) {
        continue
      }
      found += 1
      console.error(`${name}:${index + 1}: British spelling, use "${us}" not "${gb}"`)
      console.error(`  ${line.trim()}`)
    }
  })
}

if (found > 0) {
  console.error(`\n${found} line(s) to fix.`)
  process.exit(1)
}

console.log(`Prose is clean in ${files.length} tracked files: no typographic dashes, US spelling.`)
