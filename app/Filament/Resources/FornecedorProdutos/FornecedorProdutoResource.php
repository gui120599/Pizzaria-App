<?php

namespace App\Filament\Resources\FornecedorProdutos;

use App\Enums\Compras\OrigemVinculo;
use App\Filament\Resources\FornecedorProdutos\Pages\ManageFornecedorProdutos;
use App\Models\FornecedorProduto;
use App\Models\Prestador;
use App\Models\Produto;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

/**
 * De-para fornecedor × produto (fornecedor_produtos) de todos os
 * fornecedores numa tela só: o que a importação de NF-e usa para reconhecer
 * os itens. Editar aqui marca o vínculo como confirmado manualmente.
 */
class FornecedorProdutoResource extends Resource
{
    protected static ?string $model = FornecedorProduto::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static UnitEnum|string|null $navigationGroup = 'Estoque';

    protected static ?string $navigationLabel = 'De-para de fornecedores';

    protected static ?string $modelLabel = 'vínculo de produto';

    protected static ?string $pluralModelLabel = 'de-para de fornecedores';

    protected static ?int $navigationSort = 11;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('fp_prestador_id')
                ->label('Fornecedor')
                ->options(fn (): array => Prestador::fornecedores()->get()->mapWithKeys(fn (Prestador $p): array => [$p->id => $p->nome_exibicao])->all())
                ->searchable()
                ->required(),

            Select::make('fp_produto_id')
                ->label('Produto (no estoque)')
                ->options(fn (): array => Produto::query()->orderBy('produto_descricao')->pluck('produto_descricao', 'id')->all())
                ->searchable()
                ->required(),

            TextInput::make('fp_codigo_fornecedor')
                ->label('Código no fornecedor (cProd)')
                ->required()
                ->maxLength(60)
                ->unique(
                    table: FornecedorProduto::class,
                    column: 'fp_codigo_fornecedor',
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule->where('fp_prestador_id', $get('fp_prestador_id')),
                )
                ->validationMessages(['unique' => 'Este código já está vinculado para este fornecedor (confira também os excluídos).']),

            TextInput::make('fp_ean')
                ->label('EAN')
                ->maxLength(14),

            TextInput::make('fp_descricao_fornecedor')
                ->label('Descrição na nota')
                ->maxLength(255),

            TextInput::make('fp_unidade_compra')
                ->label('Unidade de compra')
                ->maxLength(10),

            TextInput::make('fp_fator_conversao')
                ->label('Fator para o estoque')
                ->numeric()
                ->step(0.0001)
                ->minValue(0.0001)
                ->default(1)
                ->required()
                ->helperText('1 unidade da nota = X unidades de estoque (ex.: 1 PCT = 4 kg → 4).'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['prestador', 'produto']))
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('prestador.razao_social')
                    ->label('Fornecedor')
                    ->state(fn (FornecedorProduto $record): string => $record->prestador?->nome_exibicao ?? '—')
                    ->searchable(['razao_social', 'nome_fantasia', 'nome']),
                TextColumn::make('fp_codigo_fornecedor')->label('Cód. forn.')->searchable(),
                TextColumn::make('fp_descricao_fornecedor')->label('Descrição na nota')->searchable()->placeholder('—')->wrap(),
                TextColumn::make('produto.produto_descricao')->label('Produto')->searchable(),
                TextColumn::make('fp_unidade_compra')->label('Un.')->placeholder('—'),
                TextColumn::make('fp_fator_conversao')->label('Fator')->numeric(decimalPlaces: 4)->alignEnd(),
                TextColumn::make('fp_ultimo_custo_unitario')->label('Último custo')->money('BRL')->placeholder('—')->alignEnd(),
                TextColumn::make('fp_origem')->label('Origem')->badge()->placeholder('—'),
                TextColumn::make('fp_ean')->label('EAN')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('confirmadoPor.name_first')->label('Confirmado por')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->label('Atualizado em')->dateTime('d/m/Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('fp_prestador_id')
                    ->label('Fornecedor')
                    ->options(fn (): array => Prestador::fornecedores()->get()->mapWithKeys(fn (Prestador $p): array => [$p->id => $p->nome_exibicao])->all())
                    ->searchable(),
                SelectFilter::make('fp_origem')
                    ->label('Origem')
                    ->options(OrigemVinculo::class),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make()->mutateDataUsing(fn (array $data): array => self::confirmadoManualmente($data)),
                DeleteAction::make(),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Vínculo criado ou editado nesta tela passa a valer como confirmação manual.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function confirmadoManualmente(array $data): array
    {
        return [...$data, 'fp_origem' => OrigemVinculo::Manual->value, 'fp_confirmado_por' => auth()->id()];
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageFornecedorProdutos::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
