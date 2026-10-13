<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * A registration for the Global Business Prayer Conference, submitted from the
 * GBPC landing page (gbpc.lwregistry.org).
 *
 * @property int $id
 * @property string|null $title
 * @property string $full_name
 * @property string $email
 * @property string $country_city
 * @property string $zone
 * @property string $occupation
 * @property string $owns_business
 * @property string|null $business_name
 * @property string $expectations
 * @property string $phone
 * @property string $kingschat_handle
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class GbpcConferenceRegistration extends Model
{
    protected $fillable = [
        'title',
        'full_name',
        'phone',
        'kingschat_handle',
        'email',
        'country_city',
        'zone',
        'occupation',
        'owns_business',
        'business_name',
        'expectations',
    ];
}
