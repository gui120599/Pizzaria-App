<?php

namespace Tests\Feature;

use App\Enums\StatusChamadoMesaEnum;
use App\Enums\TipoChamadoMesaEnum;
use App\Models\Mesa;
use App\Models\MesaChamado;
use App\Models\MesaParticipante;
use App\Models\User;
use App\Services\MesaCliente\MesaChamadoService;
use App\Services\MesaCliente\ParticipanteMesaService;
use App\Services\SessaoMesaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Chamados do QR para o garçom: chamar, pedir a conta e pedir para abrir.
 */
class MesaChamadoServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_chamar_de_novo_nao_enfileira_outro_chamado(): void
    {
        $participante = $this->participante();
        $mesa = $participante->sessaoMesa->mesa;

        $primeiro = $this->servico()->abrir($mesa, TipoChamadoMesaEnum::CHAMAR_GARCOM, $participante);
        $segundo = $this->servico()->abrir($mesa, TipoChamadoMesaEnum::CHAMAR_GARCOM, $participante);

        $this->assertSame($primeiro->id, $segundo->id);
        $this->assertSame(1, MesaChamado::count());
    }

    public function test_pedir_a_conta_marca_a_conta_como_solicitada(): void
    {
        $participante = $this->participante();

        $this->servico()->abrir($participante->sessaoMesa->mesa, TipoChamadoMesaEnum::PEDIR_CONTA, $participante);

        $this->assertTrue($participante->sessaoMesa->fresh()->contaSolicitada());
    }

    public function test_pedir_para_abrir_so_vale_com_a_mesa_fechada(): void
    {
        $participante = $this->participante();

        $this->expectExceptionObject(new RuntimeException('Esta mesa já está aberta.'));

        $this->servico()->abrir($participante->sessaoMesa->mesa, TipoChamadoMesaEnum::ABRIR_MESA, ip: '10.0.0.1');
    }

    public function test_abrir_a_mesa_atende_o_pedido_de_abertura(): void
    {
        $mesa = Mesa::factory()->create();
        $garcom = $this->garcom();
        $chamado = $this->servico()->abrir($mesa, TipoChamadoMesaEnum::ABRIR_MESA, ip: '10.0.0.1');

        app(SessaoMesaService::class)->abrir($mesa->id, $garcom->id);

        $chamado->refresh();
        $this->assertSame(StatusChamadoMesaEnum::ATENDIDO, $chamado->mc_status);
        $this->assertSame($garcom->id, $chamado->mc_atendido_por_id);
    }

    public function test_chamar_o_garcom_sem_conta_aberta_e_recusado(): void
    {
        $this->expectExceptionObject(new RuntimeException('A conta desta mesa não está aberta.'));

        $this->servico()->abrir(Mesa::factory()->create(), TipoChamadoMesaEnum::CHAMAR_GARCOM);
    }

    public function test_atender_registra_quem_atendeu_e_nao_atende_duas_vezes(): void
    {
        $participante = $this->participante();
        $chamado = $this->servico()->abrir($participante->sessaoMesa->mesa, TipoChamadoMesaEnum::CHAMAR_GARCOM, $participante);
        $garcom = $this->garcom();

        $this->assertTrue($this->servico()->atender($chamado, $garcom));
        $this->assertFalse($this->servico()->atender($chamado, $this->garcom()));

        $chamado->refresh();
        $this->assertSame(StatusChamadoMesaEnum::ATENDIDO, $chamado->mc_status);
        $this->assertSame($garcom->id, $chamado->mc_atendido_por_id);
    }

    private function servico(): MesaChamadoService
    {
        return app(MesaChamadoService::class);
    }

    private function garcom(): User
    {
        return User::factory()->create(['name_first' => 'Garçom']);
    }

    private function participante(): MesaParticipante
    {
        $mesa = Mesa::factory()->create();
        app(SessaoMesaService::class)->abrir($mesa->id, $this->garcom()->id);

        return app(ParticipanteMesaService::class)->entrar($mesa->fresh(), 'Ana', '64999991234')['participante'];
    }
}
