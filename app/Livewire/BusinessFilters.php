<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Business;
use App\Models\Category;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * @property-read LengthAwarePaginator $businesses
 * @property-read Collection<int, Category> $categories
 * @property-read Collection<int, string> $cities
 */
final class BusinessFilters extends Component
{
    use WithPagination;

    public string $category = 'all';

    public string $location = 'all';

    public string $status = 'all';

    public bool $open24 = false;

    public string $search = '';

    public string $sortBy = 'relevance';

    protected $queryString = [
        'category' => ['except' => 'all'],
        'location' => ['except' => 'all'],
        'status' => ['except' => 'all'],
        'open24' => ['except' => false],
        'search' => ['except' => ''],
        'sortBy' => ['except' => 'relevance'],
    ];

    public function updatedCategory(): void
    {
        $this->resetPage();
    }

    public function updatedLocation(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedOpen24(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSortBy(): void
    {
        $this->resetPage();
    }

    public function getBusinessesProperty(): LengthAwarePaginator
    {
        $query = Business::query()->with('category');

        // Category filter
        if ('all' !== $this->category) {
            $category = Category::where('slug', $this->category)->first();

            if ($category) {
                $query->where('category_id', $category->id);
            }
        }

        // Location filter
        if ('all' !== $this->location) {
            $query->where('city', $this->location);
        }

        // Status filter (verified/unverified)
        if ('verified' === $this->status) {
            $query->where('is_verified', true);
        } elseif ('unverified' === $this->status) {
            $query->where('is_verified', false);
        }

        // Open 24/7 filter
        if ($this->open24) {
            $query->where('is_24_7', true);
        }

        // Search filter
        if ('' !== $this->search) {
            $query->where(function ($q): void {
                $q->where('name', 'like', '%' . $this->search . '%')
                    ->orWhere('description', 'like', '%' . $this->search . '%')
                    ->orWhere('address', 'like', '%' . $this->search . '%');
            });
        }

        // Sorting
        match ($this->sortBy) {
            'rating' => $query->orderBy('avg_rating', 'desc'),
            'newest' => $query->latest(),
            default => $query->orderBy('is_verified', 'desc')->latest(),
        };

        return $query->paginate(10);
    }

    public function getCategoriesProperty(): Collection
    {
        return Category::orderBy('name')->get();
    }

    public function getCitiesProperty(): Collection
    {
        return Business::query()
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->distinct()
            ->orderBy('city')
            ->pluck('city');
    }

    public function render()
    {
        return view('livewire.business-filters', [
            'businesses' => $this->businesses,
            'categories' => $this->categories,
            'cities' => $this->cities,
        ]);
    }
}
