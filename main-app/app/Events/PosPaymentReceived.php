<?php

namespace App\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A POS sale was paid at the counter. Accounting listens and records the customer payment (Dr Cash/Bank, Cr Receivable).
 * A listener that books the payment sets `handled = true`; when nobody does, the POS marks the invoice as paid itself.
 * `document` is a Workdo\SalesPurchase\Models\Document (the posted sales invoice).
 */
class PosPaymentReceived
{
    use Dispatchable;

    public bool $handled = false;

    public function __construct(public Model $document, public string $method, public float $amount, public ?int $cashierId)
    {
    }
}
