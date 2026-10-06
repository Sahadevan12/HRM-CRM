<?php

namespace Workdo\ProductService\Exceptions;

use RuntimeException;

class InsufficientStockException extends RuntimeException
{
    public function __construct(public readonly string $productName, public readonly float $available, public readonly float $requested)
    {
        parent::__construct(__('Not enough stock for :product (available :available, requested :requested).', [
            'product' => $productName,
            'available' => $available,
            'requested' => $requested,
        ]));
    }
}
