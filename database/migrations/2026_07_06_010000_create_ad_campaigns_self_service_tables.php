<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('ad_rate_cards', function (Blueprint $table): void {
            $table->id();
            $table->string('slot')->index();
            $table->string('label');
            $table->unsignedSmallInteger('duration_days');
            $table->unsignedInteger('price');
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['slot', 'duration_days']);
        });

        Schema::create('ad_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ad_rate_card_id')->nullable()->constrained('ad_rate_cards')->nullOnDelete();
            $table->foreignId('ad_placement_id')->nullable()->constrained('ad_placements')->nullOnDelete();
            $table->string('slot')->index();
            $table->string('title');
            $table->string('cta_text')->default('Learn More');
            $table->string('cta_url', 2048);
            $table->unsignedSmallInteger('duration_days');
            $table->unsignedInteger('price');
            $table->string('currency', 50)->default('Espees');
            $table->date('desired_start_date')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('status')->default('pending_payment')->index();
            $table->string('payment_reference')->nullable()->index();
            $table->timestamp('payment_confirmed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();

            $table->index(['slot', 'status']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('ad_payment_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ad_campaign_id')->constrained('ad_campaigns')->cascadeOnDelete();
            $table->string('provider')->default('espees');
            $table->string('local_reference')->unique();
            $table->string('provider_reference')->nullable()->index();
            $table->unsignedInteger('amount');
            $table->string('currency', 50);
            $table->string('gateway_status')->default('initiated')->index();
            $table->string('review_status')->default('none')->index();
            $table->json('raw_payload')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['ad_campaign_id', 'provider']);
        });

        $this->seedRateCards();
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_payment_transactions');
        Schema::dropIfExists('ad_campaigns');
        Schema::dropIfExists('ad_rate_cards');
    }

    private function seedRateCards(): void
    {
        $prices = [
            'home_hero' => [7 => 450, 14 => 800, 30 => 1500],
            'home_mid' => [7 => 250, 14 => 450, 30 => 850],
            'directory_sidebar' => [7 => 200, 14 => 360, 30 => 700],
            'directory_inline' => [7 => 220, 14 => 400, 30 => 760],
            'company_sidebar' => [7 => 180, 14 => 320, 30 => 620],
            'company_below_about' => [7 => 200, 14 => 360, 30 => 700],
            'dashboard_top' => [7 => 260, 14 => 480, 30 => 900],
            'global_footer' => [7 => 180, 14 => 320, 30 => 600],
        ];

        $now = now();
        $rows = [];

        foreach (config('ads.slots', []) as $slot => $config) {
            foreach ([7, 14, 30] as $index => $days) {
                $rows[] = [
                    'slot' => $slot,
                    'label' => $days . ' days',
                    'duration_days' => $days,
                    'price' => $prices[$slot][$days] ?? ($days * 25),
                    'is_active' => true,
                    'sort_order' => $index + 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('ad_rate_cards')->insert($rows);
    }
};
