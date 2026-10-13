import { onBeforeUnmount, onMounted, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'

/*
 * The public site's scroll feel: sections rise in as they arrive.
 *
 * Progressive enhancement, deliberately. The DOM is left alone until this runs
 * — sections carry no hidden state in the markup — so if the script never
 * loads, or motion is turned off, the page is simply a normal, fully visible
 * page. Nothing here is load-bearing.
 *
 * It reads the DOM rather than asking every view to tag its sections: the whole
 * public site should feel the same without twenty files each opting in. It
 * targets the top-level <section> bands — the ones nested inside another
 * section are components within a band, not bands themselves.
 *
 * Entrance is an IntersectionObserver, which the browser services off the main
 * thread, and it is left off entirely for `prefers-reduced-motion`.
 */
export function useScrollEffects() {
  const page = usePage()

  const reduceMotion =
    typeof window !== 'undefined' &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches

  const desktop = () => window.matchMedia('(min-width: 64rem)').matches

  let sections: HTMLElement[] = []
  let revealObserver: IntersectionObserver | null = null

  /** The page's own bands: sections not sitting inside another section. */
  function collect(): HTMLElement[] {
    const main = document.querySelector('main')
    if (!main) return []

    return Array.from(main.querySelectorAll('section')).filter(
      (el): el is HTMLElement =>
        el instanceof HTMLElement && el.parentElement?.closest('section') == null,
    )
  }

  /*
   * A two-column row inside a section — a text column beside an image, the
   * about-page pattern. Found by looking for a grid or flex with exactly two
   * children sitting side by side, and read by measured position rather than
   * DOM order, because the page flips the order on alternate bands while the
   * left one is still, visually, on the left.
   *
   * Desktop only: below the breakpoint the two stack, and sliding a stacked
   * column in from off-screen would only drag the page sideways.
   */
  function splitColumns(section: HTMLElement): [HTMLElement, HTMLElement] | null {
    if (!desktop()) return null

    for (const el of section.querySelectorAll('div')) {
      const style = getComputedStyle(el)
      if (style.display !== 'grid' && style.display !== 'flex') continue

      const kids = Array.from(el.children).filter(
        (c): c is HTMLElement => c instanceof HTMLElement,
      )
      if (kids.length !== 2) continue

      const a = kids[0].getBoundingClientRect()
      const b = kids[1].getBoundingClientRect()

      // Side by side: on roughly the same line, clearly apart horizontally.
      const sameRow = Math.abs(a.top - b.top) < Math.max(a.height, b.height) * 0.5
      const apart = Math.abs(a.left - b.left) > 80

      if (sameRow && apart) {
        return a.left <= b.left ? [kids[0], kids[1]] : [kids[1], kids[0]]
      }
    }

    return null
  }

  function setupReveal() {
    revealObserver = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          if (entry.isIntersecting) {
            const el = entry.target
            const settling =
              el.classList.contains('reveal-split') || el.classList.contains('reveal-stagger')
            el.classList.add(settling ? 'is-in' : 'reveal-in')
            revealObserver?.unobserve(el)
          }
        }
      },
      // A little before it fully arrives, so it is settling in as it appears
      // rather than popping once it is already there.
      { threshold: 0.08, rootMargin: '0px 0px -8% 0px' },
    )

    /*
     * Section-level: the two-column bands slide in from their sides, everything
     * else rises. A section whose content is itself a stagger grid — the team —
     * is left alone here; the stagger pass below animates its cards.
     */
    for (const section of sections) {
      const columns = splitColumns(section)

      if (columns) {
        section.classList.add('reveal-split')
        columns[0].classList.add('reveal-from-left')
        columns[1].classList.add('reveal-from-right')
        revealObserver.observe(section)
      } else if (!section.querySelector('[data-stagger]')) {
        section.classList.add('reveal-init')
        revealObserver.observe(section)
      }
    }

    /*
     * Stagger pass: every [data-stagger] grid, wherever it is, has its children
     * rise in one after another as it arrives — independent of any slide the
     * band around it is doing, so a card can both glide in AND fill itself in.
     * `data-stagger="quick"` uses a tighter rhythm, for the small cards inside a
     * band as opposed to the large team portraits.
     */
    const main = document.querySelector('main')

    for (const container of Array.from(main?.querySelectorAll<HTMLElement>('[data-stagger]') ?? [])) {
      container.classList.add('reveal-stagger')
      if (container.dataset.stagger === 'quick') container.classList.add('reveal-stagger-quick')

      for (const child of Array.from(container.children)) {
        if (child instanceof HTMLElement) child.classList.add('reveal-up')
      }

      revealObserver.observe(container)
    }
  }

  function teardown() {
    revealObserver?.disconnect()
    revealObserver = null
  }

  function build() {
    teardown()
    // Let the new page's DOM settle before measuring it.
    requestAnimationFrame(() => {
      sections = collect()
      if (sections.length === 0) return

      setupReveal()
    })
  }

  onMounted(() => {
    if (reduceMotion) return
    build()
    // Rebuild when the page changes under the same layout — a new set of bands.
    watch(() => page.component, () => build())
  })

  onBeforeUnmount(teardown)
}
