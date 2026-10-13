<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Support\AdminPages;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->json('admin_page_permissions')->nullable()->after('permissions');
        });

        DB::table('users')
            ->where('role', UserRole::ADMIN->value)
            ->orderBy('id')
            ->get(['id', 'permissions'])
            ->each(function (object $user): void {
                $legacyPermissions = json_decode((string) ($user->permissions ?? '[]'), true);

                if ( ! is_array($legacyPermissions)) {
                    $legacyPermissions = [];
                }

                $pagePermissions = AdminPages::fromLegacyCapabilities($legacyPermissions, includeDefaults: true);
                $capabilityCache = AdminPages::capabilitiesForPages($pagePermissions);

                DB::table('users')
                    ->where('id', $user->id)
                    ->update([
                        'admin_page_permissions' => json_encode($pagePermissions),
                        'permissions' => json_encode($capabilityCache),
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('admin_page_permissions');
        });
    }
};
