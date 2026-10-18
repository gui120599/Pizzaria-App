<?php

namespace App\Filament\Pages;

use App\Services\RelatorioCortesiasService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use UnitEnum;

/**
 * Auditoria de cortesias: o que saiu do estoque sem cobrança, por quê e quem
 * autorizou. Regra em App\Services\RelatorioCortesiasService.
 */
class RelatorioCortesias extends Page
{
    protected static string $routePath = '/relatorio-cortesias';

    protected static ?string $title = 'Cortesias';

    protected static ?string $navigationLabel = 'Cortesias';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static UnitEnum|string|null $navigationGroup = 'Comercial';

    protected static ?int $navigationSort = 32;

    protected string $view = 'filament.pages.relatorio-cortesias';

    public string $dataInicial = '';

    public string $dataFinal = '';

    public string $busca = '';

    public static function canAccess(): bool
    {
        return Auth::user()?->can('conceder_cortesia:pedido') ?? false;
    }

    public function mount(): void
    {
        $this->dataInicial = now()->startOfMonth()->toDateString();
        $this->dataFinal = now()->toDateString();
    }

    #[Computed]
    public function relatorio(): RelatorioCortesiasService
    {
        return new RelatorioCortesiasService(
            Carbon::parse($this->dataInicial ?: now()->startOfMonth()),
            Carbon::parse($this->dataFinal ?: now()),
            $this->busca,
        );
    }
}
