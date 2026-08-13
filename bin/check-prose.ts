#!/usr/bin/env bun
/**
 * Fail on typographic dashes in tracked files.
 *
 * The em dash is banned by house style, in prose and in comments alike. A rule
 * nothing enforces is only a preference, and preferences drift back: every one of
 * the hundred-odd this replaced was written by someone who meant well at the time.
 *
 * The en dash goes with it. There are none in the repository today, and that is
 * the point of checking, because a keyboard that produces one produces the other.
 *
 * Both have plain equivalents that always work: a comma, a colon, parentheses, or
 * two sentences. A hyphen surrounded by spaces is an em dash wearing a hat, and is
 * not what this asks for.
 *
 * The characters are written as escapes below so that this file does not trip its
 * own check, which is also why the message spells them out by name.
 *
 * Generated output is exempt, since it is not authored here. Fix the generator.
 */

const BANNED = [
  { char: '\u2014', name: 'em dash', instead: 'a comma, a colon, or two sentences' },
  { char: '\u2013', name: 'en dash', instead: 'a hyphen, or "to" in a range' }
]

/** Not authored in this repository; the generator or the dependency owns them. */
const EXEMPT = [/^screenly-cast\/assets\/dist\//, /^bun\.lock$/]

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

  text.split('\n').forEach((line, index) => {
    for (const banned of BANNED) {
      if (!line.includes(banned.char)) {
        continue
      }
      found += 1
      console.error(`${name}:${index + 1}: ${banned.name}, use ${banned.instead}`)
      console.error(`  ${line.trim()}`)
    }
  })
}

if (found > 0) {
  console.error(`\n${found} line(s) with a typographic dash.`)
  process.exit(1)
}

console.log(`No typographic dashes in ${files.length} tracked files.`)
