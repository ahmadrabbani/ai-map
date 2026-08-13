<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApplicationSiteReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
            'site_condition' => ['required', 'in:vacant,constructed,partially_constructed,unclear'],
            'front_road_detected' => ['required', 'boolean'],
            'side_road_detected' => ['required', 'boolean'],
            'corner_plot' => ['required', 'boolean'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'site_review_json' => ['required', 'array'],
        ];
    }
}
