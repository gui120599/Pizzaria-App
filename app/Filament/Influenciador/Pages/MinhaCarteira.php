<?php

namespace App\Filament\Influenciador\Pages;

use App\Enums\TipoMovimentoCarteiraEnum;
use App\Models\CarteiraMovimento;
use App\Models\Influenciador;
use App\Services\CarteiraInfluenciadorService;
use App\Services\CodigoResgateService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * Saldo e extrato da carteira do influenciador. Resgate em Pix é feito pela
 * equipe; o consumo em pizza no caixa usa o código gerado aqui.
 */
class MinhaCarteira extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWallet;

    protected static ?string $navigationLabel = 'Minha Carteira';

    protected static ?string $title = 'Minha Carteira';

    protected static ?string $slug = 'carteira';

    protected string $view = 'filament.influenciador.pages.minha-carteira';

    /** Código em claro: só existe na tela logo depois de gerado. */
    public ?string $codigoGerado = null;

    public ?string $codigoExpiraEm = null;

    private function influenciador(): Influenciador
    {
        return Auth::guard('influenciador')->user();
    }

    public function saldo(): float
    {
        return app(CarteiraInfluenciadorService::class)->saldo($this->influenciador());
    }

    public function gerarCodigoAction(): Action
    {
        return Action::make('gerarCodigo')
            ->label('Gerar código de resgate')
            ->icon(Heroicon::OutlinedKey)
            ->requiresConfirmation()
            ->modalDescription('O código vale 15 minutos e só pode ser usado uma vez. Gerar um novo invalida o anterior. Passe o código só para quem vai atender você no caixa.')
            ->action(function (): void {
                $gerado = app(CodigoResgateService::class)->gerar($this->influenciador());

                $this->codigoGerado = $gerado['codigo'];
                $this->codigoExpiraEm = $gerado['expira_em']->format('H:i');
            });
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => CarteiraMovimento::query()->where('cm_influenciador_id', $this->influenciador()->id))
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('Nenhum movimento ainda')
            ->emptyStateDescription('As comissões dos pedidos pagos com o seu cupom entram aqui.')
            ->columns([
                TextColumn::make('created_at')->label('Data')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('cm_tipo')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->label())
                    ->color(fn ($state) => in_array($state, [TipoMovimentoCarteiraEnum::CREDITO_COMISSAO, TipoMovimentoCarteiraEnum::ESTORNO_CONSUMO], true) ? 'success' : 'gray'),
                TextColumn::make('cm_valor')
                    ->label('Valor')
                    ->money('BRL')
                    ->weight('bold')
                    ->color(fn ($state): string => (float) $state < 0 ? 'danger' : 'success'),
                TextColumn::make('cm_observacao')->label('Observação')->placeholder('—')->wrap(),
            ])
            ->paginated([10, 25, 50]);
    }
}
