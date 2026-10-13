/*
 * Two build-time checks for the things that pass typecheck and still break.
 *
 * 1. A template using a component it never imported.
 * 2. A `watch(..., { immediate: true })` whose getter reads a `const` declared
 *    further down the same script block.
 *
 * Fail the build when a template uses a component it never imported.
 *
 * Vue treats an unresolved PascalCase tag as a native element: it renders a
 * literal `<uiavatar src="…">` into the DOM, and nothing complains. The build
 * passes, vue-tsc passes, and the component silently is not there — which is
 * how the header's avatar shipped broken.
 *
 * vue-tsc's own `strictTemplates` does catch this, but it also rejects every
 * `aria-*` and `role` on Inertia's <Link>, which does not declare passthrough
 * attributes in its types. That is ~16 false positives against this one real
 * bug class, so this checks the one thing instead.
 */
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join } from 'node:path'

const ROOT = 'resources/js/spa'

/* Vue's own built-ins resolve without an import. */
const BUILTINS = new Set([
  'Transition',
  'TransitionGroup',
  'Teleport',
  'Suspense',
  'KeepAlive',
  'Component',
  'Slot',
])

function vueFiles(dir) {
  return readdirSync(dir).flatMap((entry) => {
    const path = join(dir, entry)
    if (statSync(path).isDirectory()) return vueFiles(path)
    return path.endsWith('.vue') ? [path] : []
  })
}

const problems = []

for (const file of vueFiles(ROOT)) {
  const source = readFileSync(file, 'utf8')
  const at = source.indexOf('<template>')
  if (at === -1) continue

  const script = source.slice(0, at)
  const template = source.slice(at)

  const used = new Set(
    [...template.matchAll(/<([A-Z][A-Za-z0-9]*)[\s/>]/g)].map((m) => m[1]),
  )

  for (const tag of [...used].sort()) {
    if (BUILTINS.has(tag)) continue
    // Imported, aliased on import, or declared in the script block.
    if (new RegExp(`\\b${tag}\\b`).test(script)) continue

    problems.push(`${file}: <${tag}> is used but never imported`)
  }
}

/*
 * An immediate watcher that reads a `const` declared below it.
 *
 * `immediate: true` runs the getter during setup, and a `const` is in its
 * temporal dead zone until its own line — so the getter throws a
 * ReferenceError and the component never mounts. Nothing catches this:
 * vue-tsc is happy (the binding exists, just not yet), the bundle builds, and
 * the page simply does not work. It shipped once, on the registration wizard,
 * where a country select stopped populating its states.
 *
 * Deliberately narrow. It looks only at watchers marked immediate, and only at
 * top-level `const` bindings in the same script block, so it has nothing to say
 * about the ordinary case of a function that happens to reference something
 * below it — that is fine, because the function runs later.
 */
for (const file of vueFiles(ROOT)) {
  const source = readFileSync(file, 'utf8')
  const script = source.slice(0, source.indexOf('<template>') + 1 || undefined)

  // Where each top-level `const` becomes usable.
  const declaredAt = new Map()
  for (const m of script.matchAll(/^const\s+([A-Za-z_$][\w$]*)/gm)) {
    if (!declaredAt.has(m[1])) declaredAt.set(m[1], m.index)
  }

  for (const call of script.matchAll(/\bwatch(?:Effect)?\s*\(/g)) {
    const start = call.index

    // The call's own text, to its closing `)` at depth zero.
    let depth = 0
    let end = start
    for (let i = script.indexOf('(', start); i < script.length; i++) {
      if (script[i] === '(') depth++
      else if (script[i] === ')' && --depth === 0) {
        end = i
        break
      }
    }

    const body = script.slice(start, end + 1)
    if (!/immediate\s*:\s*true/.test(body)) continue

    for (const [name, at] of declaredAt) {
      if (at <= start) continue
      if (!new RegExp(`\\b${name}\\b`).test(body)) continue

      problems.push(
        `${file}: immediate watcher reads \`${name}\`, which is declared below it — ` +
          'the getter runs during setup and will throw. Move the watcher below the declaration.',
      )
    }
  }
}

/*
 * ---------------------------------------------------------------------------
 * Mobile guards
 * ---------------------------------------------------------------------------
 *
 * Two patterns that look fine on the machine they were written on and break a
 * phone. Both were true across dozens of files before they were corrected, and
 * neither shows up in a test — only on a device somebody happens to own.
 */
for (const file of vueFiles(ROOT)) {
  const source = readFileSync(file, 'utf8')
  const template = source.slice(source.indexOf('<template>'))
  const where = file.replace(`${ROOT}/`, '')

  // Each class="..." as one unit, because whether a width is safe depends on
  // what else is on the same element.
  for (const attr of template.matchAll(/class="([^"]*)"/g)) {
    const classes = attr[1]
    const line = template.slice(0, attr.index).split('\n').length

    /*
     * A fixed width wider than the narrowest phone pushes the page sideways
     * and turns every screen into a horizontal scroller.
     *
     * Three things make one legitimate, and all three appear in this codebase:
     *
     *   a breakpoint prefix — it only applies on a screen wide enough for it
     *   `w-full` alongside  — the table pattern, `w-full min-w-[860px]` inside
     *                         an overflow-x-auto wrapper, which is how a wide
     *                         table is supposed to be done
     *   `hidden` alongside  — not rendered at the width where it would hurt
     */
    for (const m of classes.matchAll(/(?:^|\s)(?<!max-)(min-)?w-\[(\d{3,})px\]/g)) {
      if (Number(m[2]) < 360) continue
      if (/\bw-full\b/.test(classes)) continue
      if (/\bhidden\b/.test(classes)) continue

      problems.push(
        `${where}:${line}: \`${m[0].trim()}\` is wider than a 375px phone with no breakpoint ` +
          'prefix. Use `max-w-`, pair it with `w-full` inside an overflow-x-auto wrapper, or prefix it.',
      )
    }

    /*
     * 100vh on a phone includes the address bar, so a "full height" pane is
     * taller than the screen and its bottom — usually the composer or the
     * primary button — sits under the browser chrome. dvh is the same number
     * on a desktop and the correct one on a phone.
     *
     * Allowed behind a breakpoint, where there is no address bar to allow for.
     */
    for (const m of classes.matchAll(/(?:^|\s)([\w:.\-]*)\[?[^\s"]*100vh/g)) {
      if (/(sm|md|lg|xl|2xl):/.test(m[1])) continue

      problems.push(
        `${where}:${line}: 100vh with no breakpoint prefix. Use 100dvh — on a phone 100vh ` +
          'includes the address bar, so the bottom of the element is off screen.',
      )
    }
  }
}

if (problems.length) {
  console.error('Problems:\n' + problems.map((p) => `  ${p}`).join('\n'))
  process.exit(1)
}

console.log(`components ok (${vueFiles(ROOT).length} files)`)
