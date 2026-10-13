/*
 * A development-time guard against pages that scroll sideways.
 *
 * Horizontal overflow is reported by whoever is looking at the site on a phone,
 * which is a slow and unreliable way to find it: the symptom is a page that
 * "floats out of its container", and the cause is one box several levels down
 * that refuses to shrink. Nothing fails, nothing logs, and it only shows up at
 * widths nobody develops at.
 *
 * This walks the document after each visit and names the narrowest element that
 * is wider than the viewport — the narrowest, because every ancestor of an
 * overflowing box is also too wide, and the ancestor list is noise.
 *
 * Dev only. It is behind `import.meta.env.DEV`, so the whole function is
 * removed from the production bundle.
 *
 * The usual causes, in the order they turn up:
 *
 *   - a grid or flex item with no `min-w-0` (their default `min-width: auto`
 *     refuses to shrink below the content's own minimum)
 *   - a fixed `w-[Npx]` with no breakpoint prefix
 *   - `whitespace-nowrap` on text the registry did not write
 *   - a table without an `overflow-x-auto` wrapper
 */
export function auditOverflow(): void {
  if (!import.meta.env.DEV) return

  const limit = document.documentElement.clientWidth
  const offenders: { el: Element; width: number }[] = []

  for (const el of document.body.querySelectorAll<HTMLElement>('*')) {
    const box = el.getBoundingClientRect()

    // `right` rather than `width`: an element can be narrow and still pushed
    // past the edge by a margin or an absolute offset.
    if (box.width <= limit + 1 && box.right <= limit + 1) continue

    // Fixed and sticky chrome is positioned against the viewport and is
    // allowed to sit outside the document flow.
    const position = getComputedStyle(el).position
    if ('fixed' === position) continue

    offenders.push({ el, width: Math.round(Math.max(box.width, box.right)) })
  }

  if (!offenders.length) return

  // Only the deepest offenders: every ancestor of an overflowing box overflows
  // too, and reporting the chain buries the one element worth changing.
  const deepest = offenders.filter(
    ({ el }) => !offenders.some((other) => other.el !== el && el.contains(other.el)),
  )

  console.groupCollapsed(
    `[overflow] ${deepest.length} element(s) wider than the ${limit}px viewport`,
  )
  for (const { el, width } of deepest) {
    console.warn(`${width}px —`, el)
  }
  console.groupEnd()
}
