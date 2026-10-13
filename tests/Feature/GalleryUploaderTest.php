<?php

declare(strict_types=1);

use App\Livewire\GalleryUploader;
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

describe('gallery upload authorization', function (): void {
    it('allows owner to upload gallery image', function (): void {
        $file = UploadedFile::fake()->image('gallery1.jpg', 400, 300);

        Livewire::actingAs($this->user)
            ->test(GalleryUploader::class, ['business' => $this->business])
            ->set('image', $file)
            ->assertHasNoErrors()
            ->assertDispatched('gallery-updated');

        $this->business->refresh();
        expect($this->business->hasMedia('gallery'))->toBeTrue();
        expect($this->business->getMedia('gallery')->count())->toBe(1);
    });

    it('prevents non-owner from uploading gallery image', function (): void {
        $otherUser = User::factory()->create();
        $file = UploadedFile::fake()->image('gallery1.jpg', 400, 300);

        Livewire::actingAs($otherUser)
            ->test(GalleryUploader::class, ['business' => $this->business])
            ->set('image', $file)
            ->assertNotDispatched('gallery-updated');

        $this->business->refresh();
        expect($this->business->hasMedia('gallery'))->toBeFalse();
    });

    it('prevents unauthenticated user from uploading gallery image', function (): void {
        $file = UploadedFile::fake()->image('gallery1.jpg', 400, 300);

        Livewire::test(GalleryUploader::class, ['business' => $this->business])
            ->set('image', $file)
            ->assertNotDispatched('gallery-updated');

        $this->business->refresh();
        expect($this->business->hasMedia('gallery'))->toBeFalse();
    });
});

describe('gallery upload limits', function (): void {
    it('allows uploading up to 5 images', function (): void {
        for ($i = 1; $i <= 5; $i++) {
            $file = UploadedFile::fake()->image("gallery{$i}.jpg", 400, 300);

            Livewire::actingAs($this->user)
                ->test(GalleryUploader::class, ['business' => $this->business])
                ->set('image', $file)
                ->assertHasNoErrors()
                ->assertDispatched('gallery-updated');

            $this->business->refresh();
        }

        expect($this->business->getMedia('gallery')->count())->toBe(5);
    });

    it('rejects 6th image upload', function (): void {
        for ($i = 1; $i <= 5; $i++) {
            $file = UploadedFile::fake()->image("gallery{$i}.jpg", 400, 300);
            $this->business->addMedia($file)->toMediaCollection('gallery');
        }
        $this->business->refresh();

        $sixthFile = UploadedFile::fake()->image('gallery6.jpg', 400, 300);

        Livewire::actingAs($this->user)
            ->test(GalleryUploader::class, ['business' => $this->business])
            ->set('image', $sixthFile)
            ->assertHasErrors('image')
            ->assertNotDispatched('gallery-updated');

        $this->business->refresh();
        expect($this->business->getMedia('gallery')->count())->toBe(5);
    });
});

describe('gallery image replacement', function (): void {
    it('replaces specific image when replaceIndex is set', function (): void {
        $originalFile1 = UploadedFile::fake()->image('original1.jpg', 400, 300);
        $originalFile2 = UploadedFile::fake()->image('original2.jpg', 400, 300);
        $this->business->addMedia($originalFile1)->toMediaCollection('gallery');
        $this->business->addMedia($originalFile2)->toMediaCollection('gallery');
        $this->business->refresh();

        $originalMediaId = $this->business->getMedia('gallery')->first()->id;
        $secondMediaId = $this->business->getMedia('gallery')->get(1)->id;

        $newFile = UploadedFile::fake()->image('new.jpg', 500, 400);

        Livewire::actingAs($this->user)
            ->test(GalleryUploader::class, ['business' => $this->business])
            ->call('setReplaceIndex', 0)
            ->set('image', $newFile)
            ->assertDispatched('gallery-updated');

        $this->business->refresh();
        expect($this->business->getMedia('gallery')->count())->toBe(2);
        expect($this->business->getMedia('gallery')->where('id', $originalMediaId)->count())->toBe(0);
        expect($this->business->getMedia('gallery')->where('id', $secondMediaId)->count())->toBe(1);
    });

    it('deletes old file when replacing image', function (): void {
        $originalFile = UploadedFile::fake()->image('original.jpg', 400, 300);
        $this->business->addMedia($originalFile)->toMediaCollection('gallery');
        $this->business->refresh();

        $originalMedia = $this->business->getMedia('gallery')->first();
        $originalPath = $originalMedia->getPath();

        $newFile = UploadedFile::fake()->image('new.jpg', 500, 400);

        Livewire::actingAs($this->user)
            ->test(GalleryUploader::class, ['business' => $this->business])
            ->call('setReplaceIndex', 0)
            ->set('image', $newFile)
            ->assertDispatched('gallery-updated');

        expect(file_exists($originalPath))->toBeFalse();
    });

    it('resets replaceIndex after upload', function (): void {
        $originalFile = UploadedFile::fake()->image('original.jpg', 400, 300);
        $this->business->addMedia($originalFile)->toMediaCollection('gallery');
        $this->business->refresh();

        $newFile = UploadedFile::fake()->image('new.jpg', 500, 400);

        $component = Livewire::actingAs($this->user)
            ->test(GalleryUploader::class, ['business' => $this->business])
            ->call('setReplaceIndex', 0)
            ->set('image', $newFile);

        expect($component->get('replaceIndex'))->toBeNull();
    });
});

describe('gallery upload validation', function (): void {
    it('accepts valid jpeg image', function (): void {
        $file = UploadedFile::fake()->image('gallery.jpg', 400, 300);

        Livewire::actingAs($this->user)
            ->test(GalleryUploader::class, ['business' => $this->business])
            ->set('image', $file)
            ->assertHasNoErrors('image');
    });

    it('accepts valid png image', function (): void {
        $file = UploadedFile::fake()->image('gallery.png', 400, 300);

        Livewire::actingAs($this->user)
            ->test(GalleryUploader::class, ['business' => $this->business])
            ->set('image', $file)
            ->assertHasNoErrors('image');
    });

    it('accepts valid webp image', function (): void {
        $file = UploadedFile::fake()->create('gallery.webp', 100, 'image/webp');

        Livewire::actingAs($this->user)
            ->test(GalleryUploader::class, ['business' => $this->business])
            ->set('image', $file)
            ->assertHasNoErrors('image');
    });

    it('rejects non-image files', function (): void {
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        Livewire::actingAs($this->user)
            ->test(GalleryUploader::class, ['business' => $this->business])
            ->set('image', $file)
            ->assertHasErrors('image');
    });

    it('rejects files larger than 2MB', function (): void {
        $file = UploadedFile::fake()->image('gallery.jpg')->size(3000);

        Livewire::actingAs($this->user)
            ->test(GalleryUploader::class, ['business' => $this->business])
            ->set('image', $file)
            ->assertHasErrors('image');
    });
});

describe('component visibility', function (): void {
    it('correctly identifies owner', function (): void {
        $component = Livewire::actingAs($this->user)
            ->test(GalleryUploader::class, ['business' => $this->business]);

        expect($component->instance()->isOwner())->toBeTrue();
    });

    it('correctly identifies non-owner', function (): void {
        $otherUser = User::factory()->create();

        $component = Livewire::actingAs($otherUser)
            ->test(GalleryUploader::class, ['business' => $this->business]);

        expect($component->instance()->isOwner())->toBeFalse();
    });

    it('correctly identifies unauthenticated user', function (): void {
        $component = Livewire::test(GalleryUploader::class, ['business' => $this->business]);

        expect($component->instance()->isOwner())->toBeFalse();
    });

    it('prevents non-owner from setting replaceIndex', function (): void {
        $otherUser = User::factory()->create();

        $component = Livewire::actingAs($otherUser)
            ->test(GalleryUploader::class, ['business' => $this->business])
            ->call('setReplaceIndex', 0);

        expect($component->get('replaceIndex'))->toBeNull();
    });
});
