<?php

declare(strict_types=1);

use App\Models\Sbo;
use App\Models\SboConnection;
use App\Models\SboConversation;
use App\Models\SboMessage;
use App\Models\SboNetworkReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * Reporting a business, and reporting a message.
 *
 * The profile drew no report control at all, and `sbo_network_reports` — which
 * the admin's Network Reports queue already reads — had nothing writing to it
 * from the front end.
 *
 * Only a verified business may report: a report puts a listing in front of a
 * reviewer and can end in suspension, so it is not a power an account gets on
 * the day it signs up.
 */

function reportingSbo(array $overrides = []): Sbo
{
    $name = $overrides['name'] ?? 'Reporting SBO ' . Str::random(5);
    $owner = $overrides['owner'] ?? User::factory()->create(['email_verified_at' => now()]);

    unset($overrides['owner']);

    return Sbo::create(array_merge([
        'name' => $name,
        'slug' => Str::slug($name) . '-' . Str::random(5),
        'created_by' => $owner->id,
        'category' => 'Software Development',
        'verification_status' => 'approved',
        'documents_status' => 'compliant',
        'documents_compliant_at' => now(),
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
        'status' => 'active',
        'is_active' => true,
    ], $overrides));
}

/** Paid, so past the paywall — but not verified, so it cannot report. */
function unverifiedReporter(): User
{
    $owner = User::factory()->create(['email_verified_at' => now()]);

    reportingSbo([
        'owner' => $owner,
        'name' => 'Unverified Reporter',
        'verification_status' => 'pending',
        'documents_status' => 'pending',
        'documents_compliant_at' => null,
    ]);

    return $owner;
}

test('a verified business can report a listing', function (): void {
    $reporterOwner = User::factory()->create(['email_verified_at' => now()]);
    $reporter = reportingSbo(['owner' => $reporterOwner, 'name' => 'Good Citizen']);
    $target = reportingSbo(['name' => 'Suspect Business']);

    $this->actingAs($reporterOwner)
        ->post(route('sbo.reports.store', $target->slug), [
            'reason' => 'misrepresentation',
            'details' => 'They claim a certification they do not hold, per their profile.',
        ])
        ->assertRedirect()
        ->assertSessionHas('status', 'report-submitted');

    $report = SboNetworkReport::firstOrFail();

    expect($report->reporter_sbo_id)->toBe($reporter->id)
        ->and($report->reported_sbo_id)->toBe($target->id)
        ->and($report->status)->toBe(SboNetworkReport::STATUS_OPEN)
        // Stored as the label, which is what the admin queue groups by.
        ->and($report->reason)->toContain('Misrepresenting');
});

test('an unverified business cannot report', function (): void {
    $target = reportingSbo(['name' => 'Target']);

    // Held at the platform gate before the report action — access waits for
    // admin verification, not just payment.
    $this->actingAs(unverifiedReporter())
        ->post(route('sbo.reports.store', $target->slug), [
            'reason' => 'spam',
            'details' => 'This is a long enough detail string to pass validation.',
        ])
        ->assertRedirect(route('sbo.payment-required'));

    expect(SboNetworkReport::count())->toBe(0);
});

test('you cannot report your own listing', function (): void {
    $owner = User::factory()->create(['email_verified_at' => now()]);
    $mine = reportingSbo(['owner' => $owner, 'name' => 'My Own Listing']);

    $this->actingAs($owner)
        ->post(route('sbo.reports.store', $mine->slug), [
            'reason' => 'spam',
            'details' => 'This is a long enough detail string to pass validation.',
        ])
        ->assertForbidden();
});

test('the same reporter cannot bury the queue with duplicates', function (): void {
    $owner = User::factory()->create(['email_verified_at' => now()]);
    reportingSbo(['owner' => $owner, 'name' => 'Reporter']);
    $target = reportingSbo(['name' => 'Target']);

    $payload = [
        'reason' => 'fraud',
        'details' => 'A payment was taken and no goods were delivered to us.',
    ];

    $this->actingAs($owner)->post(route('sbo.reports.store', $target->slug), $payload);
    $this->actingAs($owner)
        ->post(route('sbo.reports.store', $target->slug), $payload)
        ->assertSessionHas('status', 'report-already-open');

    expect(SboNetworkReport::count())->toBe(1);
});

test('a report needs a real reason and enough detail to act on', function (): void {
    $owner = User::factory()->create(['email_verified_at' => now()]);
    reportingSbo(['owner' => $owner, 'name' => 'Reporter']);
    $target = reportingSbo(['name' => 'Target']);

    $this->actingAs($owner)
        ->post(route('sbo.reports.store', $target->slug), [
            'reason' => 'because-i-say-so',
            'details' => 'This is long enough to pass the length rule.',
        ])
        ->assertSessionHasErrors('reason');

    $this->actingAs($owner)
        ->post(route('sbo.reports.store', $target->slug), ['reason' => 'spam', 'details' => 'bad'])
        ->assertSessionHasErrors('details');
});

test('the profile only offers the control to someone who may use it', function (): void {
    $target = reportingSbo(['name' => 'Target Listing']);

    $verified = User::factory()->create(['email_verified_at' => now()]);
    reportingSbo(['owner' => $verified, 'name' => 'Verified Viewer']);

    $response = $this->actingAs($verified)->get(route('businesses.show', $target->slug))->assertOk();
    expect(inertiaProp($response, 'report.canReport'))->toBeTrue();

    // Paid but not verified: now walled from the profile entirely — platform
    // access waits for admin verification.
    $this->actingAs(unverifiedReporter())
        ->get(route('businesses.show', $target->slug))
        ->assertRedirect(route('sbo.payment-required'));

    // Your own listing is not something to report.
    $ownerResponse = $this->actingAs($target->creator)
        ->get(route('businesses.show', $target->slug))
        ->assertOk();
    expect(inertiaProp($ownerResponse, 'report.isOwn'))->toBeTrue();
});

/* ------------------------------------------------------ reporting chat --- */

/** @return array{0: User, 1: Sbo, 2: SboMessage} */
function reportableMessage(): array
{
    $mineOwner = User::factory()->create(['email_verified_at' => now()]);
    $mine = reportingSbo(['owner' => $mineOwner, 'name' => 'Me']);

    $theirsOwner = User::factory()->create(['email_verified_at' => now()]);
    $theirs = reportingSbo(['owner' => $theirsOwner, 'name' => 'Them']);

    $connection = SboConnection::create([
        'requester_sbo_id' => $mine->id,
        'recipient_sbo_id' => $theirs->id,
        'status' => SboConnection::STATUS_ACCEPTED,
        'responded_at' => now(),
    ]);

    $conversation = SboConversation::create([
        'connection_id' => $connection->id,
        'created_by_sbo_id' => $theirs->id,
        'type' => SboConversation::TYPE_DIRECT,
        'last_message_at' => now(),
    ]);

    $message = SboMessage::create([
        'conversation_id' => $conversation->id,
        'sbo_id' => $theirs->id,
        'user_id' => $theirsOwner->id,
        'body' => 'Something worth reporting',
    ]);

    return [$mineOwner, $mine, $message];
}

test('a message in your conversation can be reported', function (): void {
    [$owner, $mine, $message] = reportableMessage();

    $this->actingAs($owner)
        ->post(route('sbo.messages.report', $message), [
            'reason' => 'conduct',
            'details' => 'Abusive language in the thread.',
        ])
        ->assertRedirect()
        ->assertSessionHas('status', 'report-submitted');

    $report = SboNetworkReport::firstOrFail();

    // `message_id` has been a column all along with nothing writing to it; the
    // admin queue renders the reported message when there is one.
    expect($report->message_id)->toBe($message->id)
        ->and($report->reporter_sbo_id)->toBe($mine->id)
        ->and($report->reported_sbo_id)->toBe($message->sbo_id);
});

test('you cannot report a message in a conversation you are not in', function (): void {
    [, , $message] = reportableMessage();

    $stranger = User::factory()->create(['email_verified_at' => now()]);
    reportingSbo(['owner' => $stranger, 'name' => 'Stranger']);

    $this->actingAs($stranger)
        ->post(route('sbo.messages.report', $message), ['reason' => 'spam'])
        ->assertForbidden();

    expect(SboNetworkReport::count())->toBe(0);
});

test('you cannot report your own message', function (): void {
    [$owner, $mine, $message] = reportableMessage();

    $own = SboMessage::create([
        'conversation_id' => $message->conversation_id,
        'sbo_id' => $mine->id,
        'user_id' => $owner->id,
        'body' => 'My own message',
    ]);

    $this->actingAs($owner)
        ->post(route('sbo.messages.report', $own), ['reason' => 'spam'])
        ->assertForbidden();
});

test('the chat only offers reporting to a verified business', function (): void {
    [$owner] = reportableMessage();

    $response = $this->actingAs($owner)
        ->get(route('sbo.network.index', ['tab' => 'messages']))
        ->assertOk();

    expect(inertiaProp($response, 'canReport'))->toBeTrue()
        ->and(inertiaProp($response, 'reportReasons'))->not->toBeEmpty();
});
