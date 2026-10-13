import { createApp, h, type DefineComponent } from 'vue'
import { createInertiaApp, router } from '@inertiajs/vue3'
import {
  applyAppearance,
  watchSystemTheme,
  type Appearance,
} from '@/composables/useAppearance'
import { auditOverflow } from '@/composables/useOverflowAudit'
import './spa/styles/main.css'

/*
 * Inertia entry for the Vue front end.
 *
 * This is a second Vite input alongside the existing `app.js`, which still
 * drives the Livewire/Volt admin. The two do not share a bundle; a route is
 * served by one or the other depending on whether its controller returns
 * `Inertia::render()` or `view()`. That lets the migration land screen by
 * screen instead of in one cut.
 *
 * Pages resolve from `spa/views`, so `Inertia::render('public/DirectoryView')`
 * maps to `resources/js/spa/views/public/DirectoryView.vue`.
 */

/*
 * Typed as DefineComponent rather than `unknown`: Inertia's `resolve` is
 * declared to return a component, and an `unknown` default does not satisfy it.
 * The glob is eager: false, so this is only a type assertion — nothing loads
 * until a page is actually visited.
 */
const pages = import.meta.glob<{ default: DefineComponent }>('./spa/views/**/*.vue')

createInertiaApp({
  resolve: (name) => {
    const path = `./spa/views/${name}.vue`
    const page = pages[path]
    if (!page) {
      throw new Error(
        `Inertia page "${name}" not found at ${path}. ` +
          `Available: ${Object.keys(pages).join(', ')}`,
      )
    }
    /*
     * Unwrapped to the component itself. Inertia accepts a module at runtime,
     * but its `ComponentResolver` type is declared as
     * `Promise<DefineComponent>`, not `Promise<{ default }>` — so returning the
     * raw module fails the typecheck while still working. `defineOptions({
     * layout })` lives on the component, so nothing is lost by unwrapping.
     */
    return page().then((module) => module.default)
  },
  setup({ el, App, props, plugin }) {
    /*
     * Appearance is applied before mount and again on every visit.
     *
     * The Blade shell already writes the attributes so the first paint is
     * correct, but an Inertia visit swaps props without reloading the document
     * — so a member changing their theme on /settings/appearance would
     * otherwise not see it until the next full page load.
     */
    let appearance = props.initialPage.props.appearance as Appearance | null | undefined

    applyAppearance(appearance)

    // Inertia hands the new page to the success listener, which is the only
    // reliable place to read props after a visit.
    router.on('success', (event) => {
      appearance = event.detail.page.props.appearance as Appearance | null | undefined
      applyAppearance(appearance)
    })

    watchSystemTheme(() => appearance?.theme ?? 'light')

    /*
     * Development only: name any box that pushes the page sideways, so a
     * layout that overflows at 320px is a console warning here rather than a
     * bug report from someone on a phone. Stripped from the production bundle.
     */
    if (import.meta.env.DEV) {
      const audit = () => requestAnimationFrame(() => void auditOverflow())

      router.on('success', audit)
      window.addEventListener('resize', audit)
      audit()
    }

    createApp({ render: () => h(App, props) })
      .use(plugin)
      .mount(el)
  },
  progress: { color: '#0d9488' },
})
