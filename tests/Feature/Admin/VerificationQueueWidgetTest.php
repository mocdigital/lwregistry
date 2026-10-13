<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Sbo;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

beforeEach(function (): void {
    $this->admin = User::factory()->create(['role' => UserRole::ADMIN]);
});

function createSbosForQueue(int $count, string $verificationStatus): Illuminate\Support\Collection
{
    return collect(range(1, $count))->map(function (int $index) use ($verificationStatus): Sbo {
        $name = "Test SBO {$verificationStatus} {$index} " . Str::random(6);

        return Sbo::create([
            'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::random(5),
            'verification_status' => $verificationStatus,
            'category' => 'Technology',
            'primary_contact_name' => 'Test Contact',
            'primary_contact_email' => "contact{$index}-" . Str::random(6) . '@example.com',
            'created_by' => null,
        ]);
    });
}

describe('verification queue widget display', function (): void {
    it('displays correct pending count on mount', function (): void {
        createSbosForQueue(3, 'pending');
        createSbosForQueue(2, 'approved');

        Volt::test('admin.verification-queue-widget')
            ->assertSee('SBO Approval Queue')
            ->assertSee('Pending: 3')
            ->assertSee('Review');
    });

    it('displays zero when no pending SBOs', function (): void {
        createSbosForQueue(5, 'approved');

        Volt::test('admin.verification-queue-widget')
            ->assertSee('Pending: 0');
    });

    it('displays correct count with no SBOs', function (): void {
        Volt::test('admin.verification-queue-widget')
            ->assertSee('Pending: 0');
    });
});

describe('verification queue widget reactivity', function (): void {
    it('updates count when sbo-updated event is dispatched', function (): void {
        createSbosForQueue(3, 'pending');

        $component = Volt::test('admin.verification-queue-widget')
            ->assertSee('Pending: 3');

        $component->dispatch('sbo-updated')
            ->assertSee('Pending: 3');
    });

    it('decrements count after SBO approval', function (): void {
        $pendingSbos = createSbosForQueue(5, 'pending');

        $component = Volt::test('admin.verification-queue-widget')
            ->assertSee('Pending: 5');

        $pendingSbos->first()->update([
            'verification_status' => 'approved',
            'approved_by' => $this->admin->id,
            'date_approved' => now(),
        ]);

        $component->dispatch('sbo-updated')
            ->assertSee('Pending: 4');
    });

    it('handles multiple approvals correctly', function (): void {
        $pendingSbos = createSbosForQueue(10, 'pending');

        $component = Volt::test('admin.verification-queue-widget')
            ->assertSee('Pending: 10');

        foreach ($pendingSbos->take(3) as $sbo) {
            $sbo->update([
                'verification_status' => 'approved',
                'approved_by' => $this->admin->id,
                'date_approved' => now(),
            ]);

            $component->dispatch('sbo-updated');
        }

        $component->assertSee('Pending: 7');
    });
});

describe('verification queue widget links', function (): void {
    it('has review button linking to admin SBO approvals page', function (): void {
        Volt::test('admin.verification-queue-widget')
            ->assertSee(route('admin.sbos.index'));
    });
});
