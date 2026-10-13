<?php

declare(strict_types=1);

use App\Models\Business;
use App\Models\BusinessReport;
use App\Models\Category;
use App\Models\User;

beforeEach(function (): void {
    $this->category = Category::factory()->create();
    $this->business = Business::factory()->create([
        'category_id' => $this->category->id,
    ]);
});

test('authenticated users can submit a business report and it is persisted', function (): void {
    $reporter = User::factory()->create();

    $response = $this->actingAs($reporter)
        ->postJson(route('businesses.reports.store', $this->business->slug), [
            'reason' => BusinessReport::REASON_INACCURATE,
            'details' => 'The listed telephone number is no longer active.',
            'contact' => true,
        ]);

    $response->assertCreated()
        ->assertJsonPath('report.status', BusinessReport::STATUS_OPEN);

    $this->assertDatabaseHas('business_reports', [
        'business_id' => $this->business->id,
        'user_id' => $reporter->id,
        'reason' => BusinessReport::REASON_INACCURATE,
        'details' => 'The listed telephone number is no longer active.',
        'contact_requested' => true,
        'status' => BusinessReport::STATUS_OPEN,
    ]);
});

test('business report validation returns errors without losing the submitted form state client-side', function (): void {
    $reporter = User::factory()->create();

    $response = $this->actingAs($reporter)
        ->postJson(route('businesses.reports.store', $this->business->slug), [
            'reason' => 'not-a-valid-reason',
            'details' => str_repeat('x', 5001),
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['reason', 'details']);

    expect(BusinessReport::count())->toBe(0);
});

test('guests cannot submit a business report', function (): void {
    $this->postJson(route('businesses.reports.store', $this->business->slug), [
        'reason' => BusinessReport::REASON_SPAM,
    ])->assertUnauthorized();
});

test('business owners cannot report their own business', function (): void {
    $owner = User::factory()->create();
    $this->business->update(['user_id' => $owner->id]);

    $this->actingAs($owner)
        ->postJson(route('businesses.reports.store', $this->business->slug), [
            'reason' => BusinessReport::REASON_OTHER,
        ])
        ->assertForbidden();

    expect(BusinessReport::count())->toBe(0);
});
