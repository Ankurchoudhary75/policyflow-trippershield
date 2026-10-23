<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_request_id' => ['required', 'string', 'max:128'],
            'agent_id' => ['required', 'string', 'max:64'],
            'customer_name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['required', 'date_format:Y-m-d', 'before:trip_start'],
            'destination' => ['required', 'string', Rule::in(['DOMESTIC', 'INTERNATIONAL'])],
            'trip_start' => ['required', 'date_format:Y-m-d'],
            'trip_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:trip_start'],
            'plan_code' => ['required', 'string', Rule::in(['BASIC', 'PLUS', 'PREMIUM'])],
            'sum_insured' => ['required', Rule::in([100000, 300000, 500000, '100000', '300000', '500000'])],
            'mock_scenario' => ['nullable', 'string', Rule::in([
                'SUCCESS',
                'TEMPORARY_FAILURE',
                'TIMEOUT_AFTER_ISSUE',
                'DELAYED_CALLBACK',
                'DUPLICATE_CALLBACK',
            ])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'client_request_id' => is_string($this->client_request_id) ? trim($this->client_request_id) : $this->client_request_id,
            'agent_id' => is_string($this->agent_id) ? trim($this->agent_id) : $this->agent_id,
            'plan_code' => is_string($this->plan_code) ? strtoupper(trim($this->plan_code)) : $this->plan_code,
            'destination' => is_string($this->destination) ? strtoupper(trim($this->destination)) : $this->destination,
        ]);
    }
}
