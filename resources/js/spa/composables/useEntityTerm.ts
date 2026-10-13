import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'

/*
 * The word a signed-in owner's workspace uses for their business: "SBO" for a
 * Strategic Business Organization, "COB" for a Citizen Owned Business.
 *
 * The label is per business: a page that is about one listing sends its own
 * `entityTerm` prop, and that wins. Off such a page — the persistent shell
 * chrome, a list — it falls back to the owner's workspace default carried on
 * `auth.user.entityTerm`, and to "SBO" for a guest or a user with no listing.
 *
 * Feed it into a translation as the `:entity` placeholder, e.g.
 *   t('sbo_progress.sbo_profile_details', { entity })
 * where the string reads ":entity Profile Details".
 */
export function useEntityTerm() {
  const page = usePage()

  const entityTerm = computed<string>(() => {
    const props = page.props as {
      entityTerm?: string | null
      auth?: { user?: { entityTerm?: string | null } | null } | null
    }

    return props.entityTerm || props.auth?.user?.entityTerm || 'SBO'
  })

  return { entityTerm }
}
