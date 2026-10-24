<?php

namespace Tests\Feature;

use App\Enums\StatusInfluenciadorEnum;
use App\Enums\TipoMovimentoCarteiraEnum;
use App\Exceptions\CarteiraInfluenciadorException;
use App\Models\CarteiraMovimento;
use App\Models\Influenciador;
use App\Models\User;
use App\Services\CarteiraInfluenciadorService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/** F2.2: extrato append-only, saldo = soma dos movimentos, débito com trava e ajuste auditado. */
class CarteiraInfluenciadorServiceTest extends TestCase
{
    use RefreshDatabase;

    private CarteiraInfluenciadorService $carteira;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->carteira = app(CarteiraInfluenciadorService::class);
    }

    private function influenciador(): Influenciador
    {
        $influenciador = Influenciador::create([
            'influenciador_nome' => 'Fulano',
            'email' => uniqid().'@teste.com',
            'password' => 'senha-segura',
        ]);
        $influenciador->forceFill(['influenciador_status' => StatusInfluenciadorEnum::ATIVO])->save();

        return $influenciador;
    }

    public function test_saldo_e_a_soma_do_extrato(): void
    {
        $influenciador = $this->influenciador();

        $this->carteira->lancar($influenciador, TipoMovimentoCarteiraEnum::CREDITO_COMISSAO, 30.10);
        $this->carteira->lancar($influenciador, TipoMovimentoCarteiraEnum::CREDITO_COMISSAO, 19.90);
        $this->carteira->debitar($influenciador, TipoMovimentoCarteiraEnum::CONSUMO, 12.50);

        $this->assertSame(37.50, $this->carteira->saldo($influenciador));
        $this->assertSame(37.50, $influenciador->saldoCarteira());
        $this->assertSame(3, $influenciador->movimentosCarteira()->count());
    }

    public function test_debito_acima_do_saldo_e_recusado_e_nao_grava_nada(): void
    {
        $influenciador = $this->influenciador();
        $this->carteira->lancar($influenciador, TipoMovimentoCarteiraEnum::CREDITO_COMISSAO, 50);

        // Dois débitos seguidos que juntos passam do saldo: o segundo cai na conferência com trava.
        $this->carteira->debitar($influenciador, TipoMovimentoCarteiraEnum::CONSUMO, 30);

        try {
            $this->carteira->debitar($influenciador, TipoMovimentoCarteiraEnum::CONSUMO, 30);
            $this->fail('O segundo débito deveria ser recusado por saldo insuficiente.');
        } catch (CarteiraInfluenciadorException $e) {
            $this->assertStringContainsString('Saldo insuficiente', $e->getMessage());
        }

        $this->assertSame(20.0, $this->carteira->saldo($influenciador));
        $this->assertSame(2, CarteiraMovimento::count());
    }

    public function test_saldo_negativo_por_estorno_bloqueia_debitos(): void
    {
        $influenciador = $this->influenciador();
        $this->carteira->lancar($influenciador, TipoMovimentoCarteiraEnum::CREDITO_COMISSAO, 10);
        $this->carteira->debitar($influenciador, TipoMovimentoCarteiraEnum::CONSUMO, 10);

        // Estorno da comissão depois de gasta: a carteira fica negativa (permitido).
        $this->carteira->lancar($influenciador, TipoMovimentoCarteiraEnum::ESTORNO_COMISSAO, -10);
        $this->assertSame(-10.0, $this->carteira->saldo($influenciador));

        $this->expectException(CarteiraInfluenciadorException::class);
        $this->carteira->debitar($influenciador, TipoMovimentoCarteiraEnum::RESGATE_PIX, 1);
    }

    public function test_extrato_nao_pode_ser_editado_nem_apagado(): void
    {
        $influenciador = $this->influenciador();
        $movimento = $this->carteira->lancar($influenciador, TipoMovimentoCarteiraEnum::CREDITO_COMISSAO, 10);

        try {
            $movimento->update(['cm_valor' => 999]);
            $this->fail('Editar o extrato deveria falhar.');
        } catch (LogicException) {
        }

        try {
            $movimento->delete();
            $this->fail('Apagar o extrato deveria falhar.');
        } catch (LogicException) {
        }

        $this->assertSame(10.0, $this->carteira->saldo($influenciador));
    }

    public function test_ajuste_exige_permissao_e_observacao(): void
    {
        $influenciador = $this->influenciador();
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);
        $caixa = User::factory()->caixa()->create(['name_first' => 'Caixa']);

        $this->carteira->ajustar($influenciador, 25, 'Bônus de campanha avulsa', $gerente);
        $this->assertSame(25.0, $this->carteira->saldo($influenciador));
        $this->assertSame($gerente->id, CarteiraMovimento::sole()->cm_user_id);

        foreach ([
            fn () => $this->carteira->ajustar($influenciador, 10, 'Sem permissão aqui', $caixa),
            fn () => $this->carteira->ajustar($influenciador, 10, 'abc', $gerente),
            fn () => $this->carteira->ajustar($influenciador, 0, 'Valor zerado não vale', $gerente),
            fn () => $this->carteira->ajustar($influenciador, -100, 'Deixaria negativa a carteira', $gerente),
        ] as $tentativa) {
            try {
                $tentativa();
                $this->fail('O ajuste deveria ser recusado.');
            } catch (CarteiraInfluenciadorException) {
            }
        }

        $this->assertSame(25.0, $this->carteira->saldo($influenciador));
    }

    public function test_exclusao_do_influenciador_e_barrada_com_saldo(): void
    {
        $influenciador = $this->influenciador();
        $this->carteira->lancar($influenciador, TipoMovimentoCarteiraEnum::CREDITO_COMISSAO, 10);

        $this->assertStringContainsString('saldo', (string) $influenciador->motivoBloqueioExclusao());

        $this->carteira->debitar($influenciador, TipoMovimentoCarteiraEnum::CONSUMO, 10);
        $this->assertNull($influenciador->fresh()->motivoBloqueioExclusao());
    }
}
