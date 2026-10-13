<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Override;

final class StoreReviewRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return null !== $this->user();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    #[Override]
    public function messages(): array
    {
        return [
            'rating.required' => 'Please select a rating.',
            'rating.integer' => 'The rating must be a whole number.',
            'rating.min' => 'The rating must be at least 1 star.',
            'rating.max' => 'The rating cannot exceed 5 stars.',
            'comment.max' => 'Your review cannot exceed 5000 characters.',
        ];
    }
}
