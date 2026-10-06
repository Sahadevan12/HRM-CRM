<?php

namespace Workdo\ProductService\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Workdo\ProductService\Models\Product;

/** Fired after a product is deleted. Other modules can listen to it. */
class DestroyProduct
{
    use Dispatchable;

    public function __construct(
        public Request $request,
        public Product $product,
    ) {
    }
}
