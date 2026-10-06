<?php

namespace Workdo\ProductService\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Workdo\ProductService\Models\ProductCategory;

/** Fired after a product category is updated. Other modules can listen to it. */
class UpdateProductCategory
{
    use Dispatchable;

    public function __construct(
        public Request $request,
        public ProductCategory $productCategory,
    ) {
    }
}
