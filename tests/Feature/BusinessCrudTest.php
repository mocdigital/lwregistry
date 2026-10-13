<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Livewire\CreateBusinessModal;
use App\Models\Business;
use App\Models\Category;
use App\Models\Sbo;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;

function businessCrudUnpaidSboManager(): User
{
    $manager = User::factory()->create([
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ]);
    $name = 'Business Gate SBO ' . Str::random(6);

    Sbo::create([
        'name' => $name,
        'slug' => Str::slug($name) . '-' . Str::random(5),
        'created_by' => $manager->id,
        'verification_status' => 'pending',
        'documents_status' => 'pending',
        'payment_status' => 'unpaid',
        'status' => 'pending',
        'is_active' => false,
    ]);

    return $manager;
}

/**
 * A paid member.
 *
 * The platform is paid to use, so `SboWorkspaceAccess::requiresPayment()` now
 * gates anyone without a confirmed registration fee — including someone who
 * owns no SBO at all, which used to be treated as "nothing owed". These tests
 * are about Business CRUD rather than about the gate (the three
 * `businessCrudUnpaidSboManager` tests below cover that), so their actor needs
 * to be on the paid side of it.
 */
function businessCrudPaidMember(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $name = 'Business Member SBO ' . Str::random(6);

    Sbo::create([
        'name' => $name,
        'slug' => Str::slug($name) . '-' . Str::random(5),
        'created_by' => $user->id,
        'verification_status' => 'approved',
        'documents_status' => 'compliant',
        'documents_compliant_at' => now(),
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
        'status' => 'active',
        'is_active' => true,
    ]);

    return $user;
}

beforeEach(function (): void {
    $this->user = businessCrudPaidMember();
    $this->category = Category::factory()->create();
});

describe('delete business', function (): void {
    it('allows owner to delete their business', function (): void {
        $business = Business::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
        ]);

        Livewire::actingAs($this->user)
            ->test(CreateBusinessModal::class)
            ->call('deleteBusiness', $business->id);

        expect(Business::find($business->id))->toBeNull();
    });

    it('prevents non-owner from deleting business', function (): void {
        $otherUser = User::factory()->create();
        $business = Business::factory()->create([
            'user_id' => $otherUser->id,
            'category_id' => $this->category->id,
        ]);

        Livewire::actingAs($this->user)
            ->test(CreateBusinessModal::class)
            ->call('deleteBusiness', $business->id)
            ->assertForbidden();

        expect(Business::find($business->id))->not->toBeNull();
    });

    it('dispatches business-deleted event after deletion', function (): void {
        $business = Business::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
        ]);

        Livewire::actingAs($this->user)
            ->test(CreateBusinessModal::class)
            ->call('deleteBusiness', $business->id)
            ->assertDispatched('business-deleted');
    });

    it('blocks unpaid sbo managers from deleting vendor listings directly', function (): void {
        $manager = businessCrudUnpaidSboManager();
        $business = Business::factory()->create([
            'user_id' => $manager->id,
            'category_id' => $this->category->id,
        ]);

        Livewire::actingAs($manager)
            ->test(CreateBusinessModal::class)
            ->call('deleteBusiness', $business->id)
            ->assertForbidden();

        expect(Business::find($business->id))->not->toBeNull();
    });
});

describe('edit business', function (): void {
    it('populates form when editing business', function (): void {
        $business = Business::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'name' => 'Test Business',
            'phone' => '+12025551234',
            'email' => 'test@example.com',
            'address' => '123 Test Street',
            'description' => 'Test description',
        ]);

        Livewire::actingAs($this->user)
            ->test(CreateBusinessModal::class)
            ->dispatch('edit-business', businessId: $business->id)
            ->assertSet('businessId', $business->id)
            ->assertSet('name', 'Test Business')
            ->assertSet('email', 'test@example.com')
            ->assertSet('address', '123 Test Street')
            ->assertSet('description', 'Test description');
    });

    it('blocks unpaid sbo managers from editing vendor listings directly', function (): void {
        $manager = businessCrudUnpaidSboManager();
        $business = Business::factory()->create([
            'user_id' => $manager->id,
            'category_id' => $this->category->id,
            'name' => 'Locked Business',
        ]);

        Livewire::actingAs($manager)
            ->test(CreateBusinessModal::class)
            ->dispatch('edit-business', businessId: $business->id)
            ->assertForbidden();
    });

    it('prevents non-owner from editing business', function (): void {
        $otherUser = User::factory()->create();
        $business = Business::factory()->create([
            'user_id' => $otherUser->id,
            'category_id' => $this->category->id,
            'name' => 'Other Business',
        ]);

        Livewire::actingAs($this->user)
            ->test(CreateBusinessModal::class)
            ->dispatch('edit-business', businessId: $business->id)
            ->assertForbidden();
    });

    it('updates business on save in edit mode', function (): void {
        $business = Business::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'name' => 'Original Name',
            'phone' => '+12025551234',
            'email' => 'test@example.com',
            'address' => '123 Test Street',
            'description' => 'Original description',
        ]);

        Livewire::actingAs($this->user)
            ->test(CreateBusinessModal::class)
            ->dispatch('edit-business', businessId: $business->id)
            ->set('name', 'Updated Name')
            ->set('description', 'Updated description')
            ->call('save')
            ->assertDispatched('business-saved');

        $business->refresh();
        expect($business->name)->toBe('Updated Name');
        expect($business->description)->toBe('Updated description');
    });

    it('resets form after save', function (): void {
        $business = Business::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'name' => 'Test Business',
            'phone' => '+12025551234',
            'email' => 'test@example.com',
            'address' => '123 Test Street',
            'description' => 'Test description',
        ]);

        Livewire::actingAs($this->user)
            ->test(CreateBusinessModal::class)
            ->dispatch('edit-business', businessId: $business->id)
            ->call('save')
            ->assertSet('businessId', null)
            ->assertSet('name', '');
    });
});

describe('create business', function (): void {
    it('creates new business when not in edit mode', function (): void {
        Livewire::actingAs($this->user)
            ->test(CreateBusinessModal::class)
            ->set('name', 'New Business')
            ->set('category_id', $this->category->id)
            ->set('phone', '+12025551234')
            ->set('email', 'new@example.com')
            ->set('address', '456 New Street')
            ->set('description', 'New business description')
            ->call('save')
            ->assertDispatched('business-saved');

        expect(Business::where('name', 'New Business')->exists())->toBeTrue();
    });

    it('blocks unpaid sbo managers from creating vendor listings directly', function (): void {
        $manager = businessCrudUnpaidSboManager();

        Livewire::actingAs($manager)
            ->test(CreateBusinessModal::class)
            ->set('name', 'Blocked Business')
            ->set('category_id', $this->category->id)
            ->set('phone', '+12025551234')
            ->set('email', 'blocked@example.com')
            ->set('address', '456 Locked Street')
            ->set('description', 'This listing should not be saved.')
            ->call('save')
            ->assertForbidden();

        expect(Business::where('name', 'Blocked Business')->exists())->toBeFalse();
    });
});

describe('business metadata fields', function (): void {
    it('saves staff_size when creating business', function (): void {
        Livewire::actingAs($this->user)
            ->test(CreateBusinessModal::class)
            ->set('name', 'Test Business')
            ->set('category_id', $this->category->id)
            ->set('phone', '+12025551234')
            ->set('email', 'test@example.com')
            ->set('address', '123 Test St')
            ->set('description', 'Test description')
            ->set('staff_size', '11-50')
            ->call('save')
            ->assertDispatched('business-saved');

        $business = Business::where('name', 'Test Business')->first();
        expect($business->staff_size)->toBe('11-50');
    });

    it('saves years_active when creating business', function (): void {
        Livewire::actingAs($this->user)
            ->test(CreateBusinessModal::class)
            ->set('name', 'Test Business')
            ->set('category_id', $this->category->id)
            ->set('phone', '+12025551234')
            ->set('email', 'test@example.com')
            ->set('address', '123 Test St')
            ->set('description', 'Test description')
            ->set('years_active', 10)
            ->call('save')
            ->assertDispatched('business-saved');

        $business = Business::where('name', 'Test Business')->first();
        expect($business->years_active)->toBe(10);
    });

    it('updates staff_size and years_active when editing', function (): void {
        $business = Business::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'name' => 'Test Business',
            'phone' => '+12025551234',
            'email' => 'test@example.com',
            'address' => '123 Test Street',
            'description' => 'Test description',
            'staff_size' => '1-10',
            'years_active' => 5,
        ]);

        Livewire::actingAs($this->user)
            ->test(CreateBusinessModal::class)
            ->dispatch('edit-business', businessId: $business->id)
            ->assertSet('staff_size', '1-10')
            ->assertSet('years_active', 5)
            ->set('staff_size', '51-200')
            ->set('years_active', 15)
            ->call('save')
            ->assertDispatched('business-saved');

        $business->refresh();
        expect($business->staff_size)->toBe('51-200');
        expect($business->years_active)->toBe(15);
    });

    it('allows null values for staff_size and years_active', function (): void {
        Livewire::actingAs($this->user)
            ->test(CreateBusinessModal::class)
            ->set('name', 'Test Business')
            ->set('category_id', $this->category->id)
            ->set('phone', '+12025551234')
            ->set('email', 'test@example.com')
            ->set('address', '123 Test St')
            ->set('description', 'Test description')
            ->set('staff_size', '')
            ->set('years_active', '')
            ->call('save')
            ->assertHasNoErrors(['staff_size', 'years_active']);

        $business = Business::where('name', 'Test Business')->first();
        expect($business->staff_size)->toBeNull();
        expect($business->years_active)->toBeNull();
    });

    it('validates staff_size must be in predefined list', function (): void {
        Livewire::actingAs($this->user)
            ->test(CreateBusinessModal::class)
            ->set('name', 'Test Business')
            ->set('category_id', $this->category->id)
            ->set('phone', '+12025551234')
            ->set('email', 'test@example.com')
            ->set('address', '123 Test St')
            ->set('description', 'Test description')
            ->set('staff_size', 'invalid-size')
            ->call('save')
            ->assertHasErrors(['staff_size']);
    });

    it('validates years_active must be integer', function (): void {
        Livewire::actingAs($this->user)
            ->test(CreateBusinessModal::class)
            ->set('name', 'Test Business')
            ->set('category_id', $this->category->id)
            ->set('phone', '+12025551234')
            ->set('email', 'test@example.com')
            ->set('address', '123 Test St')
            ->set('description', 'Test description')
            ->set('years_active', 'not-a-number')
            ->call('save')
            ->assertHasErrors(['years_active']);
    });

    it('validates years_active cannot be negative', function (): void {
        Livewire::actingAs($this->user)
            ->test(CreateBusinessModal::class)
            ->set('name', 'Test Business')
            ->set('category_id', $this->category->id)
            ->set('phone', '+12025551234')
            ->set('email', 'test@example.com')
            ->set('address', '123 Test St')
            ->set('description', 'Test description')
            ->set('years_active', -5)
            ->call('save')
            ->assertHasErrors(['years_active']);
    });

    it('validates years_active cannot exceed 200', function (): void {
        Livewire::actingAs($this->user)
            ->test(CreateBusinessModal::class)
            ->set('name', 'Test Business')
            ->set('category_id', $this->category->id)
            ->set('phone', '+12025551234')
            ->set('email', 'test@example.com')
            ->set('address', '123 Test St')
            ->set('description', 'Test description')
            ->set('years_active', 250)
            ->call('save')
            ->assertHasErrors(['years_active']);
    });
});
