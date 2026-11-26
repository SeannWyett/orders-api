<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('customer_email') && is_string($this->input('customer_email'))) {
            $this->merge([
                'customer_email' => strtolower(trim($this->input('customer_email')))]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $guest = fn () => ! $this->user('sanctum');

        return [
            'customer_name' => [Rule::requiredIf($guest), 'string', 'max:255'],
            'customer_email' => [Rule::requiredIf($guest), 'email', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],
        ];
    }
}
