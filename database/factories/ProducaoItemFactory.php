<?php

namespace Database\Factories;

use App\Enums\Cozinha\PrioridadeProducao;
use App\Enums\Cozinha\StatusItemProducao;
use App\Enums\PedidoOrigemEnum;
use App\Enums\ProdutoTipoEnum;
use App\Enums\StatusPedidoEnum;
use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\Pedido;
use App\Models\ProducaoItem;
use App\Models\Produto;
use App\Models\SetorProducao;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Item na fila de um pedido ABERTO de mesa com uma pizza. Pedido, Produto e
 * Categoria não têm factory útil no projeto, então são criados aqui.
 *
 * @extends Factory<ProducaoItem>
 */
class ProducaoItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'item_pedido_id' => fn () => $this->itemPedido()->id,
            'pedido_id' => fn (array $atributos) => ItensPedido::find($atributos['item_pedido_id'])->item_pedido_pedido_id,
            'setor_producao_id' => SetorProducao::factory(),
            'etapa' => 1,
            'etapa_total' => 1,
            'unidade_seq' => 1,
            'geracao' => 1,
            'quantidade' => 1,
            'status' => StatusItemProducao::NaFila,
            'prioridade' => PrioridadeProducao::Normal,
            'snapshot' => [
                'produto' => 'Calabresa',
                'tamanho' => 'Pizza Grande',
                'sabores' => [],
                'adicionais' => [],
                'respostas' => [],
                'remocoes' => [],
                'observacao' => null,
            ],
            'origem' => PedidoOrigemEnum::MESA,
            'referencia' => 'Mesa 7',
            'liberado_em' => now(),
            'versao' => 0,
        ];
    }

    public function status(StatusItemProducao $status): static
    {
        return $this->state(fn () => ['status' => $status] + match ($status) {
            StatusItemProducao::Aguardando => ['liberado_em' => null],
            StatusItemProducao::EmPreparo => ['iniciado_em' => now()],
            StatusItemProducao::Pronto => ['iniciado_em' => now(), 'concluido_em' => now()],
            StatusItemProducao::Expedido => ['iniciado_em' => now(), 'concluido_em' => now(), 'expedido_em' => now()],
            StatusItemProducao::Cancelado => ['cancelado_em' => now()],
            default => [],
        });
    }

    public function doSetor(SetorProducao $setor): static
    {
        return $this->state(['setor_producao_id' => $setor->id]);
    }

    public function doItem(ItensPedido $item): static
    {
        return $this->state(['item_pedido_id' => $item->id, 'pedido_id' => $item->item_pedido_pedido_id]);
    }

    public function prioridade(PrioridadeProducao $prioridade): static
    {
        return $this->state(['prioridade' => $prioridade]);
    }

    private function itemPedido(): ItensPedido
    {
        $pedido = Pedido::create([
            'pedido_status' => StatusPedidoEnum::ABERTO->value,
            'pedido_origem' => PedidoOrigemEnum::MESA->value,
            'pedido_datahora_abertura' => now(),
        ]);

        $produto = Produto::create([
            'produto_descricao' => 'Calabresa '.fake()->unique()->numerify('####'),
            'produto_categoria_id' => Categoria::create(['categoria_nome' => 'Pizza Grande '.fake()->unique()->numerify('####')])->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
        ]);

        return ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $produto->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 40,
            'item_pedido_valor' => 40,
            'item_pedido_status' => 'INSERIDO',
        ]);
    }
}
