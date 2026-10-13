<?php

declare(strict_types=1);

use App\Models\Report;
use App\Models\Sbo;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

test('admin report form persists the submitted report and metrics', function (): void {
    Notification::fake();

    $admin = User::factory()->superAdmin()->create();
    $name = 'Report Form SBO ' . Str::random(6);
    $sbo = Sbo::create([
        'name' => $name,
        'slug' => Str::slug($name) . '-' . Str::random(5),
        'created_by' => $admin->id,
        'verification_status' => 'approved',
    ]);

    Volt::actingAs($admin)
        ->test('admin.reports.index')
        ->set('sbo_id', $sbo->id)
        ->set('title', 'Quarterly performance report')
        ->set('description', 'Summary of the quarter performance.')
        ->set('period_start', '2026-04-01')
        ->set('period_end', '2026-06-30')
        ->set('metrics.revenue_growth', 12)
        ->call('createReport')
        ->assertHasNoErrors();

    $report = Report::query()->latest('id')->first();

    expect($report)->not->toBeNull()
        ->and($report->sbo_id)->toBe($sbo->id)
        ->and($report->admin_id)->toBe($admin->id)
        ->and($report->period_start->toDateString())->toBe('2026-04-01')
        ->and($report->period_end->toDateString())->toBe('2026-06-30')
        ->and($report->metrics['revenue_growth'])->toBe(12);
});

test('admin report form keeps entered fields after validation failure', function (): void {
    $admin = User::factory()->superAdmin()->create();

    $component = Volt::actingAs($admin)
        ->test('admin.reports.index')
        ->set('title', 'Keep this report title')
        ->set('description', 'Keep this report description')
        ->set('period_start', '2026-06-30')
        ->set('period_end', '2026-06-01')
        ->call('createReport')
        ->assertHasErrors(['sbo_id', 'period_end']);

    expect($component->get('title'))->toBe('Keep this report title')
        ->and($component->get('description'))->toBe('Keep this report description')
        ->and($component->get('period_start'))->toBe('2026-06-30')
        ->and($component->get('period_end'))->toBe('2026-06-01');

    expect(Report::count())->toBe(0);
});
