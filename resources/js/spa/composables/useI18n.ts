import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

/*
 * Interface translation.
 *
 * Deliberately not vue-i18n. The dictionary already arrives on every response
 * as an Inertia shared prop (see HandleInertiaRequests), so a second library
 * would mean a second copy of the same strings, its own loading lifecycle and a
 * window where the two disagree. What is actually needed is a lookup with
 * placeholder substitution, which is this file.
 *
 * Keys are namespaced strings ("directory.empty.title"). A missing key renders
 * its FALLBACK — the English text, since the server merges English under the
 * active locale — and only renders the raw key if English is missing it too,
 * which makes an untranslated string obvious in development without ever
 * showing a reader a dotted key in production.
 */

type Translations = Record<string, string>

export type LocaleOption = {
  code: string
  name: string
  native: string
  flag: string
}

export function useI18n() {
  const page = usePage()

  const i18n = computed(
    () =>
      (page.props.i18n ?? {
        locale: 'en',
        fallback: 'en',
        locales: [],
        translations: {},
      }) as {
        locale: string
        fallback: string
        locales: LocaleOption[]
        translations: Translations
      },
  )

  /**
   * Turn an unresolved key into something readable.
   *
   * `profile_dashboard.quick_portal_actions` -> `Quick portal actions`.
   *
   * A key that reaches this function is a bug — every key the app calls should
   * exist in en.json, and the server merges English under the active locale so
   * an untranslated string still renders. But when it does happen, a reader
   * should see prose rather than a dotted slug: the failure stays visible to
   * whoever is testing without looking like the page is broken.
   */
  function humanise(key: string): string {
    const tail = key.split('.').pop() ?? key
    const words = tail.replace(/_/g, ' ').trim()

    return words.charAt(0).toUpperCase() + words.slice(1)
  }

  /**
   * Translate `key`, substituting `:name`-style placeholders.
   *
   * `t('profile.highlights.subtitle', { name: 'Ben Logistics Ltd' })`
   */
  function t(key: string, replacements: Record<string, string | number | null | undefined> = {}): string {
    const line = i18n.value.translations[key]

    if (line === undefined) {
      if (import.meta.env.DEV) {
        console.warn(`[i18n] missing key: ${key}`)
      }

      return humanise(key)
    }

    return Object.entries(replacements).reduce(
      (carry, [token, value]) => carry.replaceAll(`:${token}`, value == null ? '' : String(value)),
      line,
    )
  }

  /**
   * Translate a line that changes with a count.
   *
   * `tc('sbo_network.businesses', 3)` -> "3 entreprises".
   *
   * Reads Laravel's own pipe-separated plural form, because the dictionary is
   * Laravel's and PHP already renders these same keys through trans_choice —
   * two formats for one file would guarantee the two drift.
   *
   *   "no bids|:count bid|:count bids"   zero, one, many
   *   ":count bid|:count bids"           one, many
   *
   * French and Spanish inflect the noun AND often the verb, which is why the
   * whole sentence lives in the file rather than a count being glued onto a
   * translated noun at the call site.
   */
  function tc(key: string, count: number, replacements: Record<string, string | number | null | undefined> = {}): string {
    const line = i18n.value.translations[key]

    if (line === undefined) {
      if (import.meta.env.DEV) {
        console.warn(`[i18n] missing key: ${key}`)
      }

      return `${count} ${humanise(key)}`
    }

    const form = chooseForm(line, count)

    return Object.entries({ count, ...replacements }).reduce(
      (carry, [token, value]) => carry.replaceAll(`:${token}`, value == null ? '' : String(value)),
      form,
    )
  }

  /**
   * Label a stored value without changing it.
   *
   * `td(sbo.category, 'category')` renders "Librairies et éditeurs" while
   * `sbo.category` stays "Bookstores & Publishers" — which is what the filter
   * matches on, what `?category=` carries, and what the row actually says.
   *
   * A value with no translation renders as it arrived. The registry gains
   * categories over time and an English chip is a missing translation; a blank
   * one, or a dotted key, would be a broken page. This is the same lookup as
   * DataLabels::get() on the server, keyed identically.
   *
   * Countries are the exception: their names come back already localised, so
   * they are never passed through here.
   */
  function td(value: string | null | undefined, group: string): string {
    if (!value) {
      return ''
    }

    return i18n.value.translations[`data.${group}.${slugify(value)}`] ?? value
  }

  const locale = computed(() => i18n.value.locale)
  const locales = computed(() => i18n.value.locales)
  const currentLocale = computed(
    () => locales.value.find((l) => l.code === locale.value) ?? locales.value[0] ?? null,
  )

  return { t, tc, td, locale, locales, currentLocale }
}

/**
 * Pick the plural form Laravel would have picked.
 *
 * Laravel's own syntax, because these lines are read by PHP too:
 *
 *   "{0} No bids|{1} :count bid|[2,*] :count bids"   explicit ranges
 *   ":count bid|:count bids"                          singular, plural
 *
 * A three-form line WITHOUT ranges is not a three-form line to Laravel — it
 * falls back to the two-form rule and shows the singular for 3 — so the ranges
 * are mandatory and this reads them rather than guessing by position.
 */
function chooseForm(line: string, count: number): string {
  const forms = line.split('|')

  for (const form of forms) {
    const exact = /^\{(\d+)\}\s?/.exec(form)
    if (exact && Number(exact[1]) === count) {
      return form.slice(exact[0].length)
    }

    const range = /^\[(\d+),(\d+|\*)\]\s?/.exec(form)
    if (range) {
      const low = Number(range[1])
      const high = range[2] === '*' ? Infinity : Number(range[2])
      if (count >= low && count <= high) {
        return form.slice(range[0].length)
      }
    }
  }

  // No range matched: the plain two-form rule, with any leftover prefix
  // stripped so a partly-ranged line never leaks "{1}" to a reader.
  const plain = forms.map((f) => f.replace(/^(?:\{\d+\}|\[\d+,(?:\d+|\*)\])\s?/, ''))

  return count === 1 ? plain[0] : (plain[1] ?? plain[0])
}

/**
 * `Restaurants & Cafés` -> `restaurants_cafes`.
 *
 * Must agree exactly with DataLabels::slug() in PHP, including the accent
 * folding: the seed writes "Restaurants & Cafés" and the footer writes
 * "Restaurants & Cafes", and they have to reach the same key.
 */
function slugify(value: string): string {
  return value
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[^A-Za-z0-9]+/g, '_')
    .toLowerCase()
    .replace(/^_+|_+$/g, '')
}
