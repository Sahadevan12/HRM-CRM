<?php

namespace Workdo\ProductService\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Workdo\ProductService\Models\ProductCategory;

/** Fired after a product category is created. Other modules can listen to it. */
class CreateProductCategory
{
    use Dispatchable;

    public function __construct(
        public Request $request,
        public ProductCategory $productCategory,
    ) {
    }
}
