<?php

declare(strict_types=1);

use App\Models\Sbo;
use App\Models\User;
use Illuminate\Support\Str;

function businessViewCountSbo(array $overrides = []): Sbo
{
    $name = $overrides['name'] ?? 'Viewable SBO ' . Str::random(6);

    return Sbo::create(array_merge([
        'name' => $name,
        'slug' => Str::slug($name) . '-' . Str::random(5),
        'category' => 'Technology',
        'city' => 'Lagos',
        'address' => 'Central Business District',
        'description' => 'SBO directory detail page',
        'created_by' => User::factory()->create()->id,
        'verification_status' => 'approved',
        'documents_status' => 'compliant',
        'documents_compliant_at' => now(),
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
    ], $overrides));
}

describe('sbo detail pages', function (): void {
    it('sends a guest to the login page instead of counting a view', function (): void {
        $sbo = businessViewCountSbo(['name' => 'Guest Visible SBO']);

        // The directory is members-only now, so a guest never reaches the
        // detail page. The half of this test that still means something is
        // that nothing is recorded on the way past.
        $this->get(route('businesses.show', $sbo->slug))
            ->assertRedirect(route('login'));

        $sbo->refresh();
        expect($sbo->views_count)->toBe(0);
        $this->assertDatabaseCount('business_views', 0);
    });

    it('does not record a legacy business view for a member who does open it', function (): void {
        $sbo = businessViewCountSbo(['name' => 'Counted SBO']);

        $response = $this->actingAs(paidMember())
            ->get(route('businesses.show', $sbo->slug))
            ->assertOk();

        expect(inertiaProp($response, 'business.name'))->toBe('Counted SBO');

        // `business_views` and `views_count` belong to the legacy Business
        // model. The Sbo detail screen does not write to either, and this is
        // what says so.
        $sbo->refresh();
        expect($sbo->views_count)->toBe(0);
        $this->assertDatabaseCount('business_views', 0);
    });

    it('renders for non-owner authenticated users', function (): void {
        $owner = User::factory()->create();
        $visitor = User::factory()->create();
        // The platform is paid to use, so a visitor needs a paid listing of
        // their own to browse someone else's.
        businessViewCountSbo(['name' => 'Visitor Own SBO', 'created_by' => $visitor->id]);
        $sbo = businessViewCountSbo([
            'name' => 'Visitor Visible SBO',
            'created_by' => $owner->id,
        ]);

        $response = $this->actingAs($visitor)
            ->get(route('businesses.show', $sbo->slug))
            ->assertOk();

        expect(inertiaProp($response, 'business.name'))->toBe('Visitor Visible SBO');
    });

    it('renders for the owner', function (): void {
        $owner = User::factory()->create();
        $sbo = businessViewCountSbo([
            'name' => 'Owner Visible SBO',
            'created_by' => $owner->id,
        ]);

        $response = $this->actingAs($owner)
            ->get(route('businesses.show', $sbo->slug))
            ->assertOk();

        expect(inertiaProp($response, 'business.name'))->toBe('Owner Visible SBO');
    });

    it('does not expose declined sbo listings publicly', function (): void {
        $sbo = businessViewCountSbo([
            'name' => 'Declined Hidden SBO',
            'verification_status' => 'declined',
        ]);

        // Asserted as a member, so it is the listing being withheld and not
        // the auth redirect standing in for it.
        $this->actingAs(paidMember())
            ->get(route('businesses.show', $sbo->slug))
            ->assertNotFound();
    });
});
