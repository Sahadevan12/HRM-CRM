<?php

namespace Workdo\SalesPurchase\Providers;

use App\Events\CompanyDeleting;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Workdo\SalesPurchase\Listeners\DeleteCompanyDocuments;

class EventServiceProvider extends ServiceProvider
{
    /**
     * Listen to events of other modules here (loose coupling – never import their controllers).
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        CompanyDeleting::class => [DeleteCompanyDocuments::class],
    ];
}
