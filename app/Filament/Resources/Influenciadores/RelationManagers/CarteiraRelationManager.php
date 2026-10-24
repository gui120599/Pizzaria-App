<?php

namespace App\Filament\Resources\Influenciadores\RelationManagers;

use App\Enums\TipoMovimentoCarteiraEnum;
use App\Exceptions\CarteiraInfluenciadorException;
use App\Exceptions\FinanceiroException;
use App\Models\Influenciador;
use App\Services\CarteiraInfluenciadorService;
use App\Services\ResgatePixService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/** Extrato da carteira do influenciador (append-only) com resgate em Pix e ajuste manual. */
class CarteiraRelationManager extends RelationManager
{
    protected static string $relationship = 'movimentosCarteira';

    protected static ?string $title = 'Carteira';

    public function table(Table $table): Table
    {
        /** @var Influenciador $influenciador */
        $influenciador = $this->getOwnerRecord();

        return $table
            ->heading(fn (): string => 'Saldo: R$ '.number_format($influenciador->fresh()->saldoCarteira(), 2, ',', '.'))
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('Nenhum movimento na carteira')
            ->columns([
                TextColumn::make('created_at')->label('Data')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('cm_tipo')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->label())
                    ->color(fn ($state) => $state === TipoMovimentoCarteiraEnum::CREDITO_COMISSAO || $state === TipoMovimentoCarteiraEnum::ESTORNO_CONSUMO ? 'success' : 'gray'),
                TextColumn::make('cm_valor')->label('Valor')->money('BRL')->weight('bold')
                    ->color(fn ($state): string => (float) $state < 0 ? 'danger' : 'success'),
                TextColumn::make('cm_observacao')->label('Observação')->placeholder('—')->wrap(),
                TextColumn::make('usuario.name')->label('Por')->placeholder('Sistema'),
            ])
            ->headerActions([
                $this->pagarPixAction(),
                $this->ajustarAction(),
            ])
            ->paginated([10, 25, 50]);
    }

    private function pagarPixAction(): Action
    {
        return Action::make('pagarPix')
            ->label('Pagar saldo em Pix')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->authorize(fn (): bool => (bool) Auth::user()?->can('create:resgate_carteira'))
            ->modalHeading('Pagar saldo em Pix')
            ->modalDescription('Gera a despesa de marketing paga no Financeiro e debita a carteira. Guarde o comprovante.')
            ->fillForm(fn (): array => ['valor' => $this->saldo()])
            ->schema([
                TextInput::make('valor')
                    ->label('Valor')
                    ->numeric()
                    ->minValue(0.01)
                    ->prefix('R$')
                    ->required()
                    ->helperText(fn (): string => 'Saldo disponível: R$ '.number_format($this->saldo(), 2, ',', '.')),
                FileUpload::make('comprovante')
                    ->label('Comprovante')
                    ->disk('local')
                    ->directory('comprovantes_resgate')
                    ->visibility('private')
                    ->acceptedFileTypes(['application/pdf', 'image/png', 'image/jpeg']),
            ])
            ->action(function (array $data, ResgatePixService $service): void {
                try {
                    $resgate = $service->pagar($this->getOwnerRecord(), (float) $data['valor'], Auth::user(), $data['comprovante'] ?? null);
                } catch (CarteiraInfluenciadorException|FinanceiroException $e) {
                    Notification::make()->title('Resgate não realizado')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title("Resgate #{$resgate->id} pago")->success()->send();
            });
    }

    private function ajustarAction(): Action
    {
        return Action::make('ajustar')
            ->label('Ajustar')
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->color('gray')
            ->authorize(fn (): bool => (bool) Auth::user()?->can('ajustar:carteira_influenciador'))
            ->modalHeading('Ajuste manual da carteira')
            ->schema([
                TextInput::make('valor')
                    ->label('Valor do ajuste')
                    ->numeric()
                    ->prefix('R$')
                    ->required()
                    ->helperText('Positivo credita, negativo debita.'),
                Textarea::make('observacao')
                    ->label('Motivo')
                    ->required()
                    ->minLength(5)
                    ->maxLength(255),
            ])
            ->action(function (array $data, CarteiraInfluenciadorService $service): void {
                try {
                    $service->ajustar($this->getOwnerRecord(), (float) $data['valor'], $data['observacao'], Auth::user());
                } catch (CarteiraInfluenciadorException $e) {
                    Notification::make()->title('Ajuste não realizado')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Ajuste registrado')->success()->send();
            });
    }

    private function saldo(): float
    {
        return $this->getOwnerRecord()->fresh()->saldoCarteira();
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }
}
