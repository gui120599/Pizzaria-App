<?php

namespace App\Filament\Pages;

use App\Services\RelatorioFiscalNotasService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use UnitEnum;

/**
 * Relatório para o contador: vendas com NFC-e autorizada e itens agrupados
 * (NCM, categoria, produto, CFOP/CSOSN, forma de pagamento, dia), com total.
 * Regra em App\Services\RelatorioFiscalNotasService.
 */
class RelatorioFiscalNotas extends Page
{
    protected static string $routePath = '/relatorio-fiscal-notas';

    protected static ?string $title = 'Relatório fiscal (NFC-e)';

    protected static ?string $navigationLabel = 'Relatório fiscal (NFC-e)';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static UnitEnum|string|null $navigationGroup = 'Financeiro';

    protected static ?int $navigationSort = 60;

    protected string $view = 'filament.pages.relatorio-fiscal-notas';

    public string $dataInicial = '';

    public string $dataFinal = '';

    /** @var array<int, string> */
    public array $agrupamentos = ['ncm', 'categoria'];

    public static function canAccess(): bool
    {
        return Auth::user()?->can('view:relatorio_financeiro') ?? false;
    }

    public function mount(): void
    {
        $this->dataInicial = now()->startOfMonth()->toDateString();
        $this->dataFinal = now()->toDateString();
    }

    #[Computed]
    public function relatorio(): RelatorioFiscalNotasService
    {
        return new RelatorioFiscalNotasService(
            Carbon::parse($this->dataInicial ?: now()->startOfMonth()),
            Carbon::parse($this->dataFinal ?: now()),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function parametrosExportacao(): array
    {
        return ['inicio' => $this->dataInicial, 'fim' => $this->dataFinal, 'agrupamentos' => $this->agrupamentos];
    }
}
