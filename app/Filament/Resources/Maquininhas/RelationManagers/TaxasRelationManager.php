<?php

namespace App\Filament\Resources\Maquininhas\RelationManagers;

use App\Enums\PrazoRecebimentoMaquininhaEnum;
use App\Enums\TipoPagamentoMaquininhaEnum;
use App\Models\CartoesPagamento;
use App\Models\MaquininhaTaxa;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

/**
 * Taxas da adquirente (MDR) da maquininha por tipo e bandeira. Sem bandeira
 * = vale para qualquer bandeira daquele tipo. Usadas por TaxaMaquininhaService
 * para gravar o custo de cada pagamento.
 */
class TaxasRelationManager extends RelationManager
{
    protected static string $relationship = 'taxas';

    protected static ?string $title = 'Taxas da maquininha';

    protected static ?string $modelLabel = 'taxa';

    protected static ?string $pluralModelLabel = 'taxas';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('mt_tipo')
                    ->label('Tipo')
                    ->options(TipoPagamentoMaquininhaEnum::class)
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set, $state): void {
                        if ($state === TipoPagamentoMaquininhaEnum::Pix || $state === TipoPagamentoMaquininhaEnum::Pix->value) {
                            $set('mt_cartao_id', null);
                        }
                    })
                    ->unique(
                        table: MaquininhaTaxa::class,
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule
                            ->where('mt_maquininha_id', $this->getOwnerRecord()->getKey())
                            ->when(
                                filled($get('mt_cartao_id')),
                                fn (Unique $rule) => $rule->where('mt_cartao_id', $get('mt_cartao_id')),
                                fn (Unique $rule) => $rule->whereNull('mt_cartao_id'),
                            ),
                    )
                    ->validationMessages(['unique' => 'Já existe uma taxa para este tipo e bandeira nesta maquininha.']),
                Select::make('mt_cartao_id')
                    ->label('Bandeira')
                    ->options(fn (): array => self::bandeiras())
                    ->placeholder('Todas as bandeiras')
                    ->helperText('Deixe em branco para valer para qualquer bandeira deste tipo.')
                    ->disabled(fn (Get $get): bool => in_array($get('mt_tipo'), [TipoPagamentoMaquininhaEnum::Pix, TipoPagamentoMaquininhaEnum::Pix->value], true)),
                TextInput::make('mt_percentual')
                    ->label('Taxa (%)')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->step(0.01)
                    ->suffix('%')
                    ->required(),
                Select::make('mt_prazo_recebimento')
                    ->label('Recebimento')
                    ->options(PrazoRecebimentoMaquininhaEnum::class)
                    ->placeholder('Não informado')
                    ->helperText('Como está configurado no aplicativo da maquininha. "Mesmo dia": vendas depois das 22h caem no dia seguinte.'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('mt_tipo')
            ->defaultSort('mt_tipo')
            ->columns([
                TextColumn::make('mt_tipo')
                    ->label('Tipo')
                    ->badge(),
                TextColumn::make('mt_cartao_id')
                    ->label('Bandeira')
                    ->formatStateUsing(fn (?int $state): string => self::bandeiras()[$state] ?? 'Todas')
                    ->placeholder('Todas'),
                TextColumn::make('mt_percentual')
                    ->label('Taxa')
                    ->suffix('%'),
                TextColumn::make('mt_prazo_recebimento')
                    ->label('Recebimento')
                    ->placeholder('Não informado'),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    /** @return array<int, string> */
    private static function bandeiras(): array
    {
        return CartoesPagamento::query()
            ->get()
            ->mapWithKeys(fn (CartoesPagamento $cartao): array => [
                $cartao->id => (string) ($cartao->cartao_bandeira ?: $cartao->getRawOriginal('cartao_bandeira')),
            ])
            ->all();
    }
}
