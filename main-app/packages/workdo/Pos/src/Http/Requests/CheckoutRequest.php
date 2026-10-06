<?php

namespace Workdo\Pos\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Workdo\Pos\Models\PosSale;

class CheckoutRequest extends FormRequest
{
    /** Permission (create-pos) is checked in the controller. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenant = creatorId();

        return [
            'warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('created_by', $tenant)->where('is_active', true)],
            'customer_id' => ['nullable', Rule::exists('users', 'id')->where('created_by', $tenant)->where('type', 'client')],
            'payment_method' => ['required', Rule::in(PosSale::METHODS)],
            'amount_tendered' => 'nullable|numeric|min:0|max:9999999999',
            'items' => 'required|array|min:1|max:100',
            'items.*.product_id' => ['required', Rule::exists('products', 'id')->where('created_by', $tenant)->where('is_active', true)],
            'items.*.quantity' => 'required|numeric|gt:0|max:999999',
            'items.*.discount_amount' => 'nullable|numeric|min:0|max:9999999999',
        ];
    }
}
