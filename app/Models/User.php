<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use App\Notifications\VerifyEmailQueued;
use App\Support\AdminPages;
use App\Traits\HasUuid7;
use Database\Factories\UserFactory;
use DateTimeInterface;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Override;
use Propaganistas\LaravelPhone\Casts\E164PhoneNumberCast;

/**
 * @property UserRole $role
 */
final class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuid7, Notifiable;

    protected $fillable = [
        'firstname',
        'lastname',
        'name',
        'email',
        // The member's chosen interface language; see App\Support\Locales.
        'locale',
        'phone',
        // Loveworld-member details, captured at registration and copied onto
        // each business the member later registers.
        'country',
        'residential_address',
        'church_zone',
        'zone_id',
        'local_assembly',
        'years_as_member',
        'zonal_pastor',
        'local_pastor',
        'referred_via_code',
        'profile_photo_path',
        'kc_handle',
        'preferences',
        'password',
        'role',
        'permissions',
        'admin_page_permissions',
        'email_verified_at',
        'suspended_at',
        'suspended_by',
        'suspension_reason',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        // The secret mints valid codes and the recovery codes bypass the
        // challenge outright. Neither may ever ride along in a JSON payload.
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected $attributes = [
        'role' => 'user',
    ];

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    */

    public function sendEmailVerificationNotification(): void
    {
        /*
         * Auth mail must leave during the request. The project can run a
         * database queue, but MAMP/cPanel installs often have no worker
         * running; queueing verification leaves users with a success message
         * and no email.
         */
        $this->notifyNow(new VerifyEmailQueued());
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function initials(): string
    {
        if ($this->firstname && $this->lastname) {
            return Str::substr($this->firstname, 0, 1)
                . Str::substr($this->lastname, 0, 1);
        }

        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn (string $word) => Str::substr($word, 0, 1))
            ->implode('');
    }

    public function profilePhotoUrl(): ?string
    {
        if ( ! array_key_exists('profile_photo_path', $this->getAttributes())) {
            return null;
        }

        if ( ! $this->profile_photo_path) {
            return null;
        }

        return Storage::disk('public')->url($this->profile_photo_path);
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships (User-side only)
    |--------------------------------------------------------------------------
    */

    // Businesses owned by this user (bidders)
    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function advertisingInquiries(): HasMany
    {
        return $this->hasMany(AdvertisingInquiry::class);
    }

    public function businessReports(): HasMany
    {
        return $this->hasMany(BusinessReport::class, 'user_id');
    }

    public function favorites(): BelongsToMany
    {
        return $this->belongsToMany(Business::class, 'favorites')
            ->withTimestamps();
    }

    public function businessViews(): HasMany
    {
        return $this->hasMany(BusinessView::class);
    }

    public function verifiedBusinesses(): HasMany
    {
        return $this->hasMany(Business::class, 'verified_by');
    }

    /**
     * @return HasMany<Sbo, $this>
     */
    public function sbos(): HasMany
    {
        return $this->hasMany(Sbo::class, 'created_by');
    }

    public function purchaseOrderBids(): HasMany
    {
        return $this->hasMany(PurchaseOrderBid::class);
    }

    public function suspendedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'suspended_by');
    }

    /**
     * Overridden so notifications come back as `App\Models\Notification`
     * rather than the framework's DatabaseNotification. The row is identical;
     * the subclass adds the category / icon / action-required derivations the
     * `/notifications` screen renders from.
     *
     * `readNotifications()` and `unreadNotifications()` both defer to this
     * method, so they pick up the subclass too.
     *
     * (No #[Override]: the method it replaces comes from the Notifiable trait,
     * and PHP does not count trait methods as a parent for that attribute.)
     *
     * @return MorphMany<Notification, $this>
     */
    public function notifications(): MorphMany
    {
        return $this->morphMany(Notification::class, 'notifiable')->latest();
    }

    /*
    |--------------------------------------------------------------------------
    | Role helpers
    |--------------------------------------------------------------------------
    */

    public function isAdmin(): bool
    {
        return $this->role->isAdmin();
    }

    public function isSuperAdmin(): bool
    {
        return $this->role->isSuperAdmin();
    }

    /**
     * Whether this admin holds a given capability. Super Admins implicitly
     * have every capability; non-admins never do.
     */
    public function hasPermission(string $permission): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        if ( ! $this->isAdmin()) {
            return false;
        }

        return in_array($permission, $this->permissions ?? [], true)
            || AdminPages::capabilityIsCoveredByPages($this, $permission);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    public function hasAnyPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    public function isSboManager(): bool
    {
        return $this->role->isSboManager();
    }

    public function hasAdminPage(string $page): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        if ( ! $this->isAdmin()) {
            return false;
        }

        $attributes = $this->getAttributes();
        $pagePermissions = array_key_exists('admin_page_permissions', $attributes)
            ? $this->admin_page_permissions
            : null;

        if (null === $pagePermissions) {
            $legacyPermissions = array_key_exists('permissions', $attributes)
                ? ($this->permissions ?? [])
                : [];

            $pagePermissions = AdminPages::fromLegacyCapabilities($legacyPermissions, includeDefaults: true);
        }

        return in_array($page, AdminPages::normalize($pagePermissions), true);
    }

    /**
     * @param  array<int, string>  $pages
     */
    public function hasAnyAdminPage(array $pages): bool
    {
        foreach ($pages as $page) {
            if ($this->hasAdminPage($page)) {
                return true;
            }
        }

        return false;
    }

    public function isSuspended(): bool
    {
        return null !== $this->suspended_at;
    }

    /**
     * Whether login must ask this account for a code.
     *
     * Keyed on the CONFIRMED timestamp, not on the secret. A secret alone
     * means enrolment was started and never finished, and gating login on it
     * would lock out anyone whose QR scan failed halfway.
     */
    public function hasTwoFactorEnabled(): bool
    {
        return null !== $this->securityAttribute('two_factor_confirmed_at')
            && filled($this->securityAttribute('two_factor_secret'));
    }

    /** Days since the password was last set; null when it was never recorded. */
    public function passwordAgeInDays(): ?int
    {
        $changed = $this->securityAttribute('password_changed_at');

        return $changed instanceof DateTimeInterface
            ? (int) $changed->diffInDays(now())
            : null;
    }

    /*
    |--------------------------------------------------------------------------
    | Model events
    |--------------------------------------------------------------------------
    */

    #[Override]
    protected static function booted(): void
    {
        self::saving(function (User $user): void {
            if ('mysql' !== Schema::getConnection()->getDriverName()) {
                $user->name = mb_trim($user->lastname . ' ' . $user->firstname);
            }

            /*
             * Stamp the password age here rather than at each call site.
             * Passwords are set in five places — registration, the Livewire
             * modal's registration, the settings form, the reset flow and
             * seeders — and a per-site stamp is one someone forgets to add to
             * the sixth. The Security Overview's "Password Age" reads this.
             */
            if ($user->isDirty('password')) {
                $user->password_changed_at = now();
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Casts
    |--------------------------------------------------------------------------
    */

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'suspended_at' => 'datetime',
            'password' => 'hashed',
            // Encrypted at rest: a database dump must not hand over the means
            // to generate a valid second factor.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'phone' => E164PhoneNumberCast::class,
            'role' => UserRole::class,
            'permissions' => 'array',
            'admin_page_permissions' => 'array',
            'preferences' => 'array',
        ];
    }

    /**
     * Read one of the security columns, tolerating its absence.
     *
     * The app runs with Model::preventAccessingMissingAttributes(), and these
     * columns are newer than plenty of the code that builds User instances —
     * a factory create() or a partial select leaves them off the model
     * entirely, and a bare `$user->two_factor_confirmed_at` then throws rather
     * than returning null. Presence is checked first, then the value is read
     * through the accessor so the datetime and encryption casts still apply.
     * Same shape as the guard in SboNetworkAccess.
     */
    private function securityAttribute(string $key): mixed
    {
        return array_key_exists($key, $this->getAttributes()) ? $this->{$key} : null;
    }
}
