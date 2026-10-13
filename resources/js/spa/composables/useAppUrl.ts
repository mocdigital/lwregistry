import { usePage } from '@inertiajs/vue3'

function isExternalUrl(path: string): boolean {
  return /^(?:[a-z][a-z\d+\-.]*:)?\/\//i.test(path)
    || /^(?:mailto|tel|sms):/i.test(path)
    || path.startsWith('#')
}

/**
 * Prefix root-relative Laravel paths with the current mount point.
 *
 * Local MAMP serves this app at `/lwregistry/public`, while production may sit
 * at `/`. Keeping the prefix server-provided avoids hardcoding either shape.
 */
export function useAppUrl(): (path: string) => string {
  const page = usePage()

  return (path: string): string => {
    if (!path.startsWith('/') || isExternalUrl(path)) return path

    const basePath = String(page.props.app?.basePath ?? '').replace(/\/+$/, '')

    if (path === '/') return basePath || '/'

    return `${basePath}${path}`
  }
}
