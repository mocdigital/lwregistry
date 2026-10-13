<?php

declare(strict_types=1);

use App\Models\Sbo;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

afterEach(function (): void {
    Auth::logout();
});

test('admin add user validates invalid phone numbers instead of crashing', function (): void {
    $admin = User::factory()->superAdmin()->create();

    $this->actingAs($admin);

    Volt::test('admin.users.index')
        ->set('newUserFirstName', 'Backend')
        ->set('newUserLastName', 'Tester')
        ->set('newUserEmail', 'backend.invalid-phone@example.com')
        ->set('newUserPhone', '12345')
        ->set('newUserPassword', 'Password123')
        ->set('newUserPasswordConfirmation', 'Password123')
        ->call('createUser')
        ->assertHasErrors(['newUserPhone']);

    assertDatabaseMissing('users', [
        'email' => 'backend.invalid-phone@example.com',
    ]);
});

test('admin can add a user with a valid international phone number', function (): void {
    $admin = User::factory()->superAdmin()->create();

    $this->actingAs($admin);

    Volt::test('admin.users.index')
        ->set('newUserFirstName', 'Backend')
        ->set('newUserLastName', 'User')
        ->set('newUserEmail', 'backend.valid-phone@example.com')
        ->set('newUserPhone', '+14155552671')
        ->set('newUserPassword', 'Password123')
        ->set('newUserPasswordConfirmation', 'Password123')
        ->call('createUser')
        ->assertHasNoErrors();

    assertDatabaseHas('users', [
        'email' => 'backend.valid-phone@example.com',
        'phone' => '+14155552671',
    ]);
});

test('admin can suspend and reactivate a user', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $target = User::factory()->create();

    $this->actingAs($admin);

    Volt::test('admin.users.index')
        ->call('suspendUser', $target->id)
        ->assertHasNoErrors();

    expect($target->fresh()->isSuspended())->toBeTrue();

    $this->actingAs($target)
        ->get(route('dashboard'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Your account has been suspended. Please contact support if you believe this is an error.');

    $this->actingAs($admin);

    Volt::test('admin.users.index')
        ->call('reactivateUser', $target->id)
        ->assertHasNoErrors();

    expect($target->fresh()->isSuspended())->toBeFalse();
});

test('suspended users cannot log in', function (): void {
    $target = User::factory()->create([
        'email' => 'suspended-login@example.com',
        'password' => Hash::make('Password123'),
        'suspended_at' => now(),
    ]);

    Volt::test('auth.login')
        ->set('email', $target->email)
        ->set('password', 'Password123')
        ->call('login')
        ->assertHasErrors(['email']);

    $this->assertGuest();
});

test('admin can delete a user without core dependencies', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $target = User::factory()->create();

    $this->actingAs($admin);

    Volt::test('admin.users.index')
        ->call('deleteUser', $target->id)
        ->assertHasNoErrors();

    assertDatabaseMissing('users', [
        'id' => $target->id,
    ]);
});

test('admin cannot delete a user with managed sbos', function (): void {
    $admin = User::factory()->superAdmin()->create();
    $target = User::factory()->create();
    $name = 'Delete Blocked SBO ' . Str::random(6);

    Sbo::create([
        'name' => $name,
        'slug' => Str::slug($name),
        'created_by' => $target->id,
        'verification_status' => 'pending',
        'documents_status' => 'pending',
        'payment_status' => 'unpaid',
        'status' => 'pending',
        'is_active' => false,
    ]);

    $this->actingAs($admin);

    Volt::test('admin.users.index')
        ->call('deleteUser', $target->id)
        ->assertHasNoErrors();

    assertDatabaseHas('users', [
        'id' => $target->id,
    ]);
});

test('the users list labels a member\'s businesses by entity type', function (): void {
    $admin = User::factory()->superAdmin()->create();

    $cobOwner = User::factory()->create([
        'firstname' => 'Cob', 'lastname' => 'Owner',
        'email' => 'cob.owner@example.test', 'email_verified_at' => now(),
    ]);

    foreach (range(1, 2) as $i) {
        Sbo::create([
            'name' => 'Cob Biz ' . $i,
            'slug' => 'cob-biz-' . $i . '-' . Str::random(5),
            'created_by' => $cobOwner->id,
            'entity_type' => 'COB',
            'verification_status' => 'pending',
            'status' => 'active',
            'is_active' => true,
            'payment_status' => 'unpaid',
        ]);
    }

    $this->actingAs($admin);

    // A COB owner used to read as owning "2 SBOS".
    Volt::test('admin.users.index')
        ->set('search', 'cob.owner@example.test')
        ->assertSee('2 COBS')
        ->assertDontSee('2 SBOS');
});
