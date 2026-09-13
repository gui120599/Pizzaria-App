<?php

namespace App\Providers;

use App\Models\Cliente;
use App\Models\ItensVenda;
use App\Models\Pedido;
use App\Models\Venda;
use App\Observers\ClienteObserver;
use App\Observers\ItensVendaObserver;
use App\Observers\PedidoObserver;
use App\Observers\VendaObserver;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }

    /**
     * Observadores de inserção ou alteração de objetos
     */
    protected $observers = [
        Pedido::class => [PedidoObserver::class],
        Venda::class => [VendaObserver::class],
        ItensVenda::class => [ItensVendaObserver::class],
        Cliente::class => [ClienteObserver::class],
    ];
}
