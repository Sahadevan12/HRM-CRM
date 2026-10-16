<?php

namespace Modules\Lead\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * Listen to events of other modules here (loose coupling – never import their controllers).
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        \App\Events\CollectDashboardWidgets::class => [\Modules\Lead\Listeners\AddCrmDashboardWidget::class],
        \App\Events\CollectMailTemplates::class => [\Modules\Lead\Listeners\RegisterCrmMailTemplates::class],
    ];
}
