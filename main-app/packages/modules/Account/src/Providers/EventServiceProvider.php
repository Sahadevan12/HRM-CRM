<?php

namespace Modules\Account\Providers;

use App\Events\ApprovePurchaseReturn;
use App\Events\ApproveSalesReturn;
use App\Events\CompanyDeleting;
use App\Events\LoanDisbursed;
use App\Events\LoanDisbursementCancelled;
use App\Events\PaySalary;
use App\Events\PosPaymentReceived;
use App\Events\PostPurchaseInvoice;
use App\Events\PostSalesInvoice;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\Account\Listeners\BookLoanDisbursement;
use Modules\Account\Listeners\DeleteCompanyBooks;
use Modules\Account\Listeners\PostDocumentToLedger;
use Modules\Account\Listeners\RecordPosPayment;
use Modules\Account\Listeners\RecordSalaryPayment;

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
        PaySalary::class => [RecordSalaryPayment::class],
        LoanDisbursed::class => [BookLoanDisbursement::class],
        LoanDisbursementCancelled::class => [BookLoanDisbursement::class],
        CompanyDeleting::class => [DeleteCompanyBooks::class],
    ];
}
