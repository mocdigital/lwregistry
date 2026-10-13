<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Livewire\CreateBusinessModal;
use App\Livewire\FavoriteButton;
use App\Livewire\GalleryUploader;
use App\Livewire\LogoUploader;
use App\Models\Business;
use App\Models\Category;
use App\Models\Sbo;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * A paid member — the platform is paid to use, and `CreateBusinessModal`
 * shares the same gate the routes do, so an actor without a confirmed
 * registration fee cannot open it at all. These tests are about the policy and
 * the locked properties, not about the gate.
 */
function businessAuthPaidMember(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $name = 'Business Auth SBO ' . Str::random(6);

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
    $this->user = businessAuthPaidMember();
    $this->otherUser = User::factory()->create();
    $this->admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $this->category = Category::factory()->create();
});

describe('Business Policy Authorization', function (): void {
    it('prevents unauthorized user from updating business', function (): void {
        $business = Business::factory()->create([
            'user_id' => $this->otherUser->id,
            'category_id' => $this->category->id,
        ]);

        Livewire::actingAs($this->user)
            ->test(CreateBusinessModal::class)
            ->dispatch('edit-business', businessId: $business->id)
            ->assertForbidden();
    });

    it('prevents unauthorized user from deleting business', function (): void {
        $business = Business::factory()->create([
            'user_id' => $this->otherUser->id,
            'category_id' => $this->category->id,
        ]);

        Livewire::actingAs($this->user)
            ->test(CreateBusinessModal::class)
            ->call('deleteBusiness', $business->id)
            ->assertForbidden();
    });

    it('prevents non-admin from verifying business', function (): void {
        $business = Business::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
        ]);

        expect(Gate::forUser($this->user)->allows('verify', $business))->toBeFalse();
    });

    it('allows admin to verify business', function (): void {
        $business = Business::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'is_verified' => false,
        ]);

        expect(Gate::forUser($this->admin)->allows('verify', $business))->toBeTrue();
    });
});

describe('Locked Properties', function (): void {
    it('prevents tampering with businessId in CreateBusinessModal', function (): void {
        $business = Business::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
        ]);

        $otherBusiness = Business::factory()->create([
            'user_id' => $this->otherUser->id,
            'category_id' => $this->category->id,
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(CreateBusinessModal::class)
            ->dispatch('edit-business', businessId: $business->id);

        // Attempt to change the locked property
        try {
            $component->set('businessId', $otherBusiness->id);
        } catch (Exception $e) {
            // Locked property should prevent modification
            expect($e)->toBeInstanceOf(Exception::class);
        }

        // Verify the original businessId is still set
        expect($component->get('businessId'))->toBe($business->id);
    });

    it('prevents tampering with businessId in FavoriteButton', function (): void {
        $business = Business::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
        ]);

        $otherBusiness = Business::factory()->create([
            'user_id' => $this->otherUser->id,
            'category_id' => $this->category->id,
        ]);

        $component = Livewire::actingAs($this->user)
            ->test(FavoriteButton::class, ['businessId' => $business->id]);

        // Attempt to change the locked property
        try {
            $component->set('businessId', $otherBusiness->id);
        } catch (Exception $e) {
            // Locked property should prevent modification
            expect($e)->toBeInstanceOf(Exception::class);
        }

        // Verify the original businessId is still set
        expect($component->get('businessId'))->toBe($business->id);
    });
});

describe('File Upload Authorization', function (): void {
    it('prevents unauthorized user from uploading gallery images', function (): void {
        $business = Business::factory()->create([
            'user_id' => $this->otherUser->id,
            'category_id' => $this->category->id,
        ]);

        Livewire::actingAs($this->user)
            ->test(GalleryUploader::class, ['business' => $business])
            ->set('image', Illuminate\Http\UploadedFile::fake()->image('test.jpg'))
            ->assertForbidden();
    });

    it('prevents unauthorized user from uploading logo', function (): void {
        $business = Business::factory()->create([
            'user_id' => $this->otherUser->id,
            'category_id' => $this->category->id,
        ]);

        Livewire::actingAs($this->user)
            ->test(LogoUploader::class, ['business' => $business])
            ->set('logo', Illuminate\Http\UploadedFile::fake()->image('logo.jpg'))
            ->assertForbidden();
    });
});
