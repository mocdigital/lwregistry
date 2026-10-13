/**
 * The CSRF header for a hand-rolled `fetch`.
 *
 * Inertia's own requests carry this themselves — axios reads the XSRF-TOKEN
 * cookie on every call. A raw `fetch` does not, so anything posting outside
 * Inertia (the chat translator, the sales assistant, the payment methods) has
 * to add it.
 *
 * Read from the cookie, not the `<meta>` tag. The meta tag is stamped into the
 * HTML once, at page load; the cookie is refreshed on every response. On a
 * long-lived SPA the two drift apart the moment a session rotates or reaches
 * its lifetime and a new one is issued — and a fetch reading the frozen meta
 * token then 419s while every Inertia form on the same page keeps working,
 * because those read the current cookie. That was exactly the "everything but
 * translation" CSRF mismatch.
 *
 * Sent as `X-XSRF-TOKEN` — the cookie is the encrypted token Laravel decrypts.
 * Deliberately NOT also sending the meta value as `X-CSRF-TOKEN`: Laravel
 * checks that header first and, if present but stale, fails on it without ever
 * looking at the cookie. The meta tag is the fallback only when no cookie
 * exists at all.
 */
export function csrfHeaders(): Record<string, string> {
  const cookie = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)
  if (cookie) return { 'X-XSRF-TOKEN': decodeURIComponent(cookie[1]) }

  const meta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content
  return meta ? { 'X-CSRF-TOKEN': meta } : {}
}
