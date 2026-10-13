import { computed, type ComputedRef } from 'vue'
import { usePage } from '@inertiajs/vue3'

/**
 * Where "Register Your Business" should actually go.
 *
 * `/register` is guest-only (`Route::middleware('guest')` in routes/auth.php),
 * so a signed-in owner who clicks it is bounced to `/dashboard` — and, since
 * the platform is paid, on to the payment wall. The button appears on screens
 * only a signed-in user ever sees (the profile, the directory, the procurement
 * sticky bar), where it was therefore always a dead end.
 *
 * A signed-in user adding a business wants `sbo/listings/new`, which creates
 * another Sbo against the account they already have. That route exists for
 * exactly this reason — see SboWorkspaceController::createListing, which was
 * added when the same bug was fixed for the "New Listing" button.
 */
export function useRegisterUrl(): ComputedRef<string> {
  const page = usePage()

  return computed(() => (page.props.auth?.user ? '/sbo/listings/new' : '/register'))
}
