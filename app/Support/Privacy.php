<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Sbo;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * What a member has chosen not to be visible for.
 *
 * The Privacy rail item was a label with nothing behind it. The panel it now
 * opens would be worth no more than the label unless the choices on it are read
 * where the visibility actually happens, so this is that reader: the directory
 * query asks it whether to list, the profile payload asks it whether to print a
 * phone number, the connection endpoint asks it whether to accept.
 *
 * A setting belongs to the person who OWNS the listing, not the person looking
 * at it — `sbos.created_by` is the account whose preferences apply.
 */
final class Privacy
{
    public const LISTED = 'listedInDirectory';

    public const CONTACT_DETAILS = 'showContactDetails';

    public const CONNECTIONS = 'acceptConnectionRequests';

    public const VIEW_IDENTITY = 'shareViewIdentity';

    /** Whether a member has left a given visibility switched on. */
    public static function allows(?User $user, string $key): bool
    {
        if ( ! $user instanceof User) {
            // No owner to have expressed a preference: the designed default.
            return UserPreferences::PRIVACY_TOGGLES[$key] ?? true;
        }

        return (bool) (UserPreferences::for($user)['privacy'][$key]
            ?? UserPreferences::PRIVACY_TOGGLES[$key]
            ?? true);
    }

    /** The same question, asked about whoever owns a listing. */
    public static function allowsFor(?Sbo $sbo, string $key): bool
    {
        return self::allows($sbo?->creator, $key);
    }

    /**
     * Hide the listings whose owners have opted out of being listed.
     *
     * A subquery rather than a filter in PHP: the directory is paginated, and
     * dropping rows after the page has been cut leaves short pages and a total
     * that does not match what is shown.
     *
     * `preferences` is JSON and the key is absent until a member saves the
     * panel, so the test is "not explicitly false" — an untouched account is
     * listed, which is the default the panel itself draws.
     *
     * @param  Builder<Sbo>  $query
     * @return Builder<Sbo>
     */
    public static function scopeListed(Builder $query): Builder
    {
        return $query->whereDoesntHave('creator', function (Builder $owner): void {
            $owner->whereRaw(
                "json_extract(preferences, '$.privacy." . self::LISTED . "') = false"
            );
        });
    }
}
