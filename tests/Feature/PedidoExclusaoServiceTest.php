<?php

namespace Tests\Feature;

use App\Enums\MotivoExclusaoPedidoEnum;
use App\Enums\ProdutoTipoEnum;
use App\Enums\StonePedidoStatus;
use App\Exceptions\PedidoNaoPodeSerExcluidoException;
use App\Models\Categoria;
use App\Models\HistoricoStatusPedido;
use App\Models\ItensPedido;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\StonePedido;
use App\Models\User;
use App\Models\Venda;
use App\Services\PedidoExclusaoService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PedidoExclusaoServiceTest extends TestCase
{
    use RefreshDatabase;

    private PedidoExclusaoService $service;

    private User $gerente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->service = app(PedidoExclusaoService::class);
        $this->gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);
    }

    private function pedido(string $status): Pedido
    {
        return Pedido::create(['pedido_status' => $status, 'pedido_valor_total' => 30.0]);
    }

    public function test_exclui_cancelado_gravando_motivo_autor_e_observacao(): void
    {
        $pedido = $this->pedido('CANCELADO');

        $this->service->excluir($pedido, $this->gerente, MotivoExclusaoPedidoEnum::DUPLICADO, 'Lançado duas vezes');

        $this->assertNull(Pedido::find($pedido->id));
        $excluido = Pedido::onlyTrashed()->findOrFail($pedido->id);
        $this->assertSame(MotivoExclusaoPedidoEnum::DUPLICADO, $excluido->pedido_motivo_exclusao);
        $this->assertSame('Lançado duas vezes', $excluido->pedido_exclusao_observacao);
        $this->assertSame($this->gerente->id, $excluido->pedido_usuario_excluiu_id);
    }

    #[DataProvider('statusCancelaveisAntesDeExcluir')]
    public function test_iniciado_e_aberto_passam_por_cancelado_antes_de_excluir(string $status): void
    {
        $pedido = $this->pedido($status);

        $this->service->excluir($pedido, $this->gerente, MotivoExclusaoPedidoEnum::LANCADO_POR_ENGANO);

        $excluido = Pedido::onlyTrashed()->findOrFail($pedido->id);
        $this->assertSame('CANCELADO', $excluido->pedido_status);
        $this->assertSame($this->gerente->id, $excluido->pedido_usuario_cancelou_id);
        $this->assertTrue(
            HistoricoStatusPedido::where('hsp_pedido_id', $pedido->id)->where('hsp_status_para', 'CANCELADO')->exists()
        );
    }

    /** @return array<string, array{string}> */
    public static function statusCancelaveisAntesDeExcluir(): array
    {
        return ['iniciado' => ['INICIADO'], 'aberto' => ['ABERTO']];
    }

    public function test_recusa_preparando_em_diante(): void
    {
        $pedido = $this->pedido('PREPARANDO');

        $this->expectException(PedidoNaoPodeSerExcluidoException::class);

        try {
            $this->service->excluir($pedido, $this->gerente, MotivoExclusaoPedidoEnum::DUPLICADO);
        } finally {
            $this->assertNotNull(Pedido::find($pedido->id));
        }
    }

    public function test_recusa_pedido_com_venda(): void
    {
        $pedido = $this->pedido('CANCELADO');
        $pedido->update(['pedido_venda_id' => Venda::create(['venda_status' => 'FINALIZADA'])->id]);

        $this->expectException(PedidoNaoPodeSerExcluidoException::class);

        $this->service->excluir($pedido, $this->gerente, MotivoExclusaoPedidoEnum::DUPLICADO);
    }

    public function test_recusa_pedido_com_item_vendido(): void
    {
        $pedido = $this->pedido('ABERTO');
        $venda = Venda::create(['venda_status' => 'FINALIZADA']);
        ItensPedido::create([
            'item_pedido_pedido_id' => $pedido->id,
            'item_pedido_produto_id' => Produto::create([
                'produto_descricao' => 'Refrigerante',
                'produto_categoria_id' => Categoria::create(['categoria_nome' => 'Bebidas'])->id,
                'produto_tipo' => ProdutoTipoEnum::REVENDA->value,
            ])->id,
            'item_pedido_quantidade' => 1,
            'item_pedido_valor' => 10,
            'item_pedido_status' => 'INSERIDO',
            'item_pedido_venda_id' => $venda->id,
        ]);

        $this->expectException(PedidoNaoPodeSerExcluidoException::class);

        $this->service->excluir($pedido, $this->gerente, MotivoExclusaoPedidoEnum::DUPLICADO);
    }

    public function test_recusa_pedido_com_cobranca_stone_pendente(): void
    {
        $pedido = $this->pedido('ABERTO');
        StonePedido::create([
            'stp_pedido_id' => $pedido->id,
            'stp_status' => StonePedidoStatus::Aguardando->value,
            'stp_valor_solicitado' => 30,
        ]);

        $this->expectException(PedidoNaoPodeSerExcluidoException::class);

        $this->service->excluir($pedido, $this->gerente, MotivoExclusaoPedidoEnum::DUPLICADO);
    }

    public function test_motivo_outro_exige_observacao_com_dez_caracteres(): void
    {
        $pedido = $this->pedido('CANCELADO');

        $this->expectException(PedidoNaoPodeSerExcluidoException::class);

        try {
            $this->service->excluir($pedido, $this->gerente, MotivoExclusaoPedidoEnum::OUTRO, 'curto');
        } finally {
            $this->assertNotNull(Pedido::find($pedido->id));
        }
    }

    public function test_atendente_nao_exclui(): void
    {
        $pedido = $this->pedido('CANCELADO');

        $this->expectException(AuthorizationException::class);

        $this->service->excluir($pedido, User::factory()->atendente()->create(['name_first' => 'Atendente']), MotivoExclusaoPedidoEnum::DUPLICADO);
    }

    public function test_restaurar_devolve_o_pedido_cancelado_e_limpa_os_campos(): void
    {
        $pedido = $this->pedido('ABERTO');
        $this->service->excluir($pedido, $this->gerente, MotivoExclusaoPedidoEnum::DUPLICADO, 'obs');

        $restaurado = $this->service->restaurar(Pedido::onlyTrashed()->findOrFail($pedido->id), $this->gerente);

        $this->assertFalse($restaurado->trashed());
        $fresco = Pedido::findOrFail($pedido->id);
        $this->assertSame('CANCELADO', $fresco->pedido_status);
        $this->assertNull($fresco->pedido_motivo_exclusao);
        $this->assertNull($fresco->pedido_exclusao_observacao);
        $this->assertNull($fresco->pedido_usuario_excluiu_id);
    }
}
