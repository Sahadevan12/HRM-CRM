<?php

namespace Modules\ProductService\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Modules\ProductService\Models\ProductTax;

/** Fired after a product tax is created. Other modules can listen to it. */
class CreateProductTax
{
    use Dispatchable;

    public function __construct(
        public Request $request,
        public ProductTax $productTax,
    ) {
    }
}
