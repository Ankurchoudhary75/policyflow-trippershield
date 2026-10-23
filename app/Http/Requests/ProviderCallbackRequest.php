<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProviderCallbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'provider_event_id' => ['required', 'string', 'max:128'],
            'event_type' => ['required', 'string', Rule::in(['CONFIRMED', 'REJECTED'])],
            'policy_request_id' => ['nullable', 'string'],
            'idempotency_key' => ['nullable', 'string'],
            'provider_reference' => ['nullable', 'string'],
            'policy_number' => ['nullable', 'string', 'max:128'],
            'error_reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
