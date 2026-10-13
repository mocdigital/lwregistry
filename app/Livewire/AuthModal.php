<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\UserRole;
use App\Http\Controllers\ReferralLandingController;
use App\Models\Country;
use App\Models\Sbo;
use App\Models\State;
use App\Models\User;
use App\Models\Zone;
use App\Rules\ValidTurnstileToken;
use App\Support\TurnstileVerifier;
use App\Traits\RedirectsBasedOnRole;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Propaganistas\LaravelPhone\Rules\Phone;
use Throwable;

/**
 * Login + registration modals.
 *
 * Not final: JoinRegistry extends this to render the same registration form as
 * a standalone page, so the fields and validation live in one place.
 */
class AuthModal extends Component
{
    use RedirectsBasedOnRole;

    public string $mode = 'login'; // 'login' or 'register'

    // Business multi-step state (pages 1-3)
    public int $businessStep = 1;

    // --- Page 1: Business Information ---
    public string $bizName = '';

    /** SBO (Strategic Business Organization) or COB (Citizen Owned Business). */
    public string $bizEntityType = Sbo::ENTITY_SBO;

    public string $bizLegalStructure = '';

    public string $bizAddress = '';

    public string $bizState = '';

    public string $bizPhone = '';

    public string $bizEmail = '';

    public string $bizWebsite = '';

    /** Supported legal structures for the registration form. */
    public array $legalStructures = [
        'Sole Proprietorship/Partnership',
        'Private Limited Company (Ltd)',
        'Public Limited Company (Plc)',
        'Company Limited by Guarantee (Ltd/Gtd)',
        'Incorporated Trustees (NGO/Association)',
    ];

    /**
     * Social media accounts: list of ['platform' => string, 'handle' => string].
     *
     * @var array<int, array{platform: string, handle: string}>
     */
    public array $bizSocials = [];

    /** Supported social platforms for the registration form. */
    public array $socialPlatforms = ['Instagram', 'Facebook', 'X', 'Kingschat', 'LinkedIn', 'TikTok', 'YouTube', 'Other'];

    public string $bizNature = '';

    public string $bizYearEstablished = '';

    public string $bizEmployees = '';

    public string $bizCountry = '';

    public string $bizPremiseType = '';

    /**
     * States for the currently selected country, loaded on demand so the page
     * never ships the full global states dataset.
     *
     * @var array<int, string>
     */
    public array $availableStates = [];

    // --- Page 2: Owner Information ---
    public string $ownerSurname = '';

    public string $ownerFirstName = '';

    public string $ownerCountry = '';

    public string $ownerResidentialAddress = '';

    public string $ownerKingschat = '';

    public string $ownerPhone = '';

    public string $ownerEmail = '';

    public string $ownerChurchZone = '';

    public string $ownerLocalAssembly = '';

    public string $ownerYearsAsMember = '';

    public string $ownerZonalPastor = '';

    public string $ownerLocalPastor = '';

    // --- Page 3: Account ---
    public string $bizPassword = '';

    public string $bizPasswordConfirmation = '';

    public bool $bizAgreeTerms = false;

    public bool $bizAgreeCode = false;

    public string $turnstileToken = '';

    // Login fields
    public string $loginEmail = '';

    public string $loginPassword = '';

    public bool $remember = false;

    public bool $redirecting = false;

    public string $redirectAfterLogin = '';

    /** Base profile URLs per platform for building a link from a handle. */
    private array $socialBaseUrls = [
        'Instagram' => 'https://instagram.com/',
        'Facebook' => 'https://facebook.com/',
        'X' => 'https://x.com/',
        'Kingschat' => 'https://kingschat.online/user/',
        'LinkedIn' => 'https://linkedin.com/in/',
        'TikTok' => 'https://tiktok.com/@',
        'YouTube' => 'https://youtube.com/@',
        'Other' => '',
    ];

    public function mount(): void
    {
        if (auth()->check()) {
            $this->dispatch('close-auth-modal');
        }

        if ( ! auth()->check()) {
            $this->resetFormFields();
            $this->applyZoneReferral();
        }
    }

    public function resetFormFields(): void
    {
        // Reset login fields
        $this->loginEmail = '';
        $this->loginPassword = '';
        $this->remember = false;

        // Reset business registration fields
        $this->businessStep = 1;
        $this->reset([
            'bizName', 'bizEntityType', 'bizLegalStructure', 'bizAddress', 'bizState', 'bizPhone',
            'bizEmail', 'bizWebsite', 'bizNature', 'bizYearEstablished', 'bizEmployees',
            'bizCountry', 'bizPremiseType',
            'ownerSurname', 'ownerFirstName', 'ownerCountry', 'ownerResidentialAddress',
            'ownerKingschat', 'ownerPhone', 'ownerEmail', 'ownerChurchZone',
            'ownerLocalAssembly', 'ownerYearsAsMember', 'ownerZonalPastor', 'ownerLocalPastor',
            'bizPassword', 'bizPasswordConfirmation', 'bizAgreeTerms', 'bizAgreeCode',
            'bizSocials', 'turnstileToken',
        ]);

        // Reset validation errors
        $this->resetValidation();
    }

    public function addSocial(): void
    {
        $this->bizSocials[] = ['platform' => '', 'handle' => ''];
    }

    public function removeSocial(int $index): void
    {
        unset($this->bizSocials[$index]);
        $this->bizSocials = array_values($this->bizSocials);
    }

    public function businessNextStep(): void
    {
        $this->normalizeRegistrationInput();

        $this->validate($this->businessStepRules($this->businessStep), [
            'bizPasswordConfirmation.same' => 'The passwords do not match.',
            'bizAgreeTerms.accepted' => 'You must agree to the terms and privacy policy.',
            'bizAgreeCode.accepted' => 'You must read and agree to the Kingdom Business Code of Conduct.',
            'bizNature.exists' => 'Please select a valid business category.',
            'bizCountry.exists' => 'Please select a valid country.',
            'ownerCountry.exists' => 'Please select a valid country.',
            'bizWebsite.url' => 'Enter a valid website, like example.com or https://example.com.',
            'bizPhone.regex' => 'Select a country code and enter digits only for the business phone number.',
            'bizLegalStructure.in' => 'Please select a valid legal structure.',
            'bizEntityType.in' => 'Please select a business or entity type.',
            'ownerChurchZone.in' => 'Please select a valid church zone.',
            'bizSocials.*.platform.in' => 'Please select a valid social media platform.',
            'bizSocials.*.handle.regex' => 'Enter a valid handle (letters, numbers, . _ -), no spaces or URLs.',
        ]);

        if ($this->businessStep < 3) {
            $this->businessStep++;
        }
    }

    public function businessPreviousStep(): void
    {
        if ($this->businessStep > 1) {
            $this->businessStep--;
        }
        $this->resetValidation();
    }

    public function switchToLogin(): void
    {
        $this->mode = 'login';
        $this->resetValidation();
    }

    public function switchToRegister(): void
    {
        $this->mode = 'register';
        $this->resetValidation();
    }

    public function login()
    {
        $this->validate([
            'loginEmail' => ['required', 'email'],
            'loginPassword' => ['required'],
        ]);

        if (Auth::attempt(
            ['email' => $this->loginEmail, 'password' => $this->loginPassword],
            $this->remember
        )) {
            if (Auth::user()?->isSuspended()) {
                Auth::logout();
                session()->invalidate();
                session()->regenerateToken();

                $this->addError('loginEmail', 'Your account has been suspended. Please contact support if you believe this is an error.');

                return;
            }

            /*
             * Two-factor challenge — the same gate as the HTTP login.
             *
             * Without this the modal would be a way straight past 2FA: the
             * Blade pages sign in here, so an account that had enrolled a
             * second factor could be opened with the password alone by using
             * the modal instead of /login. Mirrors
             * AuthenticatedSessionController::store — log back out, park the
             * pending id, and hand off to the challenge screen.
             */
            if (Auth::user()?->hasTwoFactorEnabled()) {
                $userId = Auth::id();

                Auth::logout();
                session()->invalidate();
                session()->regenerateToken();

                session()->put('two_factor.user_id', $userId);
                session()->put('two_factor.remember', $this->remember);

                $this->dispatch('close-auth-modal');

                return redirect()->route('two-factor.challenge');
            }

            session()->regenerate();
            session()->regenerateToken();

            \App\Http\Controllers\Auth\AuthenticatedSessionController::recordLogin(
                request(),
                Auth::user(),
            );

            // ✅ v3-safe browser event
            $this->dispatch('close-auth-modal');

            if (Auth::user()?->hasVerifiedEmail()) {
                $redirectAfterLogin = $this->validatedLoginRedirect();

                if ($redirectAfterLogin) {
                    return redirect()->to($redirectAfterLogin);
                }

                if ($this->requestCameFromAdvertise()) {
                    return redirect()->to($this->advertiseCampaignBuilderUrl());
                }

                if ($this->requestCameFromFeedback()) {
                    return redirect()->to($this->feedbackFormUrl());
                }
            }

            // Route through the role-aware post-login landing page.
            return redirect()->route('post-login');
        }

        $this->addError('loginEmail', 'The provided credentials do not match our records.');
    }

    public function rememberLoginRedirect(string $url): void
    {
        $this->redirectAfterLogin = $url;
    }

    public function registerBusiness()
    {
        $this->normalizeRegistrationInput();

        // Re-validate every step server-side before committing.
        try {
            $this->validate(
                $this->businessStepRules(1)
                + $this->businessStepRules(2)
                + $this->businessStepRules(3)
                + [
                    'turnstileToken' => [
                        Rule::requiredIf(app(TurnstileVerifier::class)->enabled()),
                        new ValidTurnstileToken(),
                    ],
                ],
                [
                    'bizPasswordConfirmation.same' => 'The passwords do not match.',
                    'bizAgreeTerms.accepted' => 'You must agree to the terms and privacy policy.',
                    'bizAgreeCode.accepted' => 'You must read and agree to the Kingdom Business Code of Conduct.',
                    'bizNature.exists' => 'Please select a valid business category.',
                    'bizCountry.exists' => 'Please select a valid country.',
                    'ownerCountry.exists' => 'Please select a valid country.',
                    'bizWebsite.url' => 'Enter a valid website, like example.com or https://example.com.',
                    'bizPhone.regex' => 'Select a country code and enter digits only for the business phone number.',
                    'bizLegalStructure.in' => 'Please select a valid legal structure.',
                    'bizEntityType.in' => 'Please select a business or entity type.',
                    'bizEntityType.in' => 'Please select a business or entity type.',
                    'ownerChurchZone.in' => 'Please select a valid church zone.',
                    'bizSocials.*.platform.in' => 'Please select a valid social media platform.',
                    'bizSocials.*.handle.regex' => 'Enter a valid handle (letters, numbers, . _ -), no spaces or URLs.',
                ]
            );
        } catch (ValidationException $e) {
            // Errors on step-1/2 fields would otherwise render inside a hidden
            // panel; jump back to the earliest step that owns one.
            $this->businessStep = $this->firstStepWithErrors(array_keys($e->errors()));

            throw $e;
        }

        // Resolve the zone this registration should be attributed to. Prefer the
        // referral code carried in the session (link-driven sign-up); otherwise
        // fall back to the zone the owner selected in the form.
        [$zoneId, $referredViaCode] = $this->resolveRegistrationZone();

        $user = DB::transaction(function () use ($zoneId, $referredViaCode): User {
            // 1. Create the owner's login account as an SBO Manager.
            $user = new User();
            $user->firstname = $this->ownerFirstName;
            $user->lastname = $this->ownerSurname;
            $user->phone = $this->ownerPhone ?: null;
            $user->email = $this->ownerEmail;
            $user->password = Hash::make($this->bizPassword);
            $user->role = UserRole::SBO_MANAGER;
            $user->save();

            // 2. Create the SBO, linked to the owner, application-approved.
            Sbo::create([
                'name' => $this->bizName,
                'slug' => $this->uniqueSboSlug($this->bizName),
                'entity_type' => $this->bizEntityType,
                'legal_structure' => $this->bizLegalStructure,
                'business_type' => $this->bizNature,
                'category' => $this->bizNature,
                'address' => $this->bizAddress,
                'city' => null,
                'state' => $this->bizState,
                'country' => $this->bizCountry,
                // Structured country FK for country-scoped config (fees, etc.);
                // the free-text `country` was validated exists:countries,name.
                'country_id' => Country::query()->where('name', $this->bizCountry)->value('id'),
                'premise_type' => $this->bizPremiseType ?: null,
                'established_at' => '' !== $this->bizYearEstablished
                    ? $this->bizYearEstablished . '-01-01'
                    : null,
                'website' => $this->bizWebsite ?: null,
                'social_links' => $this->cleanSocials(),
                'staff_count' => '' !== $this->bizEmployees ? (int) $this->bizEmployees : null,
                'primary_contact_name' => mb_trim($this->ownerFirstName . ' ' . $this->ownerSurname),
                'primary_contact_email' => $this->bizEmail,
                'owner_surname' => $this->ownerSurname,
                'owner_first_name' => $this->ownerFirstName,
                'owner_country' => $this->ownerCountry,
                'owner_residential_address' => $this->ownerResidentialAddress,
                'church_zone' => $this->ownerChurchZone,
                'zone_id' => $zoneId,
                'referred_via_code' => $referredViaCode,
                'local_assembly' => $this->ownerLocalAssembly,
                'years_as_member' => '' !== $this->ownerYearsAsMember ? (int) $this->ownerYearsAsMember : null,
                'zonal_pastor' => $this->ownerZonalPastor ?: null,
                'local_pastor' => $this->ownerLocalPastor ?: null,
                'phone' => $this->bizPhone,
                'kingschat_handle' => $this->ownerKingschat ?: null,
                'is_active' => true,
                'created_by' => $user->id,
                'status' => 'active',
                'verification_status' => 'approved',
                'approved_by' => null,
                'date_approved' => now(),
                'documents_status' => 'pending',
                'payment_status' => 'unpaid',
                'code_of_conduct_agreed_at' => now(),
            ]);

            return $user;
        });

        // Sends the verification email. The welcome/next-steps email is held
        // back until the address is actually verified (see the Verified event
        // listener), so at registration the member receives one email only:
        // "verify your email".
        event(new Registered($user));

        // Referral consumed — clear it so a later sign-up in the same session
        // isn't wrongly attributed.
        session()->forget([ReferralLandingController::SESSION_CODE, ReferralLandingController::SESSION_NAME]);

        Auth::login($user);
        $this->dispatch('close-auth-modal');

        return redirect()->route('post-login');
    }

    public function render()
    {
        return view('livewire.auth-modal', [
            'countries' => Country::orderBy('name')->pluck('name'),
            'churchZones' => $this->activeZoneNames(),
        ]);
    }

    /**
     * Livewire hook: when the selected country changes, load just that country's
     * states (cached) instead of shipping the whole global dataset to the page.
     */
    public function updatedBizCountry(): void
    {
        $this->loadBusinessStates();
    }

    public function selectBusinessCountry(string $country): void
    {
        $this->bizCountry = $country;
        $this->loadBusinessStates();
    }

    public function selectBusinessState(string $state): void
    {
        $this->bizState = in_array($state, $this->availableStates, true) ? $state : '';
    }

    /**
     * Active church-zone names for the join-form dropdown and validation,
     * sourced from the zones table. Memoized for the request.
     *
     * @return array<int, string>
     */
    protected function activeZoneNames(): array
    {
        return once(fn (): array => Zone::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name')
            ->all());
    }

    /**
     * If the visitor arrived via a zone referral link, open the form in
     * register mode with their zone pre-selected. Runs after resetFormFields()
     * so the pre-selection isn't wiped.
     */
    private function applyZoneReferral(): void
    {
        $zoneName = session(ReferralLandingController::SESSION_NAME);

        if (is_string($zoneName) && in_array($zoneName, $this->activeZoneNames(), true)) {
            $this->mode = 'register';
            $this->ownerChurchZone = $zoneName;
        }
    }

    private function validatedLoginRedirect(): ?string
    {
        return $this->normalizeAllowedAuthRedirect($this->redirectAfterLogin);
    }

    private function requestCameFromAdvertise(): bool
    {
        return $this->requestCameFromRoute(route('advertise'));
    }

    private function requestCameFromFeedback(): bool
    {
        return $this->requestCameFromRoute(route('feedback'));
    }

    private function requestCameFromRoute(string $routeUrl): bool
    {
        $referer = request()->headers->get('referer');

        if ( ! is_string($referer) || '' === $referer) {
            return false;
        }

        $parts = parse_url($referer);
        $routeParts = parse_url($routeUrl);

        if ( ! is_array($parts) || ! is_array($routeParts)) {
            return false;
        }

        if (($parts['host'] ?? request()->getHost()) !== ($routeParts['host'] ?? request()->getHost())) {
            return false;
        }

        return ($parts['path'] ?? '/') === ($routeParts['path'] ?? '/');
    }

    private function normalizeAllowedAuthRedirect(string $url): ?string
    {
        $url = mb_trim($url);

        if ('' === $url) {
            return null;
        }

        $parts = parse_url($url);

        if ( ! is_array($parts)) {
            return null;
        }

        foreach ($this->allowedLoginRedirects() as $allowed) {
            $allowedParts = parse_url($allowed['route']);

            if ( ! is_array($allowedParts)) {
                continue;
            }

            if (($parts['host'] ?? request()->getHost()) !== ($allowedParts['host'] ?? request()->getHost())) {
                continue;
            }

            if (($parts['path'] ?? '/') !== ($allowedParts['path'] ?? '/')) {
                continue;
            }

            if (($parts['fragment'] ?? null) !== $allowed['fragment']) {
                continue;
            }

            return $allowed['url'];
        }

        return null;
    }

    /**
     * @return array<int, array{route: string, fragment: string, url: string}>
     */
    private function allowedLoginRedirects(): array
    {
        return [
            [
                'route' => route('advertise'),
                'fragment' => 'advertise-campaign-builder',
                'url' => $this->advertiseCampaignBuilderUrl(),
            ],
            [
                'route' => route('feedback'),
                'fragment' => 'feedback-form',
                'url' => $this->feedbackFormUrl(),
            ],
        ];
    }

    private function advertiseCampaignBuilderUrl(): string
    {
        return route('advertise') . '#advertise-campaign-builder';
    }

    private function feedbackFormUrl(): string
    {
        return route('feedback') . '#feedback-form';
    }

    /**
     * Resolve [zone_id, referred_via_code] for a new registration. A referral
     * code held in the session (link-driven) wins; otherwise the zone is
     * resolved from the owner's selected church-zone name so every registration
     * is still attributed. Returns nulls when no active zone matches.
     *
     * @return array{0: int|null, 1: string|null}
     */
    private function resolveRegistrationZone(): array
    {
        $code = session(ReferralLandingController::SESSION_CODE);

        if (is_string($code) && '' !== $code) {
            $zone = Zone::query()->where('code', $code)->where('is_active', true)->first();

            if (null !== $zone) {
                return [$zone->id, $zone->code];
            }
        }

        // No (valid) referral — attribute by the selected zone name, no code.
        $zoneId = Zone::query()
            ->where('name', $this->ownerChurchZone)
            ->where('is_active', true)
            ->value('id');

        return [$zoneId, null];
    }

    /**
     * Validation rules for each business step.
     *
     * @return array<string, mixed>
     */
    private function businessStepRules(int $step): array
    {
        return match ($step) {
            1 => [
                'bizName' => ['required', 'string', 'max:255'],
                'bizEntityType' => ['required', Rule::in(array_keys(Sbo::ENTITY_TYPES))],
                'bizLegalStructure' => ['required', 'string', Rule::in($this->legalStructures)],
                'bizAddress' => ['required', 'string', 'max:255'],
                'bizState' => ['required', 'string', 'max:255'],

                'bizPhone' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9]{4,25}$/'],
                'bizEmail' => ['required', 'email', 'max:255'],
                'bizWebsite' => ['nullable', 'string', 'max:255', 'url:http,https'],
                'bizSocials' => ['array'],
                'bizSocials.*.platform' => ['nullable', 'required_with:bizSocials.*.handle', 'string', 'max:50', Rule::in($this->socialPlatforms)],
                // Handle only: letters, numbers, dot, underscore, dash (optional leading @).
                'bizSocials.*.handle' => ['nullable', 'required_with:bizSocials.*.platform', 'string', 'max:100', 'regex:/^@?[A-Za-z0-9._-]+$/'],
                'bizNature' => ['required', 'string', 'max:255', 'exists:categories,name'],
                'bizYearEstablished' => ['nullable', 'integer', 'min:1800', 'max:' . date('Y')],
                'bizEmployees' => ['nullable', 'integer', 'min:0'],
                'bizCountry' => ['required', 'string', 'max:255', 'exists:countries,name'],
                'bizPremiseType' => ['nullable', 'in:physical,virtual,both'],
            ],
            2 => [
                'ownerSurname' => ['required', 'string', 'max:255'],
                'ownerFirstName' => ['required', 'string', 'max:255'],
                'ownerCountry' => ['required', 'string', 'max:255', 'exists:countries,name'],
                'ownerResidentialAddress' => ['required', 'string', 'max:255'],
                'ownerKingschat' => ['nullable', 'string', 'max:255'],
                'ownerPhone' => ['required', (new Phone())->international()],
                'ownerEmail' => ['required', 'email', 'max:255', 'unique:' . User::class . ',email'],
                'ownerChurchZone' => ['required', 'string', 'max:255', Rule::in($this->activeZoneNames())],
                'ownerLocalAssembly' => ['required', 'string', 'max:255'],
                'ownerYearsAsMember' => ['nullable', 'integer', 'min:0'],
                'ownerZonalPastor' => ['nullable', 'string', 'max:255'],
                'ownerLocalPastor' => ['nullable', 'string', 'max:255'],
            ],
            3 => [
                'bizPassword' => ['required', 'string', Password::min(8)->letters()->mixedCase()->numbers()],
                'bizPasswordConfirmation' => ['required', 'same:bizPassword'],
                'bizAgreeTerms' => ['accepted'],
                'bizAgreeCode' => ['accepted'],
            ],
            default => [],
        };
    }

    /** The earliest form step owning any of the given errored fields (3 covers its own fields, turnstile, etc.). */
    private function firstStepWithErrors(array $fields): int
    {
        $roots = array_map(static fn (string $field): string => explode('.', $field)[0], $fields);

        foreach ([1, 2] as $step) {
            foreach (array_keys($this->businessStepRules($step)) as $key) {
                if (in_array(explode('.', $key)[0], $roots, true)) {
                    return $step;
                }
            }
        }

        return 3;
    }

    private function uniqueSboSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i = 1;

        while (Sbo::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }

    private function normalizeRegistrationInput(): void
    {
        $this->bizWebsite = $this->normalizeWebsite($this->bizWebsite);
    }

    private function normalizeWebsite(string $website): string
    {
        $website = mb_trim($website);

        if ('' === $website) {
            return '';
        }

        if ( ! preg_match('/^https?:\/\//i', $website)) {
            return 'https://' . $website;
        }

        return $website;
    }

    /**
     * Drop empty social rows and return a clean list (or null if none).
     *
     * @return array<int, array{platform: string, url: string}>|null
     */
    private function cleanSocials(): ?array
    {
        $socials = [];

        foreach ($this->bizSocials as $s) {
            if (empty($s['platform']) || empty($s['handle'])) {
                continue;
            }

            $handle = mb_ltrim($s['handle'], '@');
            $base = $this->socialBaseUrls[$s['platform']] ?? '';

            $socials[] = [
                'platform' => $s['platform'],
                'handle' => '@' . $handle,
                'url' => '' !== $base ? $base . $handle : null,
            ];
        }

        return [] !== $socials ? $socials : null;
    }

    private function loadBusinessStates(): void
    {
        $this->availableStates = $this->statesForCountry($this->bizCountry);

        // Drop a previously chosen state that doesn't belong to the new country.
        if ('' !== $this->bizState && ! in_array($this->bizState, $this->availableStates, true)) {
            $this->bizState = '';
        }
    }

    /**
     * States for a single country, sourced from the database with a hardcoded
     * fallback. Cached per country so repeated selections are cheap.
     *
     * @return array<int, string>
     */
    private function statesForCountry(string $country): array
    {
        $country = mb_trim($country);

        if ('' === $country) {
            return [];
        }

        $loadStates = function () use ($country): array {
            try {
                $states = State::query()
                    ->whereHas('country', fn ($q) => $q->where('name', $country))
                    ->orderBy('name')
                    ->pluck('name')
                    ->all();
            } catch (Throwable) {
                $states = [];
            }

            if ([] !== $states) {
                return $states;
            }

            $states = $this->datasetStatesForCountry($country);

            if ([] !== $states) {
                return $states;
            }

            // Fall back to the hardcoded map for countries not yet seeded.
            return array_keys($this->hardcodedFallbackLocationData()[$country] ?? []);
        };

        try {
            return \Illuminate\Support\Facades\Cache::remember(
                'join_form.states.v2:' . mb_strtolower($country),
                now()->addHours(12),
                $loadStates
            );
        } catch (Throwable) {
            return $loadStates();
        }
    }

    /**
     * States from the bundled public country/state dataset. This keeps the join
     * form useful even before the global states migration has been run.
     *
     * @return array<int, string>
     */
    private function datasetStatesForCountry(string $country): array
    {
        $path = database_path('data/countries+states.json');

        if ( ! is_file($path)) {
            return [];
        }

        try {
            /** @var array<int, array{name?: string, states?: array<int, string|array{name?: string}>}> $dataset */
            $dataset = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        $target = $this->normalizeLocationName($country);
        $aliases = array_flip($this->countryDatasetAliases());
        $target = $aliases[$target] ?? $target;

        foreach ($dataset as $entry) {
            if ($this->normalizeLocationName((string) ($entry['name'] ?? '')) !== $target) {
                continue;
            }

            return collect($entry['states'] ?? [])
                ->map(fn (mixed $state): string => is_array($state)
                    ? mb_trim((string) ($state['name'] ?? ''))
                    : mb_trim((string) $state))
                ->filter()
                ->unique(fn (string $state): string => $this->normalizeLocationName($state))
                ->sort()
                ->values()
                ->all();
        }

        return [];
    }

    private function normalizeLocationName(string $name): string
    {
        $name = mb_strtolower(mb_trim($name));
        $name = str_replace('&', 'and', $name);

        return (string) preg_replace('/\s+/', ' ', $name);
    }

    /**
     * Dataset normalized name => local countries table normalized name.
     *
     * @return array<string, string>
     */
    private function countryDatasetAliases(): array
    {
        return [
            'eswatini' => 'swaziland',
            'guernsey' => 'guernsey and alderney',
            'ivory coast' => "cote d'ivoire (ivory coast)",
            'the gambia' => 'gambia the',
            'timor-leste' => 'east timor',
        ];
    }

    /**
     * Hardcoded states fallback for countries not yet present in the database.
     * Keyed by country => [state => cities]; only the state keys are used now.
     *
     * @return array<string, array<string, array<int, string>>>
     */
    private function hardcodedFallbackLocationData(): array
    {
        return [
            'Nigeria' => [
                'Abia' => ['Aba', 'Umuahia', 'Ohafia', 'Arochukwu'],
                'Abuja (Federal Capital Territory)' => ['Abuja', 'Gwagwalada', 'Kuje', 'Bwari'],
                'Adamawa' => ['Yola', 'Mubi', 'Numan', 'Jimeta'],
                'Akwa Ibom' => ['Uyo', 'Eket', 'Ikot Ekpene', 'Oron'],
                'Anambra' => ['Awka', 'Onitsha', 'Nnewi', 'Ekwulobia'],
                'Bauchi' => ['Bauchi', 'Azare', 'Misau', 'Jamaare'],
                'Bayelsa' => ['Yenagoa', 'Brass', 'Ogbia', 'Nembe'],
                'Benue' => ['Makurdi', 'Gboko', 'Otukpo', 'Katsina-Ala'],
                'Borno' => ['Maiduguri', 'Biu', 'Bama', 'Monguno'],
                'Cross River' => ['Calabar', 'Ikom', 'Ogoja', 'Obudu'],
                'Delta' => ['Asaba', 'Warri', 'Sapele', 'Ughelli'],
                'Ebonyi' => ['Abakaliki', 'Afikpo', 'Onueke', 'Ishiagu'],
                'Edo' => ['Benin City', 'Auchi', 'Ekpoma', 'Uromi'],
                'Ekiti' => ['Ado-Ekiti', 'Ikere-Ekiti', 'Ilawe-Ekiti', 'Oye-Ekiti'],
                'Enugu' => ['Enugu', 'Nsukka', 'Agbani', 'Udi'],
                'Gombe' => ['Gombe', 'Kaltungo', 'Billiri', 'Dukku'],
                'Imo' => ['Owerri', 'Orlu', 'Okigwe', 'Mbaise'],
                'Jigawa' => ['Dutse', 'Hadejia', 'Gumel', 'Kazaure'],
                'Kaduna' => ['Kaduna', 'Zaria', 'Kafanchan', 'Soba'],
                'Kano' => ['Kano', 'Wudil', 'Gwarzo', 'Rano'],
                'Katsina' => ['Katsina', 'Daura', 'Funtua', 'Dutsin-Ma'],
                'Kebbi' => ['Birnin Kebbi', 'Argungu', 'Yauri', 'Zuru'],
                'Kogi' => ['Lokoja', 'Okene', 'Idah', 'Kabba'],
                'Kwara' => ['Ilorin', 'Offa', 'Omu-Aran', 'Jebba'],
                'Lagos' => ['Lagos', 'Ikeja', 'Lekki', 'Ikorodu', 'Epe', 'Badagry'],
                'Nasarawa' => ['Lafia', 'Keffi', 'Akwanga', 'Karu'],
                'Niger' => ['Minna', 'Bida', 'Kontagora', 'Suleja'],
                'Ogun' => ['Abeokuta', 'Ijebu Ode', 'Sagamu', 'Ota'],
                'Ondo' => ['Akure', 'Ondo', 'Owo', 'Ikare-Akoko'],
                'Osun' => ['Osogbo', 'Ile-Ife', 'Ilesa', 'Ede'],
                'Oyo' => ['Ibadan', 'Ogbomoso', 'Oyo', 'Iseyin'],
                'Plateau' => ['Jos', 'Bukuru', 'Pankshin', 'Shendam'],
                'Rivers' => ['Port Harcourt', 'Bonny', 'Bori', 'Omoku'],
                'Sokoto' => ['Sokoto', 'Tambuwal', 'Wurno', 'Gwadabawa'],
                'Taraba' => ['Jalingo', 'Wukari', 'Bali', 'Takum'],
                'Yobe' => ['Damaturu', 'Potiskum', 'Gashua', 'Nguru'],
                'Zamfara' => ['Gusau', 'Kaura Namoda', 'Talata Mafara', 'Anka'],
            ],
            'Ghana' => [
                'Ashanti' => ['Kumasi', 'Obuasi', 'Ejisu'],
                'Greater Accra' => ['Accra', 'Tema', 'Madina'],
                'Northern' => ['Tamale', 'Yendi', 'Savelugu'],
                'Western' => ['Sekondi-Takoradi', 'Tarkwa', 'Axim'],
            ],
            'Kenya' => [
                'Nairobi County' => ['Nairobi', 'Karen', 'Westlands'],
                'Mombasa County' => ['Mombasa', 'Likoni', 'Nyali'],
                'Kisumu County' => ['Kisumu', 'Ahero', 'Maseno'],
                'Nakuru County' => ['Nakuru', 'Naivasha', 'Molo'],
            ],
            'South Africa' => [
                'Eastern Cape' => ['Gqeberha', 'East London', 'Mthatha'],
                'Gauteng' => ['Johannesburg', 'Pretoria', 'Soweto'],
                'KwaZulu-Natal' => ['Durban', 'Pietermaritzburg', 'Newcastle'],
                'Western Cape' => ['Cape Town', 'Stellenbosch', 'George'],
            ],
            'United Kingdom' => [
                'England' => ['London', 'Manchester', 'Birmingham', 'Liverpool'],
                'Northern Ireland' => ['Belfast', 'Derry', 'Lisburn'],
                'Scotland' => ['Edinburgh', 'Glasgow', 'Aberdeen'],
                'Wales' => ['Cardiff', 'Swansea', 'Newport'],
            ],
            'United States' => [
                'California' => ['Los Angeles', 'San Francisco', 'San Diego', 'Sacramento'],
                'Florida' => ['Miami', 'Orlando', 'Tampa', 'Jacksonville'],
                'Georgia' => ['Atlanta', 'Savannah', 'Augusta'],
                'New York' => ['New York City', 'Buffalo', 'Rochester', 'Albany'],
                'Texas' => ['Houston', 'Dallas', 'Austin', 'San Antonio'],
            ],
            'Canada' => [
                'Alberta' => ['Calgary', 'Edmonton', 'Red Deer'],
                'British Columbia' => ['Vancouver', 'Victoria', 'Kelowna'],
                'Ontario' => ['Toronto', 'Ottawa', 'Hamilton'],
                'Quebec' => ['Montreal', 'Quebec City', 'Laval'],
            ],
        ];
    }
}
