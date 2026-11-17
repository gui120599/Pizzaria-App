<?php

namespace App\Filament\Resources\PromocoesAdicionais\Pages;

use App\Filament\Resources\PromocoesAdicionais\PromocaoAdicionalResource;
use App\Filament\Resources\PromocoesAdicionais\Schemas\PromocaoAdicionalForm;
use App\Models\Categoria;
use App\Models\Produto;
use App\Models\PromocaoAdicional;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

class EditPromocaoAdicional extends EditRecord
{
    protected static string $resource = PromocaoAdicionalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->adicionarGatilhosPorCategoriaAction(),
            DeleteAction::make(),
            RestoreAction::make(),
            ForceDeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return PromocaoAdicionalForm::normalizarQuantidadeLimitada($data);
    }

    /**
     * Atalho para "qualquer pizza grande": cria uma regra por produto da
     * categoria, todas com a mesma oferta. Grava direto no banco e recarrega
     * a página, sem mexer no estado do Repeater.
     */
    private function adicionarGatilhosPorCategoriaAction(): Action
    {
        return Action::make('adicionarGatilhosPorCategoria')
            ->label('Adicionar gatilhos por categoria')
            ->icon(Heroicon::OutlinedSquaresPlus)
            ->color('gray')
            ->modalDescription('Cria uma regra para cada produto da categoria (ex.: todas as pizzas grandes), todas oferecendo o mesmo produto. Produtos que já são gatilho nesta campanha ficam como estão. Salve o formulário antes: alterações não salvas são descartadas.')
            ->schema([
                Select::make('categoria_id')
                    ->label('Categoria dos produtos gatilho')
                    ->options(fn (): array => Categoria::orderBy('categoria_nome')->pluck('categoria_nome', 'id')->all())
                    ->searchable()
                    ->required(),
                Select::make('produto_oferta_id')
                    ->label('Produto oferecido')
                    ->searchable()
                    ->allowHtml()
                    ->getSearchResultsUsing(fn (string $search): array => PromocaoAdicionalForm::opcoesProduto($search))
                    ->getOptionLabelUsing(fn ($value): ?string => PromocaoAdicionalForm::opcaoProdutoSelecionado($value))
                    ->required(),
                TextInput::make('valor_adicional')
                    ->label('Valor adicional')
                    ->helperText('0 = grátis (vira cortesia no pedido).')
                    ->numeric()
                    ->prefix('R$')
                    ->minValue(0)
                    ->default(0)
                    ->required(),
                TextInput::make('qtd_maxima_por_pedido')
                    ->label('Máx. aceites por pedido (por produto)')
                    ->helperText('Em branco = sem limite.')
                    ->integer()
                    ->minValue(1),
            ])
            ->action(function (array $data): void {
                /** @var PromocaoAdicional $promocao */
                $promocao = $this->getRecord();
                $criadas = self::criarRegrasDaCategoria($promocao, $data);

                Notification::make()
                    ->title($criadas === 0 ? 'Nenhuma regra nova: os produtos já eram gatilho.' : "{$criadas} regra(s) criada(s).")
                    ->success()
                    ->send();

                $this->redirect(static::getResource()::getUrl('edit', ['record' => $promocao]));
            });
    }

    /**
     * @param  array{categoria_id: int|string, produto_oferta_id: int|string, valor_adicional: int|float|string, qtd_maxima_por_pedido?: int|string|null}  $data
     */
    private static function criarRegrasDaCategoria(PromocaoAdicional $promocao, array $data): int
    {
        $jaGatilhos = $promocao->regras()->pluck('par_produto_gatilho_id')->all();
        $produtos = Produto::where('produto_categoria_id', $data['categoria_id'])
            ->whereNotIn('id', $jaGatilhos)
            ->pluck('id');

        DB::transaction(function () use ($promocao, $produtos, $data) {
            foreach ($produtos as $produtoId) {
                $promocao->regras()->create([
                    'par_produto_gatilho_id' => $produtoId,
                    'par_qtd_maxima_por_pedido' => filled($data['qtd_maxima_por_pedido'] ?? null) ? (int) $data['qtd_maxima_por_pedido'] : null,
                ])->ofertas()->create([
                    'pao_produto_oferta_id' => (int) $data['produto_oferta_id'],
                    'pao_valor_adicional' => round((float) $data['valor_adicional'], 2),
                ]);
            }
        });

        return $produtos->count();
    }
}
