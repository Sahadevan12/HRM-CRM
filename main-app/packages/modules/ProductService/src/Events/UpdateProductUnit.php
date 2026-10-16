<?php

namespace Modules\ProductService\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Modules\ProductService\Models\ProductUnit;

/** Fired after a product unit is updated. Other modules can listen to it. */
class UpdateProductUnit
{
    use Dispatchable;

    public function __construct(
        public Request $request,
        public ProductUnit $productUnit,
    ) {
    }
}
