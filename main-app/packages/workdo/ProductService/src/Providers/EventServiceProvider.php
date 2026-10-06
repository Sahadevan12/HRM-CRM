<?php

namespace Workdo\ProductService\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * Listen to events of other modules here (loose coupling – never import their controllers).
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        // \App\Events\SomeEvent::class => [\Workdo\ProductService\Listeners\SomeListener::class],
    ];
}
