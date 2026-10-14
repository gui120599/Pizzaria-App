<?php

namespace Tests\Support;

use App\Enums\PedidoOrigemEnum;
use App\Enums\ProdutoTipoEnum;
use App\Enums\StatusPedidoEnum;
use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\Mesa;
use App\Models\OpcoesEntregas;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\RotaProducao;
use App\Models\SessaoMesa;
use App\Models\SetorProducao;
use App\Models\User;
use App\Support\Cozinha\Operador;

/**
 * Montagem de cenários do módulo de cozinha: Pedido, Produto e Categoria não
 * têm factory útil no projeto. O pedido nasce INICIADO e `abrir()` o leva a
 * ABERTO pelo model (os observers geram a produção).
 */
trait CenarioCozinha
{
    protected function categoria(string $nome, ?Categoria $pai = null): Categoria
    {
        return Categoria::create(['categoria_nome' => $nome, 'categoria_pai_id' => $pai?->id]);
    }

    /** @param  array<string, mixed>  $extras */
    protected function produto(string $nome, Categoria $categoria, array $extras = []): Produto
    {
        return Produto::create($extras + [
            'produto_descricao' => $nome,
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
        ]);
    }

    protected function rotaCategoria(Categoria $categoria, SetorProducao $setor, int $etapa = 1, ?int $tempo = null): RotaProducao
    {
        return RotaProducao::create(['categoria_id' => $categoria->id, 'setor_producao_id' => $setor->id, 'etapa' => $etapa, 'tempo_previsto_min' => $tempo]);
    }

    protected function rotaProduto(Produto $produto, SetorProducao $setor, int $etapa = 1, ?int $tempo = null): RotaProducao
    {
        return RotaProducao::create(['produto_id' => $produto->id, 'setor_producao_id' => $setor->id, 'etapa' => $etapa, 'tempo_previsto_min' => $tempo]);
    }

    protected function pedidoMesa(?User $garcom = null): Pedido
    {
        $mesa = Mesa::create(['mesa_nome' => '7', 'mesa_status' => 'OCUPADA']);
        $sessao = SessaoMesa::create([
            'sessao_mesa_mesa_id' => $mesa->id,
            'sessao_mesa_usuario_id' => $garcom?->id ?? auth()->id(),
            'sessao_mesa_status' => 'ABERTA',
        ]);

        return Pedido::create([
            'pedido_status' => StatusPedidoEnum::INICIADO->value,
            'pedido_origem' => PedidoOrigemEnum::MESA->value,
            'pedido_sessao_mesa_id' => $sessao->id,
            'pedido_usuario_garcom_id' => $garcom?->id,
        ]);
    }

    protected function pedidoDelivery(): Pedido
    {
        $entrega = OpcoesEntregas::create(['opcaoentrega_nome' => 'Delivery', 'opcaoentrega_requer_endereco' => true]);

        return Pedido::create([
            'pedido_status' => StatusPedidoEnum::INICIADO->value,
            'pedido_origem' => PedidoOrigemEnum::ATENDENTE->value,
            'pedido_opcaoentrega_id' => $entrega->id,
            'pedido_endereco_entrega' => 'Rua A, 1',
        ]);
    }

    /** @param  array<string, mixed>  $extras */
    protected function item(Pedido $pedido, Produto $produto, float $quantidade = 1, array $extras = []): ItensPedido
    {
        return ItensPedido::create($extras + [
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => $produto->id,
            'item_pedido_quantidade' => $quantidade,
            'item_pedido_valor_unitario' => 40,
            'item_pedido_valor' => 40 * $quantidade,
            'item_pedido_status' => 'INSERIDO',
        ]);
    }

    /** Pizza meio a meio em linha única (o produto da linha é o 1º sabor). */
    protected function itemMeioAMeio(Pedido $pedido, Produto $sabor1, Produto $sabor2, array $extras = []): ItensPedido
    {
        return $this->item($pedido, $sabor1, 1, $extras + [
            'item_pedido_sabores' => [
                ['produto_id' => $sabor1->id, 'nome' => $sabor1->produto_descricao, 'percentual' => 50, 'rotulo' => 'MEIA'],
                ['produto_id' => $sabor2->id, 'nome' => $sabor2->produto_descricao, 'percentual' => 50, 'rotulo' => 'MEIA'],
            ],
        ]);
    }

    protected function abrir(Pedido $pedido): Pedido
    {
        $pedido->update(['pedido_status' => StatusPedidoEnum::ABERTO->value, 'pedido_datahora_abertura' => now()]);

        return $pedido->refresh();
    }

    protected function operador(?User $user = null): Operador
    {
        return Operador::doUsuario($user ?? User::factory()->cozinha()->create(['name_first' => 'Zé']));
    }
}
