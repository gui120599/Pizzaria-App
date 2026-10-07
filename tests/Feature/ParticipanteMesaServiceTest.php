<?php

namespace Tests\Feature;

use App\Exceptions\AcessoMesaNegadoException;
use App\Models\Cliente;
use App\Models\Mesa;
use App\Models\SessaoMesaCliente;
use App\Models\User;
use App\Services\MesaCliente\ParticipanteMesaService;
use App\Services\SessaoMesaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Identificação do celular que lê o QR: entra só em conta aberta e o token
 * vale só para esta conta, nesta mesa.
 */
class ParticipanteMesaServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_entrar_cadastra_o_cliente_poe_na_mesa_e_o_token_reconhece_o_celular(): void
    {
        $mesa = $this->mesaAberta();

        ['participante' => $participante, 'token' => $token] = $this->servico()->entrar($mesa, 'Ana', '(64) 99999-1234', '10.0.0.1', 'Safari');

        $cliente = Cliente::where('cliente_celular', '64999991234')->sole();
        $this->assertSame($cliente->id, $participante->mp_cliente_id);
        $this->assertTrue(SessaoMesaCliente::where('smc_cliente_id', $cliente->id)->where('smc_sessao_mesa_id', $mesa->mesa_sessao_atual_id)->exists());
        $this->assertTrue($participante->is($this->servico()->resolver($mesa, $token)));
    }

    public function test_entrar_com_a_mesa_fechada_e_recusado(): void
    {
        $mesa = Mesa::factory()->create();

        $this->expectExceptionObject(new RuntimeException('Esta mesa ainda não está aberta. Chame o garçom.'));

        $this->servico()->entrar($mesa, 'Ana', '64999991234');
    }

    public function test_entrar_com_o_pedido_pelo_celular_desligado_na_mesa_e_recusado(): void
    {
        $mesa = $this->mesaAberta();
        $mesa->update(['mesa_pedido_cliente_ativo' => false]);

        $this->expectExceptionObject(new RuntimeException('O pedido pelo celular está desligado nesta mesa. Chame o garçom.'));

        $this->servico()->entrar($mesa, 'Ana', '64999991234');
    }

    public function test_entrar_sem_celular_com_ddd_e_recusado(): void
    {
        $this->expectExceptionObject(new RuntimeException('Informe o celular com DDD.'));

        $this->servico()->entrar($this->mesaAberta(), 'Ana', '99999-1234');
    }

    public function test_token_de_conta_fechada_nao_entra_mais(): void
    {
        $mesa = $this->mesaAberta();
        ['token' => $token] = $this->servico()->entrar($mesa, 'Ana', '64999991234');
        app(SessaoMesaService::class)->fechar($mesa->sessaoAtual);

        $erro = $this->acessoNegado(fn () => $this->servico()->resolver($mesa->fresh(), $token));

        $this->assertSame(AcessoMesaNegadoException::CONTA_ENCERRADA, $erro->motivo);
    }

    public function test_foto_antiga_do_qr_numa_conta_nova_pede_identificacao_de_novo(): void
    {
        $mesa = $this->mesaAberta();
        ['token' => $tokenAntigo] = $this->servico()->entrar($mesa, 'Ana', '64999991234');
        app(SessaoMesaService::class)->fechar($mesa->sessaoAtual);
        $mesa = $this->abrir($mesa->fresh());

        $erro = $this->acessoNegado(fn () => $this->servico()->resolver($mesa, $tokenAntigo));

        $this->assertSame(AcessoMesaNegadoException::CONTA_ENCERRADA, $erro->motivo);
    }

    public function test_conta_transferida_aponta_o_codigo_da_mesa_nova(): void
    {
        $mesa = $this->mesaAberta();
        ['token' => $token] = $this->servico()->entrar($mesa, 'Ana', '64999991234');
        $nova = Mesa::factory()->create();
        app(SessaoMesaService::class)->trocarMesa($mesa->sessaoAtual, $nova->id);

        $erro = $this->acessoNegado(fn () => $this->servico()->resolver($mesa->fresh(), $token));

        $this->assertSame(AcessoMesaNegadoException::MESA_TROCADA, $erro->motivo);
        $this->assertSame($nova->mesa_codigo_qr, $erro->codigoMesaAtual);
    }

    public function test_celular_bloqueado_pelo_garcom_nao_entra(): void
    {
        $mesa = $this->mesaAberta();
        ['participante' => $participante, 'token' => $token] = $this->servico()->entrar($mesa, 'Ana', '64999991234');
        $this->servico()->bloquear($participante, $this->garcom());

        $erro = $this->acessoNegado(fn () => $this->servico()->resolver($mesa, $token));

        $this->assertSame(AcessoMesaNegadoException::BLOQUEADO, $erro->motivo);
    }

    public function test_sem_token_ou_com_token_desconhecido_pede_identificacao(): void
    {
        $mesa = $this->mesaAberta();

        $this->assertSame(AcessoMesaNegadoException::SEM_IDENTIFICACAO, $this->acessoNegado(fn () => $this->servico()->resolver($mesa, null))->motivo);
        $this->assertSame(AcessoMesaNegadoException::SEM_IDENTIFICACAO, $this->acessoNegado(fn () => $this->servico()->resolver($mesa, 'inventado'))->motivo);
    }

    private function servico(): ParticipanteMesaService
    {
        return app(ParticipanteMesaService::class);
    }

    private function garcom(): User
    {
        return User::factory()->create(['name_first' => 'Garçom']);
    }

    private function mesaAberta(): Mesa
    {
        return $this->abrir(Mesa::factory()->create());
    }

    private function abrir(Mesa $mesa): Mesa
    {
        app(SessaoMesaService::class)->abrir($mesa->id, $this->garcom()->id, pessoas: 2);

        return $mesa->fresh();
    }

    private function acessoNegado(callable $acao): AcessoMesaNegadoException
    {
        try {
            $acao();
        } catch (AcessoMesaNegadoException $e) {
            return $e;
        }

        $this->fail('Era esperado AcessoMesaNegadoException.');
    }
}
