<?php

namespace Tests\Feature;

use App\Enums\AcaoAutorizadaEnum;
use App\Exceptions\AutorizacaoNegadaException;
use App\Models\AutorizacaoGerente;
use App\Models\Mesa;
use App\Models\User;
use App\Services\Garcom\AutorizacaoGerenteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutorizacaoGerenteServiceTest extends TestCase
{
    use RefreshDatabase;

    private AutorizacaoGerenteService $service;

    private Mesa $mesa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(AutorizacaoGerenteService::class);
        $this->mesa = Mesa::create(['mesa_nome' => 'Mesa 1', 'mesa_status' => 'LIBERADA']);
    }

    public function test_gerente_se_autoriza_sem_pin(): void
    {
        $gerente = User::factory()->gerente()->create(['name_first' => 'Teste']);

        $autorizacao = $this->service->autorizar(AcaoAutorizadaEnum::CANCELAR_ITEM, $gerente, $this->mesa, motivo: 'Erro');

        $this->assertSame($gerente->id, $autorizacao->autorizacao_autorizador_id);
        $this->assertTrue($autorizacao->auditavel->is($this->mesa));
    }

    public function test_garcom_precisa_do_pin_de_um_gerente(): void
    {
        $garcom = User::factory()->garcom()->create(['name_first' => 'Teste']);
        $gerente = User::factory()->gerente()->comPin('4321')->create(['name_first' => 'Teste']);

        $autorizacao = $this->service->autorizar(
            AcaoAutorizadaEnum::REMOVER_TAXA_SERVICO,
            $garcom,
            $this->mesa,
            $gerente->id,
            '4321',
            'Cliente reclamou',
            ['percentual_anterior' => 10.0],
        );

        $this->assertDatabaseHas(AutorizacaoGerente::class, [
            'id' => $autorizacao->id,
            'autorizacao_solicitante_id' => $garcom->id,
            'autorizacao_autorizador_id' => $gerente->id,
            'autorizacao_motivo' => 'Cliente reclamou',
        ]);
        $this->assertSame(['percentual_anterior' => 10], $autorizacao->fresh()->autorizacao_dados);
    }

    public function test_pin_errado_e_negado_e_nada_e_gravado(): void
    {
        $garcom = User::factory()->garcom()->create(['name_first' => 'Teste']);
        $gerente = User::factory()->gerente()->comPin('4321')->create(['name_first' => 'Teste']);

        $this->expectException(AutorizacaoNegadaException::class);

        try {
            $this->service->autorizar(AcaoAutorizadaEnum::CANCELAR_ITEM, $garcom, $this->mesa, $gerente->id, '0000');
        } finally {
            $this->assertDatabaseCount(AutorizacaoGerente::class, 0);
        }
    }

    public function test_pin_de_quem_nao_tem_a_permissao_nao_autoriza(): void
    {
        $garcom = User::factory()->garcom()->create(['name_first' => 'Teste']);
        $colega = User::factory()->garcom()->comPin('1111')->create(['name_first' => 'Teste']);

        $this->expectExceptionMessage('não pode autorizar');

        $this->service->autorizar(AcaoAutorizadaEnum::CANCELAR_ITEM, $garcom, $this->mesa, $colega->id, '1111');
    }

    public function test_pin_bloqueia_apos_tentativas_erradas(): void
    {
        config(['pizzaria.salao.pin_tentativas' => 2]);
        $garcom = User::factory()->garcom()->create(['name_first' => 'Teste']);
        $gerente = User::factory()->gerente()->comPin('4321')->create(['name_first' => 'Teste']);

        foreach (['0000', '1111'] as $pinErrado) {
            try {
                $this->service->autorizar(AcaoAutorizadaEnum::CANCELAR_ITEM, $garcom, $this->mesa, $gerente->id, $pinErrado);
            } catch (AutorizacaoNegadaException) {
            }
        }

        $this->expectExceptionMessage('bloqueado');

        $this->service->autorizar(AcaoAutorizadaEnum::CANCELAR_ITEM, $garcom, $this->mesa, $gerente->id, '4321');
    }
}
