<?php

namespace App\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Base of the trade events. They live in core so that any module (Account, POS, ...) can listen
 * without importing the SalesPurchase module. document is a Workdo\SalesPurchase\Models\Document.
 */
abstract class TradeDocumentEvent
{
    use Dispatchable;

    public function __construct(public Model $document)
    {
    }
}
