<?php

namespace App\Filament\Resources\MovimentacoesFinanceiras\Tables;

use App\Enums\Financeiro\NaturezaMovimentacao;
use App\Enums\Financeiro\TipoMovimentacao;
use App\Filament\Resources\Lancamentos\LancamentoResource;
use App\Filament\Resources\MovimentacoesFinanceiras\Support\EstornarMovimentacaoAction;
use App\Filament\Resources\SessoesCaixa\SessaoCaixaResource;
use App\Models\ContaFinanceira;
use App\Models\MovimentacaoFinanceira;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class MovimentacoesFinanceirasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['conta', 'lancamento', 'pagamento', 'adiantamento.colaborador', 'registradoPor', 'estorno']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('data_movimento')
                    ->label('Data')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('conta.nome')
                    ->label('Conta'),
                TextColumn::make('natureza')
                    ->label('Natureza')
                    ->badge(),
                TextColumn::make('descricao')
                    ->label('Descrição')
                    ->searchable()
                    ->wrap()
                    ->description(fn (MovimentacaoFinanceira $record): ?string => match (true) {
                        $record->estorno !== null => 'Estornada',
                        $record->sessao_caixa_id !== null => "Sessão de caixa #{$record->sessao_caixa_id}",
                        default => null,
                    }),
                TextColumn::make('valor')
                    ->label('Valor')
                    ->state(fn (MovimentacaoFinanceira $record): string => ($record->tipo === TipoMovimentacao::Saida ? '− ' : '+ ')
                        .'R$ '.number_format((float) $record->valor, 2, ',', '.'))
                    ->color(fn (MovimentacaoFinanceira $record): string => $record->tipo->getColor())
                    ->alignEnd(),
                TextColumn::make('origem')
                    ->label('Origem')
                    ->state(fn (MovimentacaoFinanceira $record): ?string => match (true) {
                        $record->adiantamento !== null => "Vale — {$record->adiantamento->colaborador?->nome}",
                        $record->lancamento?->maquininha_id !== null => "Recebível #{$record->lancamento_id}",
                        $record->lancamento !== null && $record->lancamento->sessao_caixa_id !== null => "Lançamento #{$record->lancamento_id} — sessão #{$record->lancamento->sessao_caixa_id}",
                        $record->lancamento !== null => "Lançamento #{$record->lancamento_id}",
                        $record->transferencia_uuid !== null && $record->sessao_caixa_id !== null => "Transferência — sessão #{$record->sessao_caixa_id}",
                        $record->transferencia_uuid !== null => 'Transferência',
                        default => null,
                    })
                    ->url(fn (MovimentacaoFinanceira $record): ?string => match (true) {
                        $record->lancamento_id !== null => LancamentoResource::getUrl('edit', ['record' => $record->lancamento_id]),
                        $record->sessao_caixa_id !== null => SessaoCaixaResource::getUrl('edit', ['record' => $record->sessao_caixa_id]),
                        default => null,
                    })
                    ->placeholder('—'),
                IconColumn::make('conciliado')
                    ->label('Conciliada')
                    ->state(fn (MovimentacaoFinanceira $record): ?bool => $record->pagamento === null ? null : $record->pagamento->conciliado_em !== null)
                    ->boolean()
                    ->placeholder('—'),
                TextColumn::make('registradoPor.name_first')
                    ->label('Por')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('conta_financeira_id')
                    ->label('Conta')
                    ->options(fn (): array => ContaFinanceira::withTrashed()->ordenadas()->pluck('nome', 'id')->all()),
                SelectFilter::make('natureza')
                    ->label('Natureza')
                    ->options(NaturezaMovimentacao::class),
                SelectFilter::make('tipo')
                    ->label('Entrada/saída')
                    ->options(TipoMovimentacao::class),
                Filter::make('nao_conciliadas')
                    ->label('Não conciliadas')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereHas('pagamento', fn (Builder $q) => $q->whereNull('conciliado_em'))),
                Filter::make('periodo')
                    ->schema([
                        DatePicker::make('de')->label('De')->native(false)->displayFormat('d/m/Y'),
                        DatePicker::make('ate')->label('Até')->native(false)->displayFormat('d/m/Y'),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['de'] ?? null, fn (Builder $q, string $de): Builder => $q->whereDate('data_movimento', '>=', $de))
                        ->when($data['ate'] ?? null, fn (Builder $q, string $ate): Builder => $q->whereDate('data_movimento', '<=', $ate)))
                    ->indicateUsing(fn (array $data): array => array_values(array_filter([
                        ($data['de'] ?? null) ? 'De '.Carbon::parse($data['de'])->format('d/m/Y') : null,
                        ($data['ate'] ?? null) ? 'Até '.Carbon::parse($data['ate'])->format('d/m/Y') : null,
                    ]))),
            ])
            ->recordActions([
                Action::make('conciliar')
                    ->label(fn (MovimentacaoFinanceira $record): string => $record->pagamento?->conciliado_em ? 'Desmarcar conciliação' : 'Marcar conciliada')
                    ->icon('heroicon-o-check-badge')
                    ->color('gray')
                    ->visible(fn (MovimentacaoFinanceira $record): bool => $record->pagamento !== null
                        && $record->estorno_de_id === null
                        && $record->estorno === null
                        && auth()->user()?->can('conciliar:lancamento'))
                    ->requiresConfirmation()
                    ->action(function (MovimentacaoFinanceira $record): void {
                        $record->pagamento->update(['conciliado_em' => $record->pagamento->conciliado_em ? null : now()]);
                    }),
                EstornarMovimentacaoAction::make(),
            ]);
    }
}
