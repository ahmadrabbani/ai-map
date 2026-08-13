<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdEpermitDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'in:approve,reject,observation,under_review'],
            'remarks' => ['nullable', 'string', 'max:4000'],
            'push_to_dfps' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $action = (string) $this->input('action');
            $remarks = trim((string) $this->input('remarks', ''));
            if (in_array($action, ['reject', 'observation'], true) && $remarks === '') {
                $validator->errors()->add('remarks', ucfirst($action) . ' remarks are required.');
            }
        });
    }
}
