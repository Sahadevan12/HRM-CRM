<?php

namespace Workdo\ProductService\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Workdo\ProductService\Models\Product;

/** Fired after a product is updated. Other modules can listen to it. */
class UpdateProduct
{
    use Dispatchable;

    public function __construct(
        public Request $request,
        public Product $product,
    ) {
    }
}
