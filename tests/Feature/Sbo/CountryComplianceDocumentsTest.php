<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Livewire\SboComplianceDocuments;
use App\Models\ComplianceDocumentType;
use App\Models\Country;
use App\Models\Sbo;
use App\Models\SboComplianceDocument;
use App\Models\User;
use App\Notifications\SboRegistrationReceivedNotification;
use App\Support\SboApplicationProgress;
use App\Support\SboComplianceRequirements;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

function countryComplianceUser(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => UserRole::SBO_MANAGER,
        'email_verified_at' => now(),
    ], $overrides));
}

function countryComplianceAdmin(): User
{
    return User::factory()->create([
        'role' => UserRole::SUPER_ADMIN,
        'email_verified_at' => now(),
    ]);
}

function countryComplianceSbo(User $manager, array $overrides = []): Sbo
{
    $name = $overrides['name'] ?? 'Country Compliance SBO ' . Str::random(6);

    return Sbo::create(array_merge([
        'name' => $name,
        'slug' => Str::slug($name) . '-' . Str::random(5),
        'created_by' => $manager->id,
        'country' => 'Nigeria',
        'verification_status' => 'approved',
        'documents_status' => 'pending',
        // Document upload is gated on the fee being settled, so these
        // compliance tests start from a paid application.
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
        'status' => 'pending',
        'is_active' => false,
    ], $overrides));
}

function countryComplianceDocument(string $name, ?Country $country = null, array $overrides = []): ComplianceDocumentType
{
    return ComplianceDocumentType::create(array_merge([
        'name' => $name,
        'description' => $name . ' guidance',
        'country_id' => $country?->id,
        'is_required' => true,
        'is_active' => true,
    ], $overrides));
}

test('nigeria sbo sees nigeria-specific cac requirements and global manual documents only', function (): void {
    $manager = countryComplianceUser();
    $nigeria = Country::where('name', 'Nigeria')->firstOrFail();
    $ghana = Country::where('name', 'Ghana')->firstOrFail();

    countryComplianceDocument('Global Code of Conduct');
    countryComplianceDocument('Nigeria CAC Certificate', $nigeria);
    countryComplianceDocument('Ghana Registrar Certificate', $ghana);

    // Fees paid so the (gated) documents step is reachable, then deep-link to it —
    // the country-specific document names only render on the documents step.
    $sbo = countryComplianceSbo($manager, [
        'country' => 'Nigeria',
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
    ]);

    $this->actingAs($manager)
        ->get(route('sbo.applications.progress', ['sbo' => $sbo, 'step' => 'documents']))
        ->assertOk()
        ->assertSee('Corporate Affairs Commission (CAC) Document')
        ->assertSee('Global Code of Conduct')
        ->assertSee('Nigeria CAC Certificate')
        ->assertDontSee('Ghana Registrar Certificate');
});

test('non nigeria sbo gets its own country requirements and global manual documents', function (): void {
    $manager = countryComplianceUser();
    $nigeria = Country::where('name', 'Nigeria')->firstOrFail();
    $ghana = Country::where('name', 'Ghana')->firstOrFail();

    countryComplianceDocument('Global Tax Attestation');
    countryComplianceDocument('Nigeria CAC Certificate', $nigeria);
    countryComplianceDocument('Ghana Registrar Certificate', $ghana);

    // Fees paid so the (gated) documents step is reachable, then deep-link to it —
    // the country-specific document names only render on the documents step.
    $sbo = countryComplianceSbo($manager, [
        'country' => 'Ghana',
        'payment_status' => 'paid',
        'payment_confirmed_at' => now(),
    ]);

    $this->actingAs($manager)
        ->get(route('sbo.applications.progress', ['sbo' => $sbo, 'step' => 'documents']))
        ->assertOk()
        ->assertSee('Office of the Registrar of Companies Registration Document')
        ->assertSee('Global Tax Attestation')
        ->assertSee('Ghana Registrar Certificate')
        ->assertDontSee('Nigeria CAC Certificate');
});

test('progress and compliance only require applicable country documents', function (): void {
    $manager = countryComplianceUser();
    $nigeria = Country::where('name', 'Nigeria')->firstOrFail();
    $ghana = Country::where('name', 'Ghana')->firstOrFail();

    $global = countryComplianceDocument('Global Profile Document');
    $seededGhana = ComplianceDocumentType::where('country_id', $ghana->id)
        ->where('is_seeded', true)
        ->firstOrFail();
    countryComplianceDocument('Nigeria CAC Certificate', $nigeria);
    $ghanaType = countryComplianceDocument('Ghana Registrar Certificate', $ghana);

    $sbo = countryComplianceSbo($manager, ['country' => 'Ghana']);

    SboComplianceDocument::create([
        'sbo_id' => $sbo->id,
        'compliance_document_type_id' => $global->id,
        'status' => 'approved',
    ]);

    $progress = SboApplicationProgress::for($sbo);

    expect($progress['required_documents_count'])->toBe(3);
    expect($progress['approved_required_documents_count'])->toBe(1);
    expect($sbo->documentsCompliant())->toBeFalse();

    SboComplianceDocument::create([
        'sbo_id' => $sbo->id,
        'compliance_document_type_id' => $seededGhana->id,
        'status' => 'approved',
    ]);

    SboComplianceDocument::create([
        'sbo_id' => $sbo->id,
        'compliance_document_type_id' => $ghanaType->id,
        'status' => 'approved',
    ]);

    expect($sbo->fresh()->documentsCompliant())->toBeTrue();
});

test('registration received email lists required documents for selected country', function (): void {
    $manager = countryComplianceUser(['firstname' => 'Ada']);
    $nigeria = Country::where('name', 'Nigeria')->firstOrFail();
    $ghana = Country::where('name', 'Ghana')->firstOrFail();

    countryComplianceDocument('Global Compliance Form');
    countryComplianceDocument('Nigeria CAC Certificate', $nigeria);
    countryComplianceDocument('Ghana Registrar Certificate', $ghana);

    $sbo = countryComplianceSbo($manager, ['country' => 'Nigeria']);

    $mail = (new SboRegistrationReceivedNotification($sbo))->toMail($manager);
    $intro = collect($mail->introLines)->flatten()->join("\n");

    expect($intro)->toContain('Global Compliance Form');
    expect($intro)->toContain('Corporate Affairs Commission (CAC) Document');
    expect($intro)->toContain('Nigeria CAC Certificate');
    expect($intro)->not->toContain('Ghana Registrar Certificate');
});

test('admin sbo review page lists only applicable documents for the sbo country', function (): void {
    $manager = countryComplianceUser();
    $admin = countryComplianceAdmin();
    $nigeria = Country::where('name', 'Nigeria')->firstOrFail();
    $ghana = Country::where('name', 'Ghana')->firstOrFail();

    countryComplianceDocument('Global Board Approval');
    countryComplianceDocument('Nigeria CAC Certificate', $nigeria);
    countryComplianceDocument('Ghana Registrar Certificate', $ghana);

    $sbo = countryComplianceSbo($manager, ['country' => 'Nigeria']);

    Livewire::actingAs($admin)
        ->test('admin.sbos.show', ['sbo' => $sbo])
        ->assertSee('Corporate Affairs Commission (CAC) Document')
        ->assertSee('Global Board Approval')
        ->assertSee('Nigeria CAC Certificate')
        ->assertDontSee('Ghana Registrar Certificate');
});

test('admin can create and edit country-scoped document requirements with source metadata', function (): void {
    $admin = countryComplianceAdmin();
    $nigeria = Country::where('name', 'Nigeria')->firstOrFail();

    Livewire::actingAs($admin)
        ->test('admin.compliance.documents')
        ->set('name', 'Nigeria Annual Return')
        ->set('description', 'Upload the latest annual return.')
        ->set('countryId', (string) $nigeria->id)
        ->set('registryAuthority', 'Corporate Affairs Commission')
        ->set('sourceUrl', 'https://www.cac.gov.ng/')
        ->set('sourceNote', 'Admin-provided requirement.')
        ->call('create')
        ->assertHasNoErrors();

    $type = ComplianceDocumentType::where('name', 'Nigeria Annual Return')->firstOrFail();

    expect($type->country_id)->toBe($nigeria->id);
    expect($type->registry_authority)->toBe('Corporate Affairs Commission');
    expect($type->source_url)->toBe('https://www.cac.gov.ng/');
    expect($type->source_note)->toBe('Admin-provided requirement.');

    Livewire::actingAs($admin)
        ->test('admin.compliance.documents')
        ->call('edit', $type->id)
        ->set('editName', 'Annual Return')
        ->set('editCountryId', '')
        ->set('editNeedsAdminReview', false)
        ->call('update')
        ->assertHasNoErrors();

    $type->refresh();

    expect($type->name)->toBe('Annual Return');
    expect($type->country_id)->toBeNull();
    expect($type->needs_admin_review)->toBeFalse();
});

test('every country has at least one active seeded country-specific requirement', function (): void {
    $countries = Country::query()->get();

    $missing = $countries
        ->filter(fn (Country $country): bool => ComplianceDocumentType::query()
            ->where('country_id', $country->id)
            ->where('is_seeded', true)
            ->where('is_active', true)
            ->doesntExist())
        ->pluck('name')
        ->all();

    expect($missing)->toBeEmpty();

    expect(
        ComplianceDocumentType::query()
            ->where('name', 'Business Registration Document')
            ->whereNull('country_id')
            ->exists()
    )->toBeFalse();
});

test('admin can filter seeded requirements that need review', function (): void {
    $admin = countryComplianceAdmin();
    $unitedStates = Country::where('name', 'United States')->firstOrFail();

    Livewire::actingAs($admin)
        ->test('admin.compliance.documents')
        ->set('countryFilter', (string) $unitedStates->id)
        ->set('seedFilter', 'seeded')
        ->set('reviewFilter', 'needs_review')
        ->assertSee('Secretary of State Formation / Registration Document')
        ->assertSee('Needs review');
});

test('sbo cannot submit a document requirement from another country', function (): void {
    Storage::fake('compliance');

    $manager = countryComplianceUser();
    $ghana = Country::where('name', 'Ghana')->firstOrFail();
    $ghanaType = countryComplianceDocument('Ghana Registrar Certificate', $ghana);
    $sbo = countryComplianceSbo($manager, ['country' => 'Nigeria']);
    $requiredTypes = SboComplianceRequirements::requiredFor($sbo);

    $component = Livewire::actingAs($manager)
        ->test(SboComplianceDocuments::class, ['sboId' => $sbo->id]);

    foreach ($requiredTypes as $type) {
        $component->set(
            'uploads.' . $type->id,
            UploadedFile::fake()->image('required-document-' . $type->id . '.jpg'),
        );
    }

    $component
        ->set('uploads.' . $ghanaType->id, UploadedFile::fake()->image('ghana-doc.jpg'))
        ->call('submit')
        ->assertHasNoErrors()
        ->assertDispatched('compliance-documents-saved');

    expect(SboComplianceDocument::where('sbo_id', $sbo->id)
        ->where('compliance_document_type_id', $ghanaType->id)
        ->exists())->toBeFalse();
});

test('sbo submission keeps selected files until all required documents are ready', function (): void {
    Storage::fake('compliance');

    $manager = countryComplianceUser();
    $nigeria = Country::where('name', 'Nigeria')->firstOrFail();
    $firstType = countryComplianceDocument('Stateful CAC Certificate', $nigeria);
    $secondType = countryComplianceDocument('Stateful Tax Certificate', $nigeria);
    $sbo = countryComplianceSbo($manager, ['country' => 'Nigeria']);

    $component = Livewire::actingAs($manager)
        ->test(SboComplianceDocuments::class, ['sboId' => $sbo->id])
        ->set('uploads.' . $firstType->id, UploadedFile::fake()->image('cac.jpg'))
        ->call('submit')
        ->assertHasErrors('uploads.' . $secondType->id);

    expect($component->get('uploads'))->toHaveKey($firstType->id)
        ->and(SboComplianceDocument::where('sbo_id', $sbo->id)
            ->where('compliance_document_type_id', $firstType->id)
            ->exists())->toBeFalse();
});

test('sbo submits selected required documents together from the workflow action', function (): void {
    Storage::fake('compliance');

    $manager = countryComplianceUser();
    $sbo = countryComplianceSbo($manager, ['country' => 'Nigeria']);
    $requiredTypes = SboComplianceRequirements::requiredFor($sbo);

    expect($requiredTypes)->not->toBeEmpty();

    $component = Livewire::actingAs($manager)
        ->test(SboComplianceDocuments::class, ['sboId' => $sbo->id]);

    foreach ($requiredTypes as $type) {
        $component->set(
            'uploads.' . $type->id,
            UploadedFile::fake()->image('required-document-' . $type->id . '.jpg'),
        );
    }

    $component
        ->call('submit')
        ->assertHasNoErrors()
        ->assertDispatched('document-uploaded')
        ->assertDispatched('compliance-documents-saved')
        ->assertDispatched('ui-toast');

    expect($component->get('uploads'))->toBeEmpty();
    expect($sbo->fresh()->documentsCompliant())->toBeTrue();

    foreach ($requiredTypes as $type) {
        $document = SboComplianceDocument::where('sbo_id', $sbo->id)
            ->where('compliance_document_type_id', $type->id)
            ->first();

        expect($document)->not->toBeNull()
            ->and($document->status)->toBe('approved')
            ->and($document->file())->not->toBeNull();
    }
});

test('sbo only sees and accepts backend-required country document fields', function (): void {
    Storage::fake('compliance');

    $manager = countryComplianceUser();
    $nigeria = Country::where('name', 'Nigeria')->firstOrFail();
    $cacType = countryComplianceDocument('Corporate Affairs Commission (CAC) Document', $nigeria);
    $tinType = countryComplianceDocument('Tax Identification Number (TIN) Document', $nigeria, ['is_required' => false]);
    $sbo = countryComplianceSbo($manager, ['country' => 'Nigeria']);
    $requiredTypes = SboComplianceRequirements::requiredFor($sbo);

    $component = Livewire::actingAs($manager)
        ->test(SboComplianceDocuments::class, ['sboId' => $sbo->id]);

    foreach ($requiredTypes as $type) {
        $component->set(
            'uploads.' . $type->id,
            UploadedFile::fake()->image('required-document-' . $type->id . '.jpg'),
        );
    }

    $component
        ->set('uploads.' . $tinType->id, UploadedFile::fake()->image('tin.png'))
        ->call('submit')
        ->assertHasNoErrors()
        ->assertDispatched('compliance-documents-saved')
        ->assertSee($cacType->name)
        ->assertDontSee($tinType->name);

    expect($component->get('uploads'))->toBeEmpty();
    expect(SboComplianceDocument::where('sbo_id', $sbo->id)
        ->where('compliance_document_type_id', $cacType->id)
        ->exists())->toBeTrue();
    expect(SboComplianceDocument::where('sbo_id', $sbo->id)
        ->where('compliance_document_type_id', $tinType->id)
        ->exists())->toBeFalse();
});

test('sbo can upload a png compliance scan and receives success feedback', function (): void {
    Storage::fake('compliance');

    $manager = countryComplianceUser();
    $nigeria = Country::where('name', 'Nigeria')->firstOrFail();
    $type = countryComplianceDocument('PNG CAC Certificate', $nigeria);
    $sbo = countryComplianceSbo($manager, ['country' => 'Nigeria']);
    $requiredTypes = SboComplianceRequirements::requiredFor($sbo);

    $component = Livewire::actingAs($manager)
        ->test(SboComplianceDocuments::class, ['sboId' => $sbo->id]);

    foreach ($requiredTypes as $requiredType) {
        $component->set(
            'uploads.' . $requiredType->id,
            UploadedFile::fake()->image('required-document-' . $requiredType->id . '.png'),
        );
    }

    $component
        ->call('submit')
        ->assertHasNoErrors()
        ->assertDispatched('ui-toast');

    $document = SboComplianceDocument::where('sbo_id', $sbo->id)
        ->where('compliance_document_type_id', $type->id)
        ->firstOrFail();

    expect($document->file())->not->toBeNull();
    Storage::disk('compliance')->assertExists($document->file()->getPathRelativeToRoot());
});
