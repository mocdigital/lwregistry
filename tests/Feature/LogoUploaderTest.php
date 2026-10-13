<?php

declare(strict_types=1);

use App\Livewire\LogoUploader;
use App\Models\Business;
use App\Models\Category;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    Storage::fake('public');
    $this->user = User::factory()->create();
    $this->category = Category::factory()->create();
    $this->business = Business::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $this->category->id,
    ]);
});

describe('logo upload authorization', function (): void {
    it('allows owner to upload logo', function (): void {
        $file = UploadedFile::fake()->image('logo.jpg', 200, 200);

        Livewire::actingAs($this->user)
            ->test(LogoUploader::class, ['business' => $this->business])
            ->set('logo', $file)
            ->assertHasNoErrors()
            ->assertDispatched('logo-updated');

        $this->business->refresh();
        expect($this->business->hasMedia('logo'))->toBeTrue();
    });

    it('prevents non-owner from uploading logo', function (): void {
        $otherUser = User::factory()->create();
        $file = UploadedFile::fake()->image('logo.jpg', 200, 200);

        Livewire::actingAs($otherUser)
            ->test(LogoUploader::class, ['business' => $this->business])
            ->set('logo', $file)
            ->assertNotDispatched('logo-updated');

        $this->business->refresh();
        expect($this->business->hasMedia('logo'))->toBeFalse();
    });

    it('prevents unauthenticated user from uploading logo', function (): void {
        $file = UploadedFile::fake()->image('logo.jpg', 200, 200);

        Livewire::test(LogoUploader::class, ['business' => $this->business])
            ->set('logo', $file)
            ->assertNotDispatched('logo-updated');

        $this->business->refresh();
        expect($this->business->hasMedia('logo'))->toBeFalse();
    });
});

describe('logo upload validation', function (): void {
    it('accepts valid jpeg image', function (): void {
        $file = UploadedFile::fake()->image('logo.jpg', 200, 200);

        Livewire::actingAs($this->user)
            ->test(LogoUploader::class, ['business' => $this->business])
            ->set('logo', $file)
            ->assertHasNoErrors('logo');
    });

    it('accepts valid png image', function (): void {
        $file = UploadedFile::fake()->image('logo.png', 200, 200);

        Livewire::actingAs($this->user)
            ->test(LogoUploader::class, ['business' => $this->business])
            ->set('logo', $file)
            ->assertHasNoErrors('logo');
    });

    it('accepts valid webp image', function (): void {
        $file = UploadedFile::fake()->create('logo.webp', 100, 'image/webp');

        Livewire::actingAs($this->user)
            ->test(LogoUploader::class, ['business' => $this->business])
            ->set('logo', $file)
            ->assertHasNoErrors('logo');
    });

    it('rejects non-image files', function (): void {
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        Livewire::actingAs($this->user)
            ->test(LogoUploader::class, ['business' => $this->business])
            ->set('logo', $file)
            ->assertHasErrors('logo');
    });

    it('rejects files larger than 2MB', function (): void {
        $file = UploadedFile::fake()->image('logo.jpg')->size(3000);

        Livewire::actingAs($this->user)
            ->test(LogoUploader::class, ['business' => $this->business])
            ->set('logo', $file)
            ->assertHasErrors('logo');
    });
});

describe('logo replacement', function (): void {
    it('replaces existing logo when new one is uploaded', function (): void {
        $originalFile = UploadedFile::fake()->image('original.jpg', 200, 200);
        $this->business->addMedia($originalFile)->toMediaCollection('logo');
        $originalMediaId = $this->business->getFirstMedia('logo')->id;

        $newFile = UploadedFile::fake()->image('new.jpg', 300, 300);

        Livewire::actingAs($this->user)
            ->test(LogoUploader::class, ['business' => $this->business])
            ->set('logo', $newFile)
            ->assertDispatched('logo-updated');

        $this->business->refresh();
        expect($this->business->hasMedia('logo'))->toBeTrue();
        expect($this->business->getFirstMedia('logo')->id)->not->toBe($originalMediaId);
    });
});

describe('component visibility', function (): void {
    it('correctly identifies owner', function (): void {
        $component = Livewire::actingAs($this->user)
            ->test(LogoUploader::class, ['business' => $this->business]);

        expect($component->instance()->isOwner())->toBeTrue();
    });

    it('correctly identifies non-owner', function (): void {
        $otherUser = User::factory()->create();

        $component = Livewire::actingAs($otherUser)
            ->test(LogoUploader::class, ['business' => $this->business]);

        expect($component->instance()->isOwner())->toBeFalse();
    });

    it('correctly identifies unauthenticated user', function (): void {
        $component = Livewire::test(LogoUploader::class, ['business' => $this->business]);

        expect($component->instance()->isOwner())->toBeFalse();
    });
});
