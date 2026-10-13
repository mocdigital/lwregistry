<?php

declare(strict_types=1);

use App\Models\ComplianceDocumentType;
use App\Models\Sbo;
use App\Models\SboComplianceDocument;
use App\Models\User;
use App\Support\SboApplicationProgress;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Whether a submitted compliance document is visible as work waiting.
 *
 * SboApplicationProgress only ever counted APPROVED documents, so an applicant
 * who had uploaded and was waiting produced exactly the same summary as one who
 * had uploaded nothing: "0 of 1 required documents approved". The step
 * description told the reader to "upload the required documents and wait for
 * approval" — copy written for the applicant, rendered on the admin screen to
 * the person whose job was to do the approving.
 */

function reviewSbo(): Sbo
{
    $owner = User::factory()->create(['email_verified_at' => now()]);

    return Sbo::create([
        'name' => 'Review Co ' . Str::random(4),
        'slug' => 'review-co-' . Str::random(6),
        'created_by' => $owner->id,
        'verification_status' => 'approved',
        'documents_status' => 'pending',
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
        'status' => 'active',
        'is_active' => true,
    ]);
}

/**
 * A required document type that applies to every country.
 *
 * Created fresh rather than reused: the test database is seeded with 250 real
 * types, and the obvious names are already taken by country-scoped rows — the
 * CAC document belongs to Nigeria, so it does not apply to an SBO with no
 * country and the requirement list comes back empty.
 */
function requiredType(): ComplianceDocumentType
{
    return ComplianceDocumentType::create([
        'name' => 'Test Registration Certificate ' . Str::random(6),
        'is_required' => true,
        'is_active' => true,
        'country_id' => null,
    ]);
}

test('an uploaded document is counted as awaiting review, not just "not approved"', function (): void {
    Storage::fake('compliance');

    $sbo = reviewSbo();
    $type = requiredType();

    $document = SboComplianceDocument::create([
        'sbo_id' => $sbo->id,
        'compliance_document_type_id' => $type->id,
        'status' => 'pending',
    ]);
    // A real image rather than a zero-byte fake: the collection validates the
    // actual mime type, and an empty file reports as application/x-empty.
    $document->addMedia(UploadedFile::fake()->image('cac.jpg', 800, 600))
        ->toMediaCollection(SboComplianceDocument::COLLECTION);

    $progress = SboApplicationProgress::for($sbo->fresh());

    expect($progress['pending_required_documents_count'])->toBe(1)
        ->and($progress['approved_required_documents_count'])->toBe(0);

    // The line an admin reads must say someone is waiting on them.
    $documents = collect($progress['steps'])->firstWhere('key', 'documents');

    expect($documents['description'])->toBe('1 document submitted and awaiting review.')
        ->and($documents['description'])->not->toContain('Upload the required documents');
});

test('with nothing uploaded the copy still asks for an upload', function (): void {
    $sbo = reviewSbo();
    requiredType();

    $progress = SboApplicationProgress::for($sbo);
    $documents = collect($progress['steps'])->firstWhere('key', 'documents');

    expect($progress['pending_required_documents_count'])->toBe(0)
        ->and($documents['description'])->toBe('Upload the required documents and wait for approval.');
});

test('a rejected document says it needs attention rather than asking for a first upload', function (): void {
    $sbo = reviewSbo();
    $type = requiredType();

    SboComplianceDocument::create([
        'sbo_id' => $sbo->id,
        'compliance_document_type_id' => $type->id,
        'status' => 'rejected',
        'rejection_reason' => 'Illegible scan.',
    ]);

    $documents = collect(SboApplicationProgress::for($sbo->fresh())['steps'])
        ->firstWhere('key', 'documents');

    expect($documents['description'])->toBe('1 document needs attention and must be re-uploaded.');
});

test('once approved and marked compliant the step reads complete', function (): void {
    $sbo = reviewSbo();
    $type = requiredType();

    SboComplianceDocument::create([
        'sbo_id' => $sbo->id,
        'compliance_document_type_id' => $type->id,
        'status' => 'approved',
    ]);

    $sbo->forceFill([
        'documents_status' => 'compliant',
        'documents_compliant_at' => now(),
    ])->save();

    $progress = SboApplicationProgress::for($sbo->fresh());
    $documents = collect($progress['steps'])->firstWhere('key', 'documents');

    expect($documents['done'])->toBeTrue()
        ->and($documents['description'])->toBe('All required compliance documents are approved.')
        ->and($progress['approved_required_documents_count'])->toBe(1);
});
