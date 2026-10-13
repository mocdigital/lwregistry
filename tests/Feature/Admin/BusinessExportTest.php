<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Sbo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * Exporting the admin business list as CSV, Excel or PDF — scoped and filtered
 * the same way the Organizations screen is.
 */

function exportAdmin(): User
{
    return User::factory()->create([
        'role' => UserRole::SUPER_ADMIN,
        'email_verified_at' => now(),
    ]);
}

function exportSbo(array $overrides = []): Sbo
{
    $name = $overrides['name'] ?? 'Biz ' . Str::random(5);

    return Sbo::create(array_merge([
        'name' => $name,
        'slug' => Str::slug($name) . '-' . Str::random(4),
        'created_by' => User::factory()->create()->id,
        'category' => 'Software Development',
        'industry' => 'Technology',
        'verification_status' => 'approved',
        'status' => 'active',
        'is_active' => true,
    ], $overrides));
}

test('the CSV export downloads the business list', function (): void {
    exportSbo(['name' => 'Acme Exports']);

    $response = $this->actingAs(exportAdmin())
        ->get(route('admin.sbos.export', ['format' => 'csv']))
        ->assertOk();

    expect($response->headers->get('content-type'))->toContain('text/csv')
        ->and($response->streamedContent())->toContain('Business')->toContain('Acme Exports');
});

test('the Excel export is served as a spreadsheet', function (): void {
    exportSbo(['name' => 'Spreadsheet Biz']);

    $response = $this->actingAs(exportAdmin())
        ->get(route('admin.sbos.export', ['format' => 'xlsx']))
        ->assertOk();

    expect($response->headers->get('content-type'))->toContain('vnd.ms-excel')
        ->and($response->getContent())->toContain('Spreadsheet Biz');
});

test('the PDF export downloads a PDF', function (): void {
    exportSbo();

    $response = $this->actingAs(exportAdmin())
        ->get(route('admin.sbos.export', ['format' => 'pdf']))
        ->assertOk();

    expect($response->headers->get('content-type'))->toContain('application/pdf');
});

test('the status filter carries into the export', function (): void {
    exportSbo(['name' => 'Approved One', 'verification_status' => 'approved']);
    exportSbo(['name' => 'Pending One', 'verification_status' => 'pending']);

    $content = $this->actingAs(exportAdmin())
        ->get(route('admin.sbos.export', ['format' => 'csv', 'status' => 'approved']))
        ->streamedContent();

    expect($content)->toContain('Approved One')->not->toContain('Pending One');
});

test('the SBO Directory export route works and search narrows it', function (): void {
    exportSbo(['name' => 'Findable Ltd']);
    exportSbo(['name' => 'Other Co']);

    $content = $this->actingAs(exportAdmin())
        ->get(route('admin.businesses.export', ['format' => 'csv', 'search' => 'Findable']))
        ->streamedContent();

    expect($content)->toContain('Findable Ltd')->not->toContain('Other Co');
});

test('a member cannot reach the export', function (): void {
    $this->actingAs(paidMember())
        ->get(route('admin.sbos.export', ['format' => 'csv']))
        ->assertForbidden();
});
