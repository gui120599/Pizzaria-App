<?php

namespace Tests\Feature;

use App\Filament\Pages\OperarVenda;
use App\Models\Caixa;
use App\Models\Empresa;
use App\Models\NfEmissao;
use App\Models\NfNumeracao;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosVenda;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Models\Venda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Regra de envio automático da NFC-e por forma de pagamento no PDV
 * (OperarVenda): o checkbox vem pré-marcado se algum pagamento usa uma forma
 * com opcaopag_envio_automatico_nfe, e o operador pode sobrescrever.
 */
class NfeEnvioAutomaticoTest extends TestCase
{
    use RefreshDatabase;

    private const URL_EMISSAO = 'api.nfse.io/v2/companies/company-teste/consumerinvoices';

    private Venda $venda;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->admin()->create(['name_first' => 'Admin']);
        $this->actingAs($user);

        $caixa = Caixa::create(['caixa_nome' => 'Caixa 1']);
        $sessaoCaixa = SessaoCaixa::create([
            'sessaocaixa_caixa_id' => $caixa->id,
            'sessaocaixa_status' => 'ABERTA',
            'sessaocaixa_data_hora_abertura' => now(),
            'sessaocaixa_saldo_inicial' => 0,
            'sessaocaixa_user_id' => $user->id,
        ]);

        Empresa::create([
            'empresa_razao_social' => 'Pizzaria Teste LTDA',
            'empresa_cnpj' => '11222333000181',
            'empresa_api_nfeio_company_id' => 'company-teste',
            'empresa_api_nfeio_apikey' => 'chave-teste',
        ]);

        $this->venda = Venda::create([
            'venda_status' => 'INICIADA',
            'venda_sessao_caixa_id' => $sessaoCaixa->id,
            'venda_valor_total' => 0,
            'venda_valor_pago' => 0,
            'venda_valor_troco' => 0,
        ]);
    }

    private function cartaoStone(): OpcoesPagamento
    {
        return OpcoesPagamento::create(['opcaopag_nome' => 'Cartão Crédito Stone', 'opcaopag_desc_nfe' => 'creditCard', 'opcaopag_envio_automatico_nfe' => false]);
    }

    private function pix(): OpcoesPagamento
    {
        return OpcoesPagamento::create(['opcaopag_nome' => 'Pix', 'opcaopag_desc_nfe' => 'InstantPayment', 'opcaopag_envio_automatico_nfe' => true]);
    }

    private function pagar(OpcoesPagamento $opcao, float $valor): PagamentosVenda
    {
        $this->venda->increment('venda_valor_total', $valor);
        $this->venda->increment('venda_valor_pago', $valor);

        return PagamentosVenda::create([
            'pg_venda_venda_id' => $this->venda->id,
            'pg_venda_opcaopagamento_id' => $opcao->id,
            'pg_venda_valor_pagamento' => $valor,
            'pg_venda_valor_pago_pelo_cliente' => $valor,
        ]);
    }

    public function test_venda_so_com_cartao_stone_nao_envia_nem_consome_numero(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $this->pagar($this->cartaoStone(), 50);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->call('finalizarVenda')
            ->assertRedirect(OperarVenda::getUrl());

        Http::assertNothingSent();
        $this->assertSame(0, NfEmissao::count());
        $this->assertSame(0, NfNumeracao::count());
    }

    public function test_venda_com_pix_envia_automaticamente_com_numero_da_sequencia(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::URL_EMISSAO => Http::response(['id' => 'inv-1', 'status' => 'Processing'])]);
        $this->pagar($this->pix(), 30);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->call('finalizarVenda')
            ->assertNoRedirect()
            ->assertSet('modalNfeAberta', true);

        Http::assertSent(fn ($request) => $request['number'] === 1 && $request['serie'] === 1);
        $this->assertSame(NfEmissao::DECISAO_AUTOMATICA, NfEmissao::sole()->decisao_envio);
    }

    public function test_venda_mista_envia_com_todos_os_pagamentos(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::URL_EMISSAO => Http::response(['id' => 'inv-1', 'status' => 'Processing'])]);
        $this->pagar($this->cartaoStone(), 50);
        $this->pagar($this->pix(), 30);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->call('finalizarVenda');

        Http::assertSent(fn ($request) => array_column($request['payment'][0]['paymentDetail'], 'method') === ['creditCard', 'InstantPayment']);
    }

    public function test_pix_removido_antes_de_finalizar_nao_envia(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $pagamentoPix = $this->pagar($this->pix(), 30);
        $this->pagar($this->cartaoStone(), 50);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->call('removerPagamento', $pagamentoPix->id)
            ->call('finalizarVenda')
            ->assertRedirect(OperarVenda::getUrl());

        Http::assertNothingSent();
    }

    public function test_operador_sem_permissao_de_emitir_nfe_tambem_dispara_o_envio_automatico(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::URL_EMISSAO => Http::response(['id' => 'inv-1', 'status' => 'Processing'])]);
        $operador = User::factory()->atendente()->create(['name_first' => 'Atendente']);
        $operador->givePermissionTo(Permission::firstOrCreate(['name' => 'operar:venda', 'guard_name' => 'web']));
        SessaoCaixa::query()->update(['sessaocaixa_user_id' => $operador->id]);
        $this->actingAs($operador);
        $this->pagar($this->pix(), 30);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->call('finalizarVenda')
            ->assertSet('modalNfeAberta', true);

        $this->assertFalse($operador->can('emitir:nfe'));
        Http::assertSentCount(1);
    }
}
