<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Category;
use App\Models\Sbo;
use App\Notifications\AdminNewSboNotification;
use App\Support\AdminRecipients;
use App\Support\IndustryGroupChat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class SboObserver
{
    /**
     * Handle the Sbo "created" event.
     */
    public function created(Sbo $sbo): void
    {
        Cache::forget('footer_sbo_applied_count');

        // Keep the category SBO counter in sync.
        $this->incrementCategory($sbo->category);

        // Alert admins only when a registration still needs application review.
        if ($sbo->isPendingApproval()) {
            AdminRecipients::notify(new AdminNewSboNotification($sbo));
        }

        // The owner's welcome/next-steps email is deliberately NOT sent here.
        // It waits until the owner verifies their email — see
        // App\Listeners\SendSboWelcomeOnceVerified — so that at registration the
        // only email a member receives is "verify your email".

        // A brand new registration is not verified yet, so this normally does
        // nothing — but a seeded or admin-created SBO can arrive verified.
        IndustryGroupChat::sync($sbo);
    }

    /**
     * Handle the Sbo "updated" event — move the count if category changed.
     */
    public function updated(Sbo $sbo): void
    {
        if ($sbo->wasChanged('category')) {
            $this->decrementCategory($sbo->getOriginal('category'));
            $this->incrementCategory($sbo->category);
        }

        /*
         * Category group membership follows verification, so it is settled the
         * moment an approval, a fee confirmation or a document decision lands —
         * nobody has to remember to add anyone. Recategorising a business moves
         * it between rooms; losing verification takes it out.
         */
        if ($sbo->wasChanged([
            'category', 'verification_status', 'documents_status', 'payment_status',
            'documents_compliant_at', 'payment_confirmed_at', 'is_active', 'status',
        ])) {
            IndustryGroupChat::sync($sbo);
        }
    }

    /**
     * Handle the Sbo "deleted" event.
     */
    public function deleted(Sbo $sbo): void
    {
        Cache::forget('footer_sbo_applied_count');

        $this->decrementCategory($sbo->category);
    }

    private function incrementCategory(?string $category): void
    {
        $this->resolveCategory($category)?->increment('business_count');
    }

    private function decrementCategory(?string $category): void
    {
        $model = $this->resolveCategory($category);

        if ($model && $model->business_count > 0) {
            $model->decrement('business_count');
        }
    }

    /**
     * Match an SBO's free-text category to a Category record by slug/name.
     */
    private function resolveCategory(?string $category): ?Category
    {
        if (empty($category)) {
            return null;
        }

        return Category::where('slug', Str::slug($category))
            ->orWhereRaw('LOWER(name) = ?', [mb_strtolower($category)])
            ->first();
    }
}
