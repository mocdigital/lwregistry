/*
 * Appearance preferences, applied.
 *
 * /settings/appearance offers a theme, an accent, a font size, a layout density
 * and a rail position. They persist (users.preferences) but persisting a
 * preference and honouring it are different things — this is the second half.
 *
 * Everything is expressed as attributes on <html>, which the token layer reads
 * (resources/js/spa/styles/tokens.css). Nothing here knows about components,
 * so a screen added later inherits the member's settings without doing
 * anything.
 */

export type Appearance = {
  theme: string
  accent: string
  fontSize: string
  density: string
  railSide: string
  toggles?: Record<string, boolean>
}

/** What the frames draw before anyone changes anything. */
const DEFAULTS: Appearance = {
  theme: 'light',
  accent: '#0d9488',
  fontSize: 'Medium',
  density: 'comfortable',
  // Every built screen puts the rail on the right, so that is the real
  // default — whatever the settings label says.
  railSide: 'right',
  toggles: {},
}

/**
 * Darken a hex by a fixed amount, for the accent's hover state.
 *
 * A member picks one colour; a button still needs a second for hover, and
 * asking for two would be a worse settings screen than deriving one.
 */
function darken(hex: string, amount = 0.18): string {
  const value = hex.replace('#', '')
  if (value.length !== 6) return hex

  const channels = [0, 2, 4].map((i) => parseInt(value.slice(i, i + 2), 16))
  const shifted = channels.map((c) => Math.max(0, Math.round(c * (1 - amount))))

  return '#' + shifted.map((c) => c.toString(16).padStart(2, '0')).join('')
}

/**
 * Resolve 'system' against the OS setting.
 *
 * Read at apply time rather than stored, so a member on 'system' follows their
 * machine switching to dark in the evening without touching the app.
 */
function resolveTheme(theme: string): 'light' | 'dark' {
  if (theme === 'dark') return 'dark'
  if (theme === 'light') return 'light'

  return window.matchMedia?.('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
}

export function applyAppearance(preferences?: Partial<Appearance> | null): void {
  if (typeof document === 'undefined') return

  const settings: Appearance = { ...DEFAULTS, ...(preferences ?? {}) }
  const root = document.documentElement

  root.dataset.theme = resolveTheme(settings.theme)
  root.dataset.fontSize = settings.fontSize
  root.dataset.density = settings.density
  root.dataset.rail = settings.railSide

  // Accessibility switches from the same panel.
  const toggles = settings.toggles ?? {}
  root.dataset.reduceMotion = toggles.reduceMotion ? 'true' : 'false'
  root.dataset.highContrast = toggles.highContrast ? 'true' : 'false'

  root.style.setProperty('--color-accent', settings.accent)
  root.style.setProperty('--color-accent-hover', darken(settings.accent))
}

/**
 * Follow the OS while the member is on 'system'.
 *
 * Returns a teardown so a caller can stop listening; without it every Inertia
 * visit would add another listener.
 */
export function watchSystemTheme(getTheme: () => string): () => void {
  if (typeof window === 'undefined' || !window.matchMedia) return () => {}

  const query = window.matchMedia('(prefers-color-scheme: dark)')

  const onChange = () => {
    if (getTheme() === 'system') {
      document.documentElement.dataset.theme = resolveTheme('system')
    }
  }

  query.addEventListener('change', onChange)

  return () => query.removeEventListener('change', onChange)
}
