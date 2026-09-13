<?php

namespace Tests\Feature;

use App\Enums\PrestadorCategoriaEnum;
use App\Enums\PrestadorTipoEnum;
use App\Enums\ProdutoTipoEnum;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\ItensPedido;
use App\Models\Lancamento;
use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\Prestador;
use App\Models\Produto;
use App\Models\SessaoMesa;
use App\Models\SessaoMesaCliente;
use App\Models\User;
use App\Models\Venda;
use App\Services\ClienteMergeService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cobre a unificação de clientes duplicados: reatribuição das 7 tabelas com FK
 * para clientes.id, preenchimento de campos vazios do principal e soft delete
 * dos perdedores. Ver App\Filament\Resources\Clientes\Tables\Actions\UnificarClientesBulkAction
 * para a UI que aciona este serviço.
 */
class ClienteMergeServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_unifica_reatribui_todas_as_tabelas_relacionadas_e_apaga_perdedor(): void
    {
        $principal = Cliente::create([
            'cliente_nome' => 'João Principal',
            'cliente_celular' => '11999990000',
            'cliente_tipo' => 'Física',
        ]);

        $perdedor = Cliente::create([
            'cliente_nome' => 'João Duplicado',
            'cliente_cpf' => '12345678900',
            'cliente_email' => 'joao@example.com',
            'cliente_tipo' => 'Física',
        ]);

        $categoria = Categoria::create(['categoria_nome' => 'Pizzas']);
        $produto = Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 50.0,
        ]);

        $pedido = Pedido::create([
            'pedido_status' => 'FINALIZADO',
            'pedido_cliente_id' => $perdedor->id,
        ]);

        $item = ItensPedido::create([
            'item_pedido_produto_id' => $produto->id,
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_cliente_id' => $perdedor->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor_unitario' => 50,
            'item_pedido_valor' => 50,
            'item_pedido_status' => 'INSERIDO',
        ]);

        $venda = Venda::create([
            'venda_cliente_id' => $perdedor->id,
            'venda_status' => 'FINALIZADA',
        ]);

        $mesa = Mesa::create(['mesa_nome' => 'Mesa 1']);
        $garcom = User::factory()->create(['name_first' => 'Garçom']);

        $sessaoMesa = SessaoMesa::create([
            'sessao_mesa_mesa_id' => $mesa->id,
            'sessao_mesa_cliente_id' => $perdedor->id,
            'sessao_mesa_usuario_id' => $garcom->id,
            'sessao_mesa_status' => 'ABERTA',
        ]);

        // Convidado extra já vinculado ao PRINCIPAL na mesma sessão: reatribuir o
        // perdedor geraria uma 2ª linha pro mesmo par (sessão, cliente), que o
        // merge precisa deduplicar.
        SessaoMesaCliente::create([
            'smc_sessao_mesa_id' => $sessaoMesa->id,
            'smc_cliente_id' => $principal->id,
        ]);
        SessaoMesaCliente::create([
            'smc_sessao_mesa_id' => $sessaoMesa->id,
            'smc_cliente_id' => $perdedor->id,
        ]);

        $prestador = Prestador::create([
            'tipo' => PrestadorTipoEnum::PF->value,
            'categoria' => PrestadorCategoriaEnum::AUTONOMO->value,
            'nome' => 'Fulano',
            'cliente_id' => $perdedor->id,
        ]);

        $lancamento = Lancamento::create([
            'tipo' => TipoLancamento::Receber->value,
            'cliente_id' => $perdedor->id,
            'descricao' => 'Fiado',
            'valor' => 100,
            'vencimento' => now()->addDays(5),
            'status' => StatusLancamento::Pendente->value,
        ]);

        app(ClienteMergeService::class)->unificar($principal, new Collection([$perdedor]));

        $this->assertSame($principal->id, $pedido->fresh()->pedido_cliente_id);
        $this->assertSame($principal->id, $item->fresh()->item_pedido_cliente_id);
        $this->assertSame($principal->id, $venda->fresh()->venda_cliente_id);
        $this->assertSame($principal->id, $sessaoMesa->fresh()->sessao_mesa_cliente_id);
        $this->assertSame($principal->id, $prestador->fresh()->cliente_id);
        $this->assertSame($principal->id, $lancamento->fresh()->cliente_id);

        // sessao_mesa_clientes: só deve sobrar 1 linha do principal nessa sessão (dedupe).
        $this->assertSame(1, SessaoMesaCliente::where('smc_sessao_mesa_id', $sessaoMesa->id)
            ->where('smc_cliente_id', $principal->id)
            ->count());

        // Campos vazios do principal foram preenchidos com os do perdedor.
        $principal->refresh();
        $this->assertSame('12345678900', $principal->cliente_cpf);
        $this->assertSame('joao@example.com', $principal->cliente_email);

        $this->assertSoftDeleted('clientes', ['id' => $perdedor->id]);
    }

    public function test_nao_faz_nada_quando_nao_ha_perdedor_distinto_do_principal(): void
    {
        $principal = Cliente::create(['cliente_nome' => 'Único', 'cliente_tipo' => 'Física']);

        app(ClienteMergeService::class)->unificar($principal, new Collection([$principal]));

        $this->assertNotSoftDeleted('clientes', ['id' => $principal->id]);
    }
}
