<?php

namespace App\Filament\Pages;

use App\Models\Cliente;
use App\Services\DebitosClienteService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use UnitEnum;

/**
 * Quanto cada cliente deve (fiado/saldo em aberto) e de quais pedidos vem a
 * dívida. Regra em App\Services\DebitosClienteService; mesma lista da aba
 * Pendentes do PDV, aqui incluindo os títulos lançados à mão no Financeiro.
 */
class RelatorioDebitosClientes extends Page
{
    protected static string $routePath = '/relatorio-debitos-clientes';

    protected static ?string $title = 'Débitos de Clientes';

    protected static ?string $navigationLabel = 'Débitos de Clientes';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static UnitEnum|string|null $navigationGroup = 'Comercial';

    protected static ?int $navigationSort = 31;

    protected string $view = 'filament.pages.relatorio-debitos-clientes';

    public string $busca = '';

    public bool $somenteVencidos = false;

    /** Separa os títulos de cada cliente pelo mês da compra, com subtotal. */
    public bool $agruparPorMes = false;

    public static function canAccess(): bool
    {
        return Auth::user()?->can('viewAny', Cliente::class) ?? false;
    }

    #[Computed]
    public function relatorio(): DebitosClienteService
    {
        return new DebitosClienteService($this->filtros());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('imprimir')
                ->label('Imprimir')
                ->icon(Heroicon::OutlinedPrinter)
                ->color('primary')
                ->url(fn (): string => route('relatorios.debitos_clientes.imprimir', ['filters' => $this->filtros()]))
                ->openUrlInNewTab(),
        ];
    }

    /**
     * @return array{busca: string, somente_vencidos: bool, agrupar_mes: bool}
     */
    private function filtros(): array
    {
        return ['busca' => $this->busca, 'somente_vencidos' => $this->somenteVencidos, 'agrupar_mes' => $this->agruparPorMes];
    }
}
