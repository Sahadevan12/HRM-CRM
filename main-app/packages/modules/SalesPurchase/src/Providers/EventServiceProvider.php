<?php

namespace Modules\SalesPurchase\Providers;

use App\Events\CompanyDeleting;
use App\Events\DealWon;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\SalesPurchase\Listeners\DeleteCompanyDocuments;
use Modules\SalesPurchase\Listeners\DraftProposalForWonDeal;

class EventServiceProvider extends ServiceProvider
{
    /**
     * Listen to events of other modules here (loose coupling – never import their controllers).
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        CompanyDeleting::class => [DeleteCompanyDocuments::class],
        DealWon::class => [DraftProposalForWonDeal::class],
    ];
}
