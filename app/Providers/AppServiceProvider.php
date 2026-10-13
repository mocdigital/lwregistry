<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Business;
use App\Models\Category;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderBid;
use App\Models\Report;
use App\Models\Review;
use App\Models\Sbo;
use App\Models\User;
use App\Observers\BusinessObserver;
use App\Observers\PurchaseOrderBidObserver;
use App\Observers\PurchaseOrderObserver;
use App\Observers\ReportObserver;
use App\Observers\ReviewObserver;
use App\Observers\SboObserver;
use App\Observers\UserObserver;
use App\Policies\BusinessPolicy;
use App\Support\Chatbot\KnowledgeBaseResponder;
use App\Support\Chatbot\Responder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Override;
use Throwable;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Business::class => BusinessPolicy::class,
    ];

    /**
     * Register any application services.
     */
    #[Override]
    public function register(): void
    {
        /*
         * What answers a visitor's question. Bound to the interface rather
         * than type-hinted concretely, so replacing the knowledge bank with
         * something that reads the same passages through a language model is a
         * change to this line and nothing else — the same shape the translator
         * uses.
         */
        $this->app->bind(Responder::class, KnowledgeBaseResponder::class);

        /*
         * The embedder for the assistant's semantic search. A singleton so its
         * cached health check is shared, and bound to the interface so a test
         * can swap in a fake without a running model.
         */
        $this->app->singleton(\App\Support\Chatbot\Embedder::class, \App\Support\Chatbot\HttpEmbedder::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Business::class, BusinessPolicy::class);

        // Register an authorization gate for each admin capability so views can
        // use @can('approve_sbos') and code can use $user->can('manage_ads').
        foreach (\App\Support\AdminCapabilities::all() as $capability) {
            Gate::define($capability, fn (User $user): bool => $user->hasPermission($capability));
        }

        // Business::observe(BusinessObserver::class);
        PurchaseOrder::observe(PurchaseOrderObserver::class);
        PurchaseOrderBid::observe(PurchaseOrderBidObserver::class);
        Report::observe(ReportObserver::class);
        Review::observe(ReviewObserver::class);
        Sbo::observe(SboObserver::class);
        User::observe(UserObserver::class);

        // The owner's welcome/next-steps email waits for email verification, so
        // it never arrives before "verify your email" — sent by the
        // App\Listeners\SendSboWelcomeOnceVerified listener, which Laravel
        // auto-discovers from its Verified type-hint (no manual wiring here, or
        // it would fire twice).

        // Share categories only when the database is reachable and the table exists.
        if ( ! $this->app->runningInConsole() && $this->hasTable('categories')) {
            View::share('allCategories', Category::orderBy('name')->get());
        }
    }

    private function hasTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }
}
