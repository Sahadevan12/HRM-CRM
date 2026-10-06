<?php

namespace Workdo\ProductService\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveProductTaxRequest extends FormRequest
{
    /** Permission checks live in the controller (create-/edit-product-taxes). */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'rate' => 'required|numeric|min:0|max:100',
        ];
    }
}
