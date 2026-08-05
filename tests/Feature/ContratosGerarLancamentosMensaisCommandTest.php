<?php

namespace Tests\Feature;

use App\Enums\StatusContrato;
use App\Models\Contrato;
use App\Models\Lancamento;
use App\Models\PlanoDespesa;
use App\Models\Prestador;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContratosGerarLancamentosMensaisCommandTest extends TestCase
{
    use RefreshDatabase;

    private function contrato(array $attrs = []): Contrato
    {
        $fornecedor = Prestador::create([
            'tipo' => 'pj',
            'categoria' => 'fornecedor',
            'razao_social' => 'Fornecedor Teste',
            'nome' => 'Fornecedor Teste',
            'cpf_cnpj' => '11222333000144',
        ]);

        $plano = PlanoDespesa::create([
            'nome' => 'Software',
            'comportamento' => 'fixo',
        ]);

        return Contrato::create(array_merge([
            'favorecido_id' => $fornecedor->id,
            'plano_despesa_id' => $plano->id,
            'descricao' => 'Assinatura mensal',
            'valor' => 300.00,
            'dia_vencimento' => 5,
            'data_inicio' => now()->subMonth()->startOfMonth(),
            'status' => StatusContrato::Ativo,
        ], $attrs));
    }

    public function test_gera_lancamento_para_contratos_ativos_vigentes(): void
    {
        $ativo = $this->contrato();
        $suspenso = $this->contrato(['status' => StatusContrato::Suspenso]);

        $this->artisan('contratos:gerar-lancamentos')->assertSuccessful();

        $this->assertSame(1, Lancamento::where('contrato_id', $ativo->id)->count());
        $this->assertSame(0, Lancamento::where('contrato_id', $suspenso->id)->count());
    }

    public function test_rodar_o_comando_duas_vezes_nao_duplica(): void
    {
        $contrato = $this->contrato();

        $this->artisan('contratos:gerar-lancamentos')->assertSuccessful();
        $this->artisan('contratos:gerar-lancamentos')->assertSuccessful();

        $this->assertSame(1, Lancamento::where('contrato_id', $contrato->id)->count());
    }

    public function test_encerra_automaticamente_contrato_com_vigencia_vencida_e_nao_gera_lancamento(): void
    {
        $contrato = $this->contrato(['data_fim' => now()->subDay()]);

        $this->artisan('contratos:gerar-lancamentos')->assertSuccessful();

        $this->assertSame(StatusContrato::Encerrado, $contrato->fresh()->status);
        $this->assertSame(0, Lancamento::where('contrato_id', $contrato->id)->count());
    }
}
