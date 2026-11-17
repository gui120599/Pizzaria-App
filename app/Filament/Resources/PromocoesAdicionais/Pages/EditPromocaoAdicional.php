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
use Filament\Forms\Components\Repeater;
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
     * categoria, todas com as mesmas ofertas. Produto que já é gatilho ganha
     * só as ofertas que ainda não tem. Grava direto no banco e recarrega a
     * página, sem mexer no estado do Repeater do formulário.
     */
    private function adicionarGatilhosPorCategoriaAction(): Action
    {
        return Action::make('adicionarGatilhosPorCategoria')
            ->label('Adicionar gatilhos por categoria')
            ->icon(Heroicon::OutlinedSquaresPlus)
            ->color('gray')
            ->modalWidth('3xl')
            ->modalDescription('Cria uma regra para cada produto da categoria (ex.: todas as pizzas grandes) com os produtos oferecidos abaixo. Produto que já é gatilho nesta campanha ganha só as ofertas que ainda não tem; as existentes não mudam. Salve o formulário antes: alterações não salvas são descartadas.')
            ->schema([
                Select::make('categoria_id')
                    ->label('Categoria dos produtos gatilho')
                    ->options(fn (): array => Categoria::orderBy('categoria_nome')->pluck('categoria_nome', 'id')->all())
                    ->searchable()
                    ->required(),
                TextInput::make('qtd_maxima_por_pedido')
                    ->label('Máx. aceites por pedido (por produto)')
                    ->helperText('Vale só para as regras novas. Em branco = sem limite.')
                    ->integer()
                    ->minValue(1),
                // Mesmos campos do Repeater "Produtos oferecidos" do formulário
                // (TextInput numérico no valor, pelo mesmo motivo de lá).
                Repeater::make('ofertas')
                    ->label('Produtos oferecidos')
                    ->addActionLabel('Adicionar produto oferecido')
                    ->columns(3)
                    ->minItems(1)
                    ->defaultItems(1)
                    ->schema([
                        Select::make('pao_produto_oferta_id')
                            ->label('Produto oferecido')
                            ->helperText('O cliente escolhe no máximo 1 das opções.')
                            ->placeholder('Digite o nome do produto...')
                            ->searchable()
                            ->allowHtml()
                            ->getSearchResultsUsing(fn (string $search): array => PromocaoAdicionalForm::opcoesProduto($search))
                            ->getOptionLabelUsing(fn ($value): ?string => PromocaoAdicionalForm::opcaoProdutoSelecionado($value))
                            ->native(false)
                            ->distinct()
                            ->required(),
                        TextInput::make('pao_valor_adicional')
                            ->label('Valor adicional')
                            ->helperText('0 = grátis (vira cortesia no pedido).')
                            ->numeric()
                            ->prefix('R$')
                            ->step(0.01)
                            ->minValue(0)
                            ->default(0)
                            ->required(),
                        TextInput::make('pao_qtd_total')
                            ->label('Quantidade total desta oferta')
                            ->helperText('Por regra. Em branco = ilimitado.')
                            ->integer()
                            ->minValue(1),
                    ]),
            ])
            ->action(function (array $data): void {
                /** @var PromocaoAdicional $promocao */
                $promocao = $this->getRecord();
                ['regras' => $regras, 'ofertas' => $ofertas] = self::criarRegrasDaCategoria($promocao, $data);

                Notification::make()
                    ->title($regras === 0 && $ofertas === 0
                        ? 'Nada novo: os produtos já eram gatilho com essas ofertas.'
                        : "{$regras} regra(s) criada(s), {$ofertas} oferta(s) adicionada(s).")
                    ->success()
                    ->send();

                $this->redirect(static::getResource()::getUrl('edit', ['record' => $promocao]));
            });
    }

    /**
     * @param  array{categoria_id: int|string, qtd_maxima_por_pedido?: int|string|null, ofertas: array<array-key, array{pao_produto_oferta_id: int|string, pao_valor_adicional: int|float|string, pao_qtd_total?: int|string|null}>}  $data
     * @return array{regras: int, ofertas: int}
     */
    private static function criarRegrasDaCategoria(PromocaoAdicional $promocao, array $data): array
    {
        $ofertas = collect($data['ofertas'])
            ->map(fn (array $oferta): array => [
                'pao_produto_oferta_id' => (int) $oferta['pao_produto_oferta_id'],
                'pao_valor_adicional' => round((float) $oferta['pao_valor_adicional'], 2),
                'pao_qtd_total' => filled($oferta['pao_qtd_total'] ?? null) ? (int) $oferta['pao_qtd_total'] : null,
            ])
            ->unique('pao_produto_oferta_id')
            ->values();

        $produtos = Produto::where('produto_categoria_id', $data['categoria_id'])->pluck('id');
        $existentes = $promocao->regras()->with('ofertas')->get()->keyBy('par_produto_gatilho_id');

        return DB::transaction(function () use ($promocao, $produtos, $existentes, $ofertas, $data): array {
            $contagem = ['regras' => 0, 'ofertas' => 0];

            foreach ($produtos as $produtoId) {
                $regra = $existentes->get($produtoId);

                if (! $regra) {
                    $regra = $promocao->regras()->create([
                        'par_produto_gatilho_id' => $produtoId,
                        'par_qtd_maxima_por_pedido' => filled($data['qtd_maxima_por_pedido'] ?? null) ? (int) $data['qtd_maxima_por_pedido'] : null,
                    ]);
                    $contagem['regras']++;
                }

                $jaOferecidos = $regra->ofertas->pluck('pao_produto_oferta_id')->map(fn ($id) => (int) $id)->all();

                foreach ($ofertas as $oferta) {
                    if (in_array($oferta['pao_produto_oferta_id'], $jaOferecidos, true)) {
                        continue;
                    }

                    $regra->ofertas()->create($oferta);
                    $contagem['ofertas']++;
                }
            }

            return $contagem;
        });
    }
}
