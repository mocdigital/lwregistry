<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Sbo;
use App\Models\User;
use App\Support\AccountCompletion;
use App\Support\PhoneNumbers;
use App\Support\SboListingProfilePayload;
use App\Support\SecurityOverview;
use App\Support\Timezones;
use App\Support\TwoFactorAuth;
use App\Support\UserPreferences;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Settings, served from Vue.
 *
 * This replaces the Volt pages at settings/profile, settings/password and
 * settings/appearance. Those were WORKING forms, so the update actions are
 * carried over rather than dropped — swapping a functioning Livewire form for
 * a display-only Vue screen would have been a regression, not a migration.
 *
 * MIRRORS resources/views/livewire/settings/{profile,password}.blade.php. The
 * Volt files are left in place for reference but are no longer routed; delete
 * them once nothing else references the partials they pull in.
 *
 * The tab set is the Vue screen's: profile, appearance, security,
 * notifications. `security` is what Volt called `password`, and
 * `notifications` is new — it had no Volt page at all.
 */
final class SettingsController extends Controller
{
    public function __construct(private readonly TwoFactorAuth $twoFactor) {}

    public function show(Request $request, string $tab = 'profile'): InertiaResponse
    {
        $user = $request->user();

        /*
         * Read through the same presence guard the model uses: these columns
         * postdate plenty of the code that builds User instances, and strict
         * attribute mode throws on a bare read when they were not selected.
         */
        $attrs = $user->getAttributes();
        $pendingSecret = array_key_exists('two_factor_secret', $attrs) ? $user->two_factor_secret : null;
        $pendingSetup = filled($pendingSecret) && ! $user->hasTwoFactorEnabled();
        $recoveryCodes = (array_key_exists('two_factor_recovery_codes', $attrs)
            ? $user->two_factor_recovery_codes : null) ?? [];

        return Inertia::render('account/SettingsView', [
            'tab' => $tab,
            'profile' => [
                'firstname' => $user->firstname,
                'lastname' => $user->lastname,
                'email' => $user->email,
                'phone' => $user->phone,
                'emailVerified' => $user->hasVerifiedEmail(),
                /*
                 * The avatar block drew the initials "KJ" for everybody and its
                 * Upload / Remove buttons were bare <button>s, so there was no
                 * way to set a photo from this screen at all.
                 */
                'photoUrl' => $user->profilePhotoUrl(),
                'initials' => mb_strtoupper(
                    mb_substr((string) $user->firstname, 0, 1) . mb_substr((string) $user->lastname, 0, 1)
                ),
            ],
            // Appearance and Notifications stored nothing before this.
            'preferences' => UserPreferences::for($user),
            /*
             * The Timezone field was a text box reading "East Africa Time
             * (GMT+3)" that saved nothing. These are the zones it offers and
             * the one in force — which is the app's own until a member picks.
             */
            'timezones' => Timezones::options(),
            'activeTimezone' => Timezones::for($user),
            /*
             * Account Completion was a fixed 75% and four fixed steps — one of
             * which named "Kenafly", a company belonging to nobody using the
             * system. Real conditions now; see AccountCompletion.
             */
            'completion' => AccountCompletion::for($user),
            /*
             * "Linked Business Associations" showed "Kenafly LLC · MRN-2026-0001
             * · Nairobi, Kenya · Approved Partner" to every member. These are
             * the member's actual listings.
             */
            'linkedBusinesses' => $user->sbos()->orderBy('name')->get()
                ->map(fn (Sbo $s): array => [
                    'name' => $s->name,
                    'reference' => $s->moc_registration_number ?: $s->application_id,
                    'location' => mb_trim(implode(', ', array_filter([$s->city, $s->country]))) ?: null,
                    'status' => $s->isFullyVerified()
                        ? 'Verified'
                        : ('approved' === $s->verification_status ? 'Approved' : 'Pending'),
                    'verified' => $s->isFullyVerified(),
                    'href' => route('businesses.show', $s->slug),
                ])->all(),
            /*
             * The About tab hosts the same listing-profile editor as
             * /sbo/listings/{id}/edit, so a business can maintain its public
             * page from Settings without going via My Account.
             *
             * Only built for that tab. It costs three eager-loaded relations
             * and a media query, which the Appearance and Notifications tabs
             * have no use for.
             */
            'about' => 'about' === $tab ? $this->aboutTab($request) : null,
            /*
             * The Security Overview panel used to be three fixed strings —
             * including "Two-Factor Auth: ACTIVE" on accounts with no second
             * factor at all. Real values now; see SecurityOverview.
             */
            'security' => [
                'overview' => SecurityOverview::for($user),
                'twoFactor' => [
                    'enabled' => $user->hasTwoFactorEnabled(),
                    // A secret exists but was never confirmed: enrolment was
                    // started and abandoned. The panel offers to resume.
                    'pending' => $pendingSetup,
                    'recoveryCodesRemaining' => count($recoveryCodes),
                    /*
                     * The QR is rendered only while enrolling, and inline as an
                     * SVG — sending the secret to a third-party chart service
                     * to draw it would hand the second factor away.
                     */
                    'qrSvg' => $pendingSetup
                        ? $this->twoFactor->qrCodeSvg(
                            $this->twoFactor->provisioningUri($user, (string) $pendingSecret)
                        )
                        : null,
                    // Shown beside the QR for authenticators that cannot scan.
                    'secret' => $pendingSetup ? $pendingSecret : null,
                ],
            ],
        ]);
    }

    /**
     * Deactivate my own account.
     *
     * REVERSIBLE ON PURPOSE. `sbos.created_by` is nullOnDelete, so actually
     * deleting the user would not remove their listings — it would orphan
     * them: an approved listing would stay in the public directory with no
     * owner, and nobody could ever edit it again, because every write path
     * authorizes on created_by. Suspension is the mechanism the app already
     * has (login refuses it, EnsureUserIsNotSuspended blocks it, network and
     * procurement visibility exclude it), so deactivating sets that and leaves
     * the registry records intact for an admin to restore or reassign.
     *
     * Two gates, guarding two different mistakes:
     *   typing DELETE  — the accidental click
     *   the password   — someone else acting on an unattended session, which a
     *                    word visible on screen does nothing to stop
     */
    public function deactivate(Request $request): RedirectResponse
    {
        $request->validate([
            'confirmation' => ['required', 'string', Rule::in(['DELETE'])],
            'password' => ['required', 'string', 'current_password'],
        ], [
            'confirmation.in' => 'Type DELETE exactly to confirm.',
            'confirmation.required' => 'Type DELETE to confirm.',
            'password.current_password' => 'That password is not correct.',
        ]);

        $user = $request->user();

        $user->forceFill([
            'suspended_at' => now(),
            // Self-service, so there is no admin to record. `suspended_by`
            // stays null, which is how a review can tell the two apart.
            'suspended_by' => null,
        ])->save();

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/')->with('status', 'account-deactivated');
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'firstname' => ['required', 'string', 'max:255'],
            'lastname' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'lowercase', 'email', 'max:255',
                Rule::unique(User::class)->ignore($user->id),
            ],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        $validated['phone'] = $this->normaliseProfilePhone($user, $validated['phone'] ?? null);

        $user->fill($validated);

        // Changing the address invalidates the previous verification.
        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        return back()->with('status', 'profile-updated');
    }

    /**
     * Upload a profile photo.
     *
     * 2MB and JPG/PNG, which is what the panel tells the member. The previous
     * file is deleted rather than orphaned on disk.
     */
    public function updatePhoto(Request $request): RedirectResponse
    {
        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
        ]);

        $user = $request->user();
        $previous = $user->getAttributes()['profile_photo_path'] ?? null;

        $user->profile_photo_path = $request->file('photo')->store('profile-photos', 'public');
        $user->save();

        if ($previous) {
            Storage::disk('public')->delete($previous);
        }

        return back()->with('status', 'photo-updated');
    }

    /** Remove it again, falling the display back to initials. */
    public function deletePhoto(Request $request): RedirectResponse
    {
        $user = $request->user();
        $path = $user->getAttributes()['profile_photo_path'] ?? null;

        if ($path) {
            Storage::disk('public')->delete($path);
        }

        $user->profile_photo_path = null;
        $user->save();

        return back()->with('status', 'photo-removed');
    }

    /**
     * Appearance and notification preferences.
     *
     * Both panels were client-side state that reset on reload. Each saves its
     * own half, so changing a theme does not resend every notification switch.
     */
    public function updatePreferences(Request $request): RedirectResponse
    {
        UserPreferences::merge($request->user(), $request->validate(UserPreferences::rules()));

        return back()->with('status', 'preferences-updated');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        try {
            $validated = $request->validate([
                'current_password' => ['required', 'string', 'current_password'],
                'password' => ['required', 'string', Password::defaults(), 'confirmed'],
            ]);
        } catch (ValidationException $e) {
            // Never echo a rejected password back to the client.
            throw $e;
        }

        $user = $request->user();
        $user->password = Hash::make($validated['password']);
        $user->save();

        return back()->with('status', 'password-updated');
    }

    /**
     * The About tab: the listing-profile editor, inside Settings.
     *
     * An owner can hold more than one listing, so the tab needs a picker and a
     * selection. `?listing=` chooses; anything not theirs falls back to their
     * first rather than 404ing the whole settings screen over a stale query
     * string.
     *
     * Returns null-ish shapes rather than aborting when the account has no
     * listing at all — Settings must still render for a member who has not
     * registered a business.
     *
     * @return array{listings: list<array{id: int, name: string}>, selectedId: int|null, profile: array<string, mixed>|null}
     */
    private function aboutTab(Request $request): array
    {
        $listings = $request->user()->sbos()->orderBy('name')->get();

        $selected = $listings->firstWhere('id', (int) $request->query('listing'))
            ?? $listings->first();

        return [
            'listings' => $listings->map(fn (Sbo $s): array => [
                'id' => $s->id,
                'name' => $s->name,
            ])->all(),
            'selectedId' => $selected?->id,
            'profile' => $selected ? SboListingProfilePayload::for($selected) : null,
        ];
    }

    private function normaliseProfilePhone(User $user, ?string $number): ?string
    {
        if (blank($number)) {
            return null;
        }

        $listing = $user->sbos()
            ->select(['country', 'owner_country'])
            ->orderBy('id')
            ->first();

        $normalised = PhoneNumbers::toE164(
            $number,
            PhoneNumbers::regionForCountry($listing?->owner_country ?: $listing?->country),
        );

        if (null === $normalised) {
            throw ValidationException::withMessages([
                'phone' => 'Enter a valid phone number. Use international format if your country is not known.',
            ]);
        }

        return $normalised;
    }
}
