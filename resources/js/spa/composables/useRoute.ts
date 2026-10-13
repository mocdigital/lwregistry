import { computed, reactive } from 'vue'
import { usePage, router } from '@inertiajs/vue3'

/*
 * Migration shim for the screens that were written against vue-router.
 *
 * Inertia has no client router: the server owns the URL. These give the nine
 * screens that read `route.params.*` and the two that call `router.push()` the
 * same surface, reading from `usePage().url` instead.
 *
 * `params` is derived from URL segments, so it only knows positional tabs and
 * ids — which is all those screens use (`/settings/:tab`, `/procurement/:id`).
 * The honest fix is for the controller to pass the active tab as a prop; that
 * lands screen by screen as each one is wired. See frontend/INTEGRATION.md.
 */

export type ShimRoute = {
  path: string
  /** Path plus query string — the identity components watch to detect a visit. */
  fullPath: string
  params: Record<string, string>
  query: Record<string, string>
}

/** Segment names, positionally, for the routes that carry params. */
const PARAM_SHAPES: { test: RegExp; names: string[] }[] = [
  { test: /^\/settings/, names: ['tab'] },
  { test: /^\/application/, names: ['step'] },
  { test: /^\/sbo\/network/, names: ['tab'] },
  { test: /^\/register/, names: ['step'] },
  { test: /^\/procurement\/[^/]+\/bid/, names: ['id', 'bid', 'step'] },
  { test: /^\/procurement\/[^/]+/, names: ['id', 'tab'] },
  { test: /^\/business\/[^/]+/, names: ['slug', 'section'] },
]

function parse(url: string): ShimRoute {
  const [rawPath, rawQuery = ''] = url.split('?')
  const path = rawPath || '/'

  const query: Record<string, string> = {}
  new URLSearchParams(rawQuery).forEach((v, k) => {
    query[k] = v
  })

  const params: Record<string, string> = {}
  const shape = PARAM_SHAPES.find((s) => s.test.test(path))
  if (shape) {
    // Segments after the static prefix, in order.
    const segments = path.split('/').filter(Boolean)
    const prefixLength = segments.length - shape.names.length
    shape.names.forEach((name, i) => {
      const segment = segments[prefixLength + i]
      if (segment) params[name] = segment
    })
  }

  return { path, fullPath: url || '/', params, query }
}

export function useRoute(): ShimRoute {
  const page = usePage()
  const parsed = computed(() => parse(page.url))

  /*
   * Returned reactive rather than as a plain object: callers read
   * `route.params.tab` directly, and resolving `.value` once at setup would
   * freeze the route at whatever it was when the component mounted, so an
   * Inertia visit would leave every consumer showing the previous URL's tab.
   * `reactive` unwraps the refs, keeping the `route.path` call shape.
   */
  return reactive({
    path: computed(() => parsed.value.path),
    fullPath: computed(() => parsed.value.fullPath),
    params: computed(() => parsed.value.params),
    query: computed(() => parsed.value.query),
  }) as ShimRoute
}

/*
 * `preserveState` defaults on. Every caller here is moving within a screen —
 * a wizard step, a query-string panel — where a remount would throw away the
 * half-filled form that prompted the navigation. Inertia ignores the flag when
 * the page component actually changes, so this is safe for the rest.
 */
export function useRouter() {
  const defaults = { preserveState: true, preserveScroll: true }

  return {
    push: (to: string, options: Record<string, unknown> = {}) =>
      router.visit(to, { ...defaults, ...options }),
    replace: (to: string, options: Record<string, unknown> = {}) =>
      router.visit(to, { ...defaults, replace: true, ...options }),
    back: () => window.history.back(),
  }
}
