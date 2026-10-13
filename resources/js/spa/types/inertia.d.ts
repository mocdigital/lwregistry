/*
 * The shape of what `HandleInertiaRequests::share()` sends on every response.
 *
 * Augmenting `@inertiajs/core` rather than casting at each call site: the
 * layouts and the header all read `page.props.auth`, and before this they each
 * reached into an untyped `{}` and had to cast to get anything out of it.
 */
import '@inertiajs/core'

declare module '@inertiajs/core' {
  interface PageProps {
    app: {
      /** Request base path, e.g. `/lwregistry/public` under MAMP or `` at root. */
      basePath: string
    }
    auth: {
      user: {
        name: string
        email: string
        initials: string
      } | null
    }
    /**
     * Reverb connection details, or null when no key is configured — the
     * normal state in local development, where messaging falls back to a
     * plain reload on send.
     */
    reverb: {
      key: string
      wsHost: string
      wsPort: number
      wssPort: number
      forceTLS: boolean
    } | null
    /**
     * Interface language: the active locale, the selector's options, and the
     * dictionary for that locale merged over English. See useI18n().
     */
    i18n: {
      locale: string
      fallback: string
      locales: { code: string; name: string; native: string; flag: string }[]
      translations: Record<string, string>
    }
    /**
     * The live ad for every placement slot, keyed by slot name. Null where no
     * ad is running — the <AdSlot> then renders nothing. See AdService.
     */
    ads: Record<
      string,
      {
        id: number
        title: string
        image: string
        mobileImage: string | null
        clickUrl: string
        seenUrl: string
        width: number
        height: number
        mobileWidth: number | null
        mobileHeight: number | null
      } | null
    >
    /** One-off flash messages set by `->with(...)` on a redirect. */
    flash: {
      status: string | null
      /** Set by the fee and Espees controllers; rendered on the fee screens. */
      feesSuccess: string | null
      feesError: string | null
      /**
       * Recovery codes, flashed once by TwoFactorSettingsController on the
       * response that mints them. They are stored encrypted and never shown
       * again, so this is the only render that can display them.
       */
      recoveryCodes: string[] | null
      /** The advertising inquiry's confirmation, and the prompt a guest gets. */
      advertisingInquiry: string | null
      advertisingAuthMessage: string | null
      /**
       * The Espees checkout URL for a submitted campaign, flashed by
       * AdCampaignController::submit for the SPA to navigate to — the proxy
       * strips the header Inertia::location's 409 would carry.
       */
      espeesRedirect: string | null
      /** Confirmation from POST /subscribe. */
      subscribed: string | null
    }
  }
}
