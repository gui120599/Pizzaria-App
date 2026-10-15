<?php

namespace App\Filament\Resources\Repasses\Schemas;

use App\Enums\Ponto\NaturezaItem;
use App\Enums\Ponto\TipoItemRepasse;
use App\Enums\Ponto\VinculoColaborador;
use App\Filament\Resources\ContasFinanceiras\Support\ValorMonetario;
use App\Models\Repasse;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Leandrocfe\FilamentPtbrFormFields\Money;

class RepasseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Itens manuais')
                ->description('Os itens automáticos (jornada e adiantamentos) são refeitos pelo "Recalcular". Aqui entram INSS/IRRF do holerite, bônus, ajuda de custo e outros.')
                ->columnSpanFull()
                ->schema([
                    Repeater::make('itens')
                        ->hiddenLabel()
                        ->relationship('itens', fn (Builder $query): Builder => $query->where('automatico', false))
                        ->schema([
                            Select::make('natureza')
                                ->label('Item')
                                ->options([
                                    'Proventos' => NaturezaItem::opcoesDe(TipoItemRepasse::Provento),
                                    'Descontos' => NaturezaItem::opcoesDe(TipoItemRepasse::Desconto),
                                ])
                                ->required(),
                            TextInput::make('descricao')->label('Descrição')->required()->maxLength(255),
                            Money::make('valor')->label('Valor')->minValue(0.01)->required(),
                        ])
                        ->columns(3)
                        ->addActionLabel('Adicionar item')
                        ->reorderable(false)
                        ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => self::normalizarItem($data))
                        ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): array => self::normalizarItem($data)),
                ]),
            Section::make('Conferência')
                ->columnSpanFull()
                ->schema([
                    Toggle::make('conferido_holerite')
                        ->label('Conferido com o holerite da contabilidade')
                        ->helperText('Obrigatório para aprovar repasse de CLT.')
                        ->visible(fn (Repasse $record): bool => $record->colaborador->vinculo === VinculoColaborador::Clt),
                    Textarea::make('observacao')->label('Observação')->rows(2),
                ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private static function normalizarItem(array $item): array
    {
        $valor = ValorMonetario::normalizar($item['valor'] ?? null);

        return [...$item, 'automatico' => false, 'quantidade' => 1, 'valor_unitario' => $valor, 'valor' => $valor];
    }
}
