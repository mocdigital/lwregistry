/*
 * `sbos.website` is free text — "example.com", "www.example.com" and
 * "https://example.com" all occur. Without a scheme the browser resolves the
 * href against the current origin, so a "Visit Website" link quietly goes to
 * /business/example.com instead of leaving the site.
 *
 * Anything that is not plausibly a host is dropped rather than linked blindly,
 * which is what lets a caller disable the button instead of offering a link
 * that goes nowhere.
 *
 * Lived in DirectoryView; the business profile needs the same treatment for the
 * same column, so it is shared rather than copied.
 */
export function normalizeUrl(raw: string | null | undefined): string | null {
  const value = raw?.trim()
  if (!value) return null

  const withScheme = /^https?:\/\//i.test(value) ? value : `https://${value}`

  try {
    const url = new URL(withScheme)
    return url.hostname.includes('.') ? url.toString() : null
  } catch {
    return null
  }
}
