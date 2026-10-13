<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Business;
use App\Models\Category;
use App\Models\Country;
use App\Models\State;
use App\Support\SboWorkspaceAccess;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Propaganistas\LaravelPhone\Rules\Phone;

/**
 * @property-read bool $isEditMode
 */
final class CreateBusinessModal extends Component
{
    public $categories = [];

    public $countries = [];

    public $states = [];

    #[Locked]
    public $businessId = null;

    public $name = '';

    public $category_id = '';

    public $website = '';

    public $phone = '';

    public $email = '';

    public $address = '';

    public $city = '';

    public $description = '';

    public $country_id = '';

    public $state_id = '';

    public $is_24_7 = false;

    public $hours = [];

    public $products = [];

    public $currentProduct = '';

    public $staff_size = '';

    public $years_active = '';

    public function mount(): void
    {
        $this->categories = Category::orderBy('name')->get();
        $this->countries = Country::all();
        $this->initializeHours();
    }

    public function updatedCountryId(): void
    {
        $this->state_id = '';

        $this->states = $this->isNigeriaSelected() ? State::where('country_id', $this->country_id)->get() : [];
    }

    public function updatedIs247(): void
    {
        if ($this->is_24_7) {
            foreach ($this->hours as &$hour) {
                $hour['is_open'] = true;
                $hour['open_time'] = '00:00';
                $hour['close_time'] = '23:59';
            }
        }
    }

    public function isNigeriaSelected(): bool
    {
        return 159 === (int) $this->country_id;
    }

    public function getIsEditModeProperty(): bool
    {
        return null !== $this->businessId;
    }

    #[On('edit-business')]
    public function editBusiness(int $businessId): void
    {
        $this->ensureWorkspaceAccess();

        $business = Business::with(['hours', 'products'])->findOrFail($businessId);

        $this->authorize('update', $business);

        $this->businessId = $business->id;
        $this->name = $business->name;
        $this->category_id = $business->category_id;
        $this->website = $business->website ?? '';
        $this->phone = $business->getRawOriginal('phone') ?? '';
        $this->email = $business->email;
        $this->address = $business->address;
        $this->city = $business->city ?? '';
        $this->description = $business->description;
        $this->country_id = $business->country_id ?? '';
        $this->state_id = $business->state_id ?? '';
        $this->is_24_7 = $business->is_24_7;
        $this->staff_size = $business->staff_size ?? '';
        $this->years_active = $business->years_active ?? '';

        if ($this->isNigeriaSelected()) {
            $this->states = State::where('country_id', $this->country_id)->get();
        }

        $this->initializeHours();

        foreach ($business->hours as $hour) {
            $index = $hour->day_of_week - 1;

            if (isset($this->hours[$index])) {
                $this->hours[$index]['is_open'] = ! $hour->is_closed;
                $this->hours[$index]['open_time'] = $hour->open_time ? mb_substr((string) $hour->open_time, 0, 5) : '09:00';
                $this->hours[$index]['close_time'] = $hour->close_time ? mb_substr((string) $hour->close_time, 0, 5) : '17:00';
            }
        }

        $this->products = $business->products->pluck('name')->toArray();

        $this->dispatch('open-modal');
    }

    #[On('open-create-business')]
    public function openCreate(): void
    {
        $this->ensureWorkspaceAccess();

        $this->resetForm(); // 🔑 clears edit state
        $this->dispatch('open-create-business-modal');
    }

    public function resetForm(): void
    {
        $this->businessId = null;
        $this->states = [];
        $this->is_24_7 = false;
        $this->reset(['name', 'category_id', 'website', 'phone', 'email', 'address', 'city', 'description', 'country_id', 'state_id', 'products', 'currentProduct', 'staff_size', 'years_active']);
        $this->initializeHours();
    }

    public function setEveryDay(): void
    {
        foreach ($this->hours as &$hour) {
            $hour['is_open'] = true;
        }
    }

    public function setWeekdays(): void
    {
        foreach ($this->hours as &$hour) {
            // Monday to Friday (1-5)
            $hour['is_open'] = $hour['day_of_week'] >= 1 && $hour['day_of_week'] <= 5;
        }
    }

    public function clearHours(): void
    {
        foreach ($this->hours as &$hour) {
            $hour['is_open'] = false;
        }
    }

    public function addProduct(): void
    {
        if (in_array(mb_trim($this->currentProduct), ['', '0'], true)) {
            return;
        }

        $this->products[] = mb_trim($this->currentProduct);
        $this->currentProduct = '';
    }

    public function removeProduct(int $index): void
    {
        unset($this->products[$index]);
        $this->products = array_values($this->products);
    }

    public function save(): void
    {
        $this->ensureWorkspaceAccess();

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['required', 'exists:categories,id'],
            'phone' => ['required', 'string', new Phone()],
            'email' => ['required', 'email', 'max:255'],
            'address' => ['required', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:1000'],
            'website' => ['nullable', 'url', 'max:255'],
            'country_id' => ['nullable', 'exists:countries,id'],
            'state_id' => ['nullable', 'exists:states,id'],
            'staff_size' => ['nullable', 'string', 'in:1-10,11-50,51-200,201-500,500+'],
            'years_active' => ['nullable', 'integer', 'min:0', 'max:200'],
            'hours.*.is_open' => ['boolean'],
            'hours.*.open_time' => ['required_if:hours.*.is_open,true', 'date_format:H:i'],
            'hours.*.close_time' => ['required_if:hours.*.is_open,true', 'date_format:H:i'],
            'products.*' => ['string', 'max:100'],
        ]);

        $isNewBusiness = ! $this->isEditMode;

        if ($this->isEditMode) {
            $this->updateBusiness();
        } else {
            $this->authorize('create', Business::class);
            $this->createBusiness();
        }

        $this->dispatch('business-saved', isNewBusiness: $isNewBusiness);
        $this->dispatch('close-modal');
        $this->resetForm();
    }

    #[On('delete-business')]
    public function deleteBusiness(int $businessId): void
    {
        $this->ensureWorkspaceAccess();

        $business = Business::findOrFail($businessId);

        $this->authorize('delete', $business);

        $business->delete();

        session()->flash('success', 'Business listing deleted successfully!');

        $this->dispatch('business-deleted');
    }

    public function render()
    {
        return view('livewire.create-business-modal')->layout(null);
    }

    protected function initializeHours(): void
    {
        $this->hours = [
            ['day' => 'Monday', 'day_of_week' => 1, 'is_open' => false, 'open_time' => '09:00', 'close_time' => '17:00'],
            ['day' => 'Tuesday', 'day_of_week' => 2, 'is_open' => false, 'open_time' => '09:00', 'close_time' => '17:00'],
            ['day' => 'Wednesday', 'day_of_week' => 3, 'is_open' => false, 'open_time' => '09:00', 'close_time' => '17:00'],
            ['day' => 'Thursday', 'day_of_week' => 4, 'is_open' => false, 'open_time' => '09:00', 'close_time' => '17:00'],
            ['day' => 'Friday', 'day_of_week' => 5, 'is_open' => false, 'open_time' => '09:00', 'close_time' => '17:00'],
            ['day' => 'Saturday', 'day_of_week' => 6, 'is_open' => false, 'open_time' => '09:00', 'close_time' => '17:00'],
            ['day' => 'Sunday', 'day_of_week' => 7, 'is_open' => false, 'open_time' => '09:00', 'close_time' => '17:00'],
        ];
    }

    protected function createBusiness(): void
    {
        $slug = Str::slug($this->name);
        $originalSlug = $slug;
        $counter = 1;

        while (Business::where('slug', $slug)->exists()) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        $business = Business::create([
            'user_id' => auth()->id(),
            'category_id' => $this->category_id,
            'name' => $this->name,
            'slug' => $slug,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'city' => $this->city ?: null,
            'description' => $this->description,
            'website' => $this->website ?: null,
            'country_id' => $this->country_id ?: null,
            'state_id' => $this->state_id ?: null,
            'staff_size' => $this->staff_size ?: null,
            'years_active' => $this->years_active ?: null,
            'is_verified' => false,
            'is_24_7' => $this->is_24_7,
            'status' => 'active',
        ]);

        $this->saveHoursAndProducts($business);

        session()->flash('success', 'Business listing submitted for verification!');
    }

    protected function updateBusiness(): void
    {
        $business = Business::findOrFail($this->businessId);

        $this->authorize('update', $business);

        $slug = $business->slug;

        if ($business->name !== $this->name) {
            $slug = Str::slug($this->name);
            $originalSlug = $slug;
            $counter = 1;

            while (Business::where('slug', $slug)->where('id', '!=', $business->id)->exists()) {
                $slug = $originalSlug . '-' . $counter;
                $counter++;
            }
        }

        $business->update([
            'category_id' => $this->category_id,
            'name' => $this->name,
            'slug' => $slug,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'city' => $this->city ?: null,
            'description' => $this->description,
            'website' => $this->website ?: null,
            'country_id' => $this->country_id ?: null,
            'state_id' => $this->state_id ?: null,
            'staff_size' => $this->staff_size ?: null,
            'years_active' => $this->years_active ?: null,
            'is_24_7' => $this->is_24_7,
        ]);

        $business->hours()->delete();
        $business->products()->delete();
        $this->saveHoursAndProducts($business);

        session()->flash('success', 'Business listing updated successfully!');
    }

    protected function saveHoursAndProducts(Business $business): void
    {
        foreach ($this->hours as $hour) {
            if ($hour['is_open']) {
                $business->hours()->create([
                    'day_of_week' => $hour['day_of_week'],
                    'open_time' => $hour['open_time'],
                    'close_time' => $hour['close_time'],
                    'is_closed' => false,
                ]);
            }
        }

        foreach ($this->products as $productName) {
            $business->products()->create([
                'name' => $productName,
            ]);
        }
    }

    private function ensureWorkspaceAccess(): void
    {
        $user = auth()->user();

        if ($user && app(SboWorkspaceAccess::class)->requiresPayment($user)) {
            abort(403, 'Complete payment to access listings.');
        }
    }
}
