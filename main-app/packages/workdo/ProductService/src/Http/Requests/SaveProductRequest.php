<?php

namespace Workdo\ProductService\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Workdo\ProductService\Models\Product;

class SaveProductRequest extends FormRequest
{
    /** Permission checks live in the controller (create-/edit-products). */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenant = creatorId();
        $ownedBy = fn (string $table) => Rule::exists($table, 'id')->where('created_by', $tenant);

        return [
            'name' => 'required|string|max:255',
            'sku' => [
                'required', 'string', 'max:100',
                Rule::unique('products', 'sku')->where('created_by', $tenant)->ignore($this->route('product')?->id),
            ],
            'type' => ['required', Rule::in(Product::TYPES)],
            'sale_price' => 'required|numeric|min:0|max:9999999999',
            'purchase_price' => 'required|numeric|min:0|max:9999999999',
            'description' => 'nullable|string|max:5000',
            'is_active' => 'boolean',
            // relations must belong to the same company, otherwise ids of other tenants could be linked
            'category_id' => ['nullable', $ownedBy('product_categories')],
            'unit_id' => ['nullable', $ownedBy('product_units')],
            'tax_ids' => 'nullable|array',
            'tax_ids.*' => ['integer', 'distinct', $ownedBy('product_taxes')],
        ];
    }
}
