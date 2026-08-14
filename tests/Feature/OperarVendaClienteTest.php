<?php

namespace Tests\Feature;

use App\Filament\Pages\OperarVenda;
use App\Models\Caixa;
use App\Models\Cliente;
use App\Models\SessaoCaixa;
use App\Models\User;
use App\Models\Venda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OperarVendaClienteTest extends TestCase
{
    use RefreshDatabase;

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

        $this->venda = Venda::create([
            'venda_status' => 'INICIADA',
            'venda_sessao_caixa_id' => $sessaoCaixa->id,
        ]);
    }

    public function test_busca_cliente_por_nome(): void
    {
        Cliente::create(['cliente_nome' => 'Maria Silva', 'cliente_tipo' => 'Física']);
        Cliente::create(['cliente_nome' => 'João Souza', 'cliente_tipo' => 'Física']);

        $component = Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->set('buscaCliente', 'Maria');

        $nomes = $component->instance()->clientesEncontrados->pluck('cliente_nome')->all();
        $this->assertSame(['Maria Silva'], $nomes);
    }

    public function test_busca_cliente_por_telefone(): void
    {
        Cliente::create(['cliente_nome' => 'Maria Silva', 'cliente_tipo' => 'Física', 'cliente_celular' => '11987654321']);
        Cliente::create(['cliente_nome' => 'João Souza', 'cliente_tipo' => 'Física', 'cliente_celular' => '11912340000']);

        $component = Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->set('buscaCliente', '(11) 98765-4321');

        $nomes = $component->instance()->clientesEncontrados->pluck('cliente_nome')->all();
        $this->assertSame(['Maria Silva'], $nomes);
    }

    public function test_selecionar_cliente_cria_venda_lazy_quando_ainda_nao_existe(): void
    {
        $cliente = Cliente::create(['cliente_nome' => 'Maria Silva', 'cliente_tipo' => 'Física']);

        $component = Livewire::test(OperarVenda::class)->assertSet('vendaId', null);
        $component->call('selecionarCliente', $cliente->id);

        $novaVendaId = $component->get('vendaId');
        $this->assertNotNull($novaVendaId);
        $this->assertNotSame($this->venda->id, $novaVendaId);
        $this->assertSame($cliente->id, Venda::find($novaVendaId)->venda_cliente_id);
    }

    public function test_salvar_cliente_ad_hoc_cria_venda_lazy_quando_ainda_nao_existe(): void
    {
        $component = Livewire::test(OperarVenda::class)->assertSet('vendaId', null);
        $component->set('clienteAdHocNome', 'Cliente Balcão')
            ->set('clienteAdHocCpf', '321.654.987-00')
            ->call('salvarClienteAdHoc');

        $novaVendaId = $component->get('vendaId');
        $this->assertNotNull($novaVendaId);
        $this->assertNotSame($this->venda->id, $novaVendaId);
        $this->assertNotNull(Venda::find($novaVendaId)->venda_cliente_id);
    }

    public function test_selecionar_cliente_vincula_a_venda(): void
    {
        $cliente = Cliente::create(['cliente_nome' => 'Maria Silva', 'cliente_tipo' => 'Física']);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->call('selecionarCliente', $cliente->id)
            ->assertSet('modalClienteAberta', false);

        $this->assertSame($cliente->id, $this->venda->fresh()->venda_cliente_id);
    }

    public function test_remover_cliente_da_venda(): void
    {
        $cliente = Cliente::create(['cliente_nome' => 'Maria Silva', 'cliente_tipo' => 'Física']);
        $this->venda->update(['venda_cliente_id' => $cliente->id]);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->call('removerClienteDaVenda');

        $this->assertNull($this->venda->fresh()->venda_cliente_id);
    }

    public function test_cadastrar_cliente_ad_hoc_por_cpf_vincula_a_venda(): void
    {
        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->set('clienteAdHocNome', 'Cliente Balcão')
            ->set('clienteAdHocCpf', '123.456.789-00')
            ->call('salvarClienteAdHoc')
            ->assertSet('modalClienteAberta', false)
            ->assertHasNoErrors();

        $vendaFresh = $this->venda->fresh();
        $this->assertNotNull($vendaFresh->venda_cliente_id);

        $cliente = Cliente::find($vendaFresh->venda_cliente_id);
        $this->assertSame('12345678900', $cliente->cliente_cpf);
    }

    public function test_cadastrar_cliente_ad_hoc_reaproveita_cliente_existente_pelo_cpf(): void
    {
        $existente = Cliente::create(['cliente_nome' => 'Já Cadastrado', 'cliente_cpf' => '11122233344', 'cliente_tipo' => 'Física']);

        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->set('clienteAdHocCpf', '111.222.333-44')
            ->call('salvarClienteAdHoc');

        $this->assertSame($existente->id, $this->venda->fresh()->venda_cliente_id);
        $this->assertSame(1, Cliente::count());
    }

    public function test_cadastrar_cliente_ad_hoc_com_email_invalido_mantem_modal_aberto_com_erro(): void
    {
        Livewire::test(OperarVenda::class, ['venda' => $this->venda])
            ->set('clienteAdHocCpf', '999.888.777-66')
            ->set('clienteAdHocEmail', 'nao-e-um-email')
            ->call('salvarClienteAdHoc')
            ->assertHasErrors(['cliente_email']);

        $this->assertNull($this->venda->fresh()->venda_cliente_id);
    }
}
