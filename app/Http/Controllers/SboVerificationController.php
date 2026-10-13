<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Sbo;
use Illuminate\View\View;

final class SboVerificationController extends Controller
{
    public function __invoke(string $mocRegistrationNumber): View
    {
        $sbo = Sbo::with('seal')
            ->where('moc_registration_number', $mocRegistrationNumber)
            ->first();
        $isValid = true === $sbo?->isFullyVerified();

        return view('sbo.verify', [
            'sbo' => $isValid ? $sbo : null,
            'searchedNumber' => $mocRegistrationNumber,
            'isValid' => $isValid,
        ]);
    }
}
