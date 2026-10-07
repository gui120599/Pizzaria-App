<?php

namespace Tests\Feature;

use App\Enums\MotivoAprovacaoMesaEnum;
use App\Enums\PedidoOrigemEnum;
use App\Enums\StatusAprovacaoPedidoEnum;
use App\Models\MesaParticipante;
use App\Models\Pedido;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Colunas do pedido feito pelo cliente no QR: quem pediu, a aprovação do
 * garçom com os motivos e a chave que impede a rodada em dobro.
 */
class PedidoMesaQrTest extends TestCase
{
    use RefreshDatabase;

    public function test_pedido_pendente_guarda_participante_e_motivos_da_aprovacao(): void
    {
        $participante = MesaParticipante::factory()->create();

        $pedido = Pedido::create([
            'pedido_status' => 'INICIADO',
            'pedido_origem' => PedidoOrigemEnum::MESA_QR,
            'pedido_sessao_mesa_id' => $participante->mp_sessao_mesa_id,
            'pedido_mesa_participante_id' => $participante->id,
            'pedido_aprovacao_status' => StatusAprovacaoPedidoEnum::PENDENTE,
            'pedido_aprovacao_motivos' => [MotivoAprovacaoMesaEnum::PRIMEIRO_PEDIDO, MotivoAprovacaoMesaEnum::QUANTIDADE_ALTA],
        ])->fresh();

        $this->assertTrue($pedido->aguardandoAprovacao());
        $this->assertTrue($pedido->participante->is($participante));
        $this->assertSame(
            [MotivoAprovacaoMesaEnum::PRIMEIRO_PEDIDO, MotivoAprovacaoMesaEnum::QUANTIDADE_ALTA],
            $pedido->pedido_aprovacao_motivos->all(),
        );
    }

    public function test_a_mesma_chave_de_idempotencia_nao_grava_dois_pedidos(): void
    {
        $chave = (string) Str::uuid();
        Pedido::create(['pedido_status' => 'INICIADO', 'pedido_chave_idempotencia' => $chave]);

        $this->expectException(QueryException::class);

        Pedido::create(['pedido_status' => 'INICIADO', 'pedido_chave_idempotencia' => $chave]);
    }
}
