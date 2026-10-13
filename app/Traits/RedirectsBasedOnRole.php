<?php

declare(strict_types=1);

namespace App\Traits;

use App\Support\AdminPages;
use Illuminate\Http\RedirectResponse;

trait RedirectsBasedOnRole
{
    protected function redirectToDashboard(): RedirectResponse
    {
        $user = auth()->user();

        if ($user->role->isAdmin()) {
            return redirect()->route(AdminPages::firstAccessibleRouteName($user) ?? 'home');
        }

        if ($user->role->isSboManager()) {
            return redirect()->route('sbo.dashboard');
        }

        return redirect()->route('dashboard');
    }
}
