<?php

namespace Workdo\Account\Providers;

use App\Events\ApprovePurchaseReturn;
use App\Events\ApproveSalesReturn;
use App\Events\CompanyDeleting;
use App\Events\PosPaymentReceived;
use App\Events\PostPurchaseInvoice;
use App\Events\PostSalesInvoice;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Workdo\Account\Listeners\DeleteCompanyBooks;
use Workdo\Account\Listeners\PostDocumentToLedger;
use Workdo\Account\Listeners\RecordPosPayment;

class EventServiceProvider extends ServiceProvider
{
    /**
     * Listen to events of other modules here (loose coupling – never import their controllers).
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        PostSalesInvoice::class => [PostDocumentToLedger::class],
        PostPurchaseInvoice::class => [PostDocumentToLedger::class],
        ApproveSalesReturn::class => [PostDocumentToLedger::class],
        ApprovePurchaseReturn::class => [PostDocumentToLedger::class],
        PosPaymentReceived::class => [RecordPosPayment::class],
        CompanyDeleting::class => [DeleteCompanyBooks::class],
    ];
}
