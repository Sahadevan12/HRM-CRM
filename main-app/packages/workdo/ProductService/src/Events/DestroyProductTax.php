<?php

namespace Workdo\ProductService\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Workdo\ProductService\Models\ProductTax;

/** Fired after a product tax is deleted. Other modules can listen to it. */
class DestroyProductTax
{
    use Dispatchable;

    public function __construct(
        public Request $request,
        public ProductTax $productTax,
    ) {
    }
}
