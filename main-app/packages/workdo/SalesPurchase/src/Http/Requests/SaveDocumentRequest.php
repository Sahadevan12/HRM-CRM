<?php

namespace Workdo\SalesPurchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Workdo\SalesPurchase\Support\DocumentType;

/** Validation for creating/updating any trade document; the type comes from the route default. */
class SaveDocumentRequest extends FormRequest
{
    /** Permission checks live in the controller. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $type = $this->route('type');
        $meta = DocumentType::get($type);
        $tenant = creatorId();
        $owned = fn (string $table) => Rule::exists($table, 'id')->where('created_by', $tenant);

        $rules = [
            'doc_date' => 'required|date',
            'notes' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1|max:200',
        ];

        if ($meta['is_return']) {
            // party + warehouse are taken from the invoice being returned
            return $rules + [
                'parent_id' => ['required', Rule::exists('documents', 'id')->where('created_by', $tenant)->where('type', $meta['parent_type'])->where('status', 'posted')],
                'reason' => 'nullable|string|max:1000',
                'items.*.source_item_id' => 'required|integer',
                'items.*.quantity' => 'required|numeric|gt:0|max:999999999',
            ];
        }

        return $rules + [
            'party_id' => ['required', Rule::exists('users', 'id')->where('created_by', $tenant)->where('type', $meta['party'])],
            'warehouse_id' => ['required', $owned('warehouses')],
            'due_date' => 'nullable|date|after_or_equal:doc_date',
            'items.*.product_id' => ['required', $owned('products')],
            'items.*.quantity' => 'required|numeric|gt:0|max:999999999',
            'items.*.unit_price' => 'required|numeric|min:0|max:9999999999',
            'items.*.discount_amount' => 'nullable|numeric|min:0|max:9999999999',
            'items.*.tax_ids' => 'nullable|array',
            'items.*.tax_ids.*' => ['integer', $owned('product_taxes')],
        ];
    }
}
