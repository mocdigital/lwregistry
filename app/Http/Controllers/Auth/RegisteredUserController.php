<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Controllers\ReferralLandingController;
use App\Models\Country;
use App\Models\User;
use App\Models\Zone;
use App\Support\PhoneNumbers;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Member registration over HTTP.
 *
 * Registration creates a MEMBER ACCOUNT ONLY — the person, their contact
 * details and their Loveworld-member/church attribution, plus an email and
 * password. It no longer creates a business: the member verifies their email
 * first, then adds one or more businesses from the workspace
 * (SboWorkspaceController::createListing), where the business details and the
 * Code of Conduct are collected. The member details captured here are copied
 * onto each business at that point, so they are entered once.
 */
final class RegisteredUserController extends Controller
{
    public function create(): InertiaResponse
    {
        return Inertia::render('auth/RegisterView', [
            // Church-zone select; validated against the same active set on submit.
            'zones' => Zone::where('is_active', true)->orderBy('name')->pluck('name')->values(),
            'countries' => Country::orderBy('name')->pluck('name')->values(),
            /*
             * The phone prefix follows the country the member picks rather than a
             * fixed "+234", on a registry that spans every country in `countries`.
             * Keyed by country name because that is what the select holds.
             */
            'dialCodes' => PhoneNumbers::dialCodesByCountry(),
            'countryIsoCodes' => PhoneNumbers::isoCodesByCountry(),
            'countriesByDialCode' => PhoneNumbers::countriesByDialCode(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->rules(), [
            'passwordConfirmation.same' => 'The passwords do not match.',
            'agreeTerms.accepted' => 'You must agree to the terms and privacy policy.',
            'ownerCountry.exists' => 'Please select a valid country.',
            'ownerChurchZone.in' => 'Please select a valid church zone.',
            'email.unique' => 'An account with this email already exists.',
        ]);

        /*
         * `User::$phone` is an E.164 cast with no default region, so assigning a
         * number as people type it ("08031234567") threw at the very end of
         * registration. Normalised against the country the member picked.
         */
        $data['ownerPhone'] = $this->e164($data['ownerPhone'], $data['ownerCountry'], 'ownerPhone');

        [$zoneId, $referredViaCode] = $this->resolveRegistrationZone($data['ownerChurchZone']);

        $user = new User();
        $user->firstname = $data['ownerFirstName'];
        $user->lastname = $data['ownerSurname'];
        $user->email = $data['email'];
        $user->phone = $data['ownerPhone'] ?? null;
        $user->kc_handle = $data['ownerKingschat'] ?? null;
        $user->country = $data['ownerCountry'];
        $user->residential_address = $data['ownerResidentialAddress'];
        $user->church_zone = $data['ownerChurchZone'];
        $user->zone_id = $zoneId;
        $user->local_assembly = $data['ownerLocalAssembly'];
        $user->years_as_member = filled($data['ownerYearsAsMember'] ?? null)
            ? (int) $data['ownerYearsAsMember']
            : null;
        $user->zonal_pastor = $data['ownerZonalPastor'] ?? null;
        $user->local_pastor = $data['ownerLocalPastor'] ?? null;
        $user->referred_via_code = $referredViaCode;
        $user->role = UserRole::SBO_MANAGER;
        $user->password = Hash::make($data['password']);
        $user->save();

        /*
         * The verification mail leaves during the request (see
         * User::sendEmailVerificationNotification), so a mail-provider outage
         * used to turn a saved account into a bare 500. The account exists by
         * now; carry on to the verification notice, which says the mail did not
         * go out and offers the resend button.
         */
        $mailFailed = false;

        try {
            event(new Registered($user));
        } catch (TransportExceptionInterface $e) {
            report($e);
            $mailFailed = true;
        }

        // Referral consumed — clear it so a later sign-up in the same session is
        // not wrongly attributed.
        $request->session()->forget([
            ReferralLandingController::SESSION_CODE,
            ReferralLandingController::SESSION_NAME,
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        // post-login sends the member to the email-verification notice, and from
        // there — once verified — to adding their first business.
        // Straight to the notice when the mail failed: a flash survives one
        // redirect, and post-login would spend it on its own hop there.
        return $mailFailed
            ? redirect()->route('verification.notice')->with('status', 'verification-link-failed')
            : redirect()->route('post-login');
    }

    /**
     * Normalise a typed number, failing validation rather than storing something
     * the model cast would reject on save.
     */
    private function e164(string $number, string $country, string $field): string
    {
        $normalised = PhoneNumbers::toE164($number, PhoneNumbers::regionForCountry($country));

        if (null === $normalised) {
            throw ValidationException::withMessages([
                $field => 'Enter a valid phone number for the country you selected.',
            ]);
        }

        return $normalised;
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            // The member (Contact & Representative).
            'ownerSurname' => ['required', 'string', 'max:255'],
            'ownerFirstName' => ['required', 'string', 'max:255'],
            'ownerCountry' => ['required', 'string', 'max:255', 'exists:countries,name'],
            'ownerResidentialAddress' => ['required', 'string', 'max:255'],
            'ownerKingschat' => ['nullable', 'string', 'max:255'],
            'ownerPhone' => ['required', 'string', 'max:30'],
            'ownerChurchZone' => ['required', 'string', 'max:255', Rule::in($this->activeZoneNames())],
            'ownerLocalAssembly' => ['required', 'string', 'max:255'],
            'ownerYearsAsMember' => ['nullable', 'integer', 'min:0'],
            'ownerZonalPastor' => ['nullable', 'string', 'max:255'],
            'ownerLocalPastor' => ['nullable', 'string', 'max:255'],

            // The account.
            'email' => ['required', 'email', 'max:255', 'unique:' . User::class . ',email'],
            'password' => ['required', 'string', Password::min(8)->letters()->mixedCase()->numbers()],
            'passwordConfirmation' => ['required', 'same:password'],
            'agreeTerms' => ['accepted'],
        ];
    }

    /** @return array<int, string> */
    private function activeZoneNames(): array
    {
        return Zone::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    /**
     * Prefer the referral code carried in the session (link-driven sign-up),
     * otherwise attribute by the zone the member selected.
     *
     * @return array{0: int|null, 1: string|null}
     */
    private function resolveRegistrationZone(string $churchZone): array
    {
        $code = session(ReferralLandingController::SESSION_CODE);

        if (is_string($code) && '' !== $code) {
            $zone = Zone::query()->where('code', $code)->where('is_active', true)->first();

            if (null !== $zone) {
                return [$zone->id, $zone->code];
            }
        }

        $zoneId = Zone::query()
            ->where('name', $churchZone)
            ->where('is_active', true)
            ->value('id');

        return [$zoneId, null];
    }
}
