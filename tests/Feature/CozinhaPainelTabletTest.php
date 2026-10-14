<?php

namespace Tests\Feature;

use App\Enums\Checklist\ChecklistSetor;
use App\Enums\Cozinha\StatusItemProducao;
use App\Enums\StatusPedidoEnum;
use App\Filament\Cozinha\Pages\ChecklistsCozinha;
use App\Filament\Cozinha\Pages\ConsultaFichas;
use App\Filament\Cozinha\Pages\Estacao;
use App\Filament\Cozinha\Pages\ExecutarChecklistCozinha;
use App\Filament\Cozinha\Pages\Expedicao;
use App\Http\Middleware\IdentificaDispositivoCozinha;
use App\Models\ChecklistModelo;
use App\Models\DispositivoCozinha;
use App\Models\FichaTecnica;
use App\Models\Pedido;
use App\Models\ProducaoItem;
use App\Models\SetorProducao;
use App\Models\User;
use App\Services\Checklist\ChecklistExecucaoService;
use App\Services\Cozinha\OperadorCozinhaService;
use App\Services\Cozinha\PainelCozinhaService;
use App\Services\Cozinha\PareamentoDispositivoService;
use App\Services\Cozinha\ProducaoService;
use Database\Seeders\PermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CenarioCozinha;
use Tests\TestCase;

/** Fase 4: painel /cozinha — pareamento, operador, Estação, Expedição, fichas e checklists. */
class CozinhaPainelTabletTest extends TestCase
{
    use CenarioCozinha, RefreshDatabase;

    private SetorProducao $pizzaria;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->travelTo('2026-10-14 19:00:00');
        $this->pizzaria = SetorProducao::factory()->create(['nome' => 'Pizzaria']);
        Filament::setCurrentPanel('cozinha');
    }

    /** Tablet pareado à Pizzaria, logado na conta da estação. */
    private function tabletLogado(): DispositivoCozinha
    {
        $tablet = DispositivoCozinha::factory()->paraSetores($this->pizzaria)->create(['nome' => 'Forno']);
        $pareamento = app(PareamentoDispositivoService::class);
        $tablet = $pareamento->parear($pareamento->gerarToken($tablet));
        $this->actingAs($tablet->usuario);
        app()->instance(DispositivoCozinha::ATUAL, $tablet);

        return $tablet;
    }

    private function cozinheira(string $nome = 'Ana'): User
    {
        return User::factory()->cozinha()->create(['name_first' => $nome]);
    }

    /** 2 pizzas de mesa na fila da Pizzaria. */
    private function pedidoNaFila(): Pedido
    {
        $pizzas = $this->categoria('Pizza Grande');
        $this->rotaCategoria($pizzas, $this->pizzaria);
        $garcom = User::factory()->garcom()->create(['name_first' => 'Beto']);
        $pedido = $this->pedidoMesa($garcom);
        $this->item($pedido, $this->produto('Calabresa', $pizzas), 2, ['item_pedido_observacao' => 'Sem cebola']);

        return $this->abrir($pedido);
    }

    public function test_qr_pareia_o_tablet_uma_vez_e_o_cookie_identifica_o_dispositivo(): void
    {
        $tablet = DispositivoCozinha::factory()->paraSetores($this->pizzaria)->create();
        $token = app(PareamentoDispositivoService::class)->gerarToken($tablet);

        $resposta = $this->get('/cozinha/parear/'.$token);

        $resposta->assertRedirect(Estacao::getUrl());
        $resposta->assertCookie(IdentificaDispositivoCozinha::COOKIE);
        $tablet->refresh();
        $this->assertAuthenticatedAs($tablet->usuario);

        $this->withCookie(IdentificaDispositivoCozinha::COOKIE, IdentificaDispositivoCozinha::valorDoCookie($tablet))
            ->get(Estacao::getUrl())
            ->assertSuccessful();
        $this->assertNotNull($tablet->fresh()->ultimo_ping_em);

        auth()->logout();
        $this->get('/cozinha/parear/'.$token)->assertSuccessful()->assertSee('Não foi possível parear');
    }

    public function test_tablet_revogado_sai_da_conta_da_estacao(): void
    {
        $tablet = $this->tabletLogado();
        $cookie = IdentificaDispositivoCozinha::valorDoCookie($tablet);
        app(PareamentoDispositivoService::class)->revogar($tablet);

        $this->withCookie(IdentificaDispositivoCozinha::COOKIE, $cookie)
            ->get(Estacao::getUrl())
            ->assertRedirect(Filament::getPanel('cozinha')->getLoginUrl());
        $this->assertGuest();
    }

    public function test_paineis_liberados_por_papel(): void
    {
        $this->actingAs(User::factory()->garcom()->create(['name_first' => 'Beto']));
        $this->get(Estacao::getUrl())->assertForbidden();

        $this->actingAs($this->cozinheira());
        $this->get(Estacao::getUrl())->assertSuccessful();
    }

    public function test_operador_escolhido_no_tablet_com_pin(): void
    {
        config(['cozinha.operador.exigir_pin' => true]);
        $this->tabletLogado();
        $ana = User::factory()->cozinha()->comPin('1234')->create(['name_first' => 'Ana']);

        Livewire::test(Estacao::class)
            ->callAction('trocarOperador', ['user_id' => $ana->id, 'pin' => '9999'])
            ->assertNotified('PIN incorreto.')
            ->setActionData(['user_id' => $ana->id, 'pin' => '1234'])
            ->callMountedAction()
            ->assertNotified('Bom trabalho, Ana!');

        $this->assertSame($ana->id, app(OperadorCozinhaService::class)->atual()?->userId);
    }

    public function test_estacao_inicia_conclui_desfaz_e_trava_sem_operador(): void
    {
        $this->tabletLogado();
        $pedido = $this->pedidoNaFila();
        [$primeira, $segunda] = ProducaoItem::orderBy('id')->get()->all();

        $tela = Livewire::test(Estacao::class)
            ->assertSee(['Fila', 'SEM cebola', 'Mesa 7'])
            ->assertSee('escolha quem está produzindo')
            ->call('iniciar', $primeira->id, $primeira->versao)
            ->assertNotified();
        $this->assertSame(StatusItemProducao::NaFila, $primeira->fresh()->status, 'Pizzaria exige operador.');

        app(OperadorCozinhaService::class)->selecionar($this->cozinheira());

        $tela = Livewire::test(Estacao::class)
            ->call('iniciar', $primeira->id, $primeira->versao)
            ->assertDispatched('cozinha-feito');
        $primeira->refresh();
        $this->assertSame(StatusItemProducao::EmPreparo, $primeira->status);
        $this->assertSame('Ana', $primeira->operador_nome);
        $this->assertSame(StatusPedidoEnum::PREPARANDO, $pedido->fresh()->status());

        $tela->call('concluir', $primeira->id, $primeira->versao)
            ->call('desfazer', $primeira->id);
        $this->assertSame(StatusItemProducao::EmPreparo, $primeira->fresh()->status);

        // Versão velha (outro tablet já mexeu): recusa sem quebrar a tela.
        $tela->call('iniciar', $segunda->id, $segunda->versao + 5)->assertNotified();
        $this->assertSame(StatusItemProducao::NaFila, $segunda->fresh()->status);

        $tela->call('iniciarPedido', $pedido->id);
        $this->assertSame(StatusItemProducao::EmPreparo, $segunda->fresh()->status);

        $tela->mountAction('ficha', ['item' => $primeira->id])
            ->assertMountedActionModalSee('Ficha técnica não cadastrada');
    }

    public function test_poll_so_renderiza_quando_a_versao_muda_e_toca_no_item_novo(): void
    {
        $this->tabletLogado();
        app(OperadorCozinhaService::class)->selecionar($this->cozinheira());

        $tela = Livewire::test(Estacao::class);
        $tela->call('verificarVersao')->assertNotDispatched('cozinha-som');

        $this->pedidoNaFila();

        $tela->call('verificarVersao')
            ->assertDispatched('cozinha-som', tipo: 'novo')
            ->assertSee('Calabresa');
    }

    public function test_all_day_soma_fracoes_dos_sabores_e_as_massas(): void
    {
        $grande = $this->categoria('Pizza Grande');
        $this->rotaCategoria($grande, $this->pizzaria);
        $calabresa = $this->produto('Calabresa', $grande);
        $pedido = $this->pedidoMesa(User::factory()->garcom()->create(['name_first' => 'Beto']));
        $this->item($pedido, $calabresa, 1);
        $this->itemMeioAMeio($pedido, $calabresa, $this->produto('Portuguesa', $grande));
        $this->abrir($pedido);

        $allDay = app(PainelCozinhaService::class)->allDay(ProducaoItem::all());

        $this->assertContains(['rotulo' => 'Massas · Pizza Grande', 'quantidade' => '2', 'base' => true], $allDay);
        $this->assertContains(['rotulo' => 'Calabresa · Pizza Grande', 'quantidade' => '1½', 'base' => false], $allDay);
        $this->assertContains(['rotulo' => 'Portuguesa · Pizza Grande', 'quantidade' => '½', 'base' => false], $allDay);
    }

    public function test_expedicao_despacha_o_pedido_pronto_e_refaz_item(): void
    {
        $this->actingAs($gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']));
        $pedido = $this->pedidoNaFila();
        $producao = app(ProducaoService::class);

        foreach (ProducaoItem::all() as $item) {
            $producao->concluir($producao->iniciar($item, $this->operador($gerente)), $this->operador($gerente));
        }

        Livewire::test(Expedicao::class)
            ->assertSee(['LEVAR À MESA', 'Pizzaria 2/2'])
            ->callAction('refazer', ['motivo' => 'queimou'], ['item' => ProducaoItem::first()->id])
            ->assertNotified('Item enviado de novo para a cozinha')
            ->assertSee('AGUARDANDO A COZINHA');

        $nova = ProducaoItem::where('geracao', 2)->sole();
        $producao->concluir($producao->iniciar($nova, $this->operador($gerente)), $this->operador($gerente));

        Livewire::test(Expedicao::class)->call('despachar', $pedido->id);

        $this->assertSame(StatusPedidoEnum::ENTREGUE, $pedido->fresh()->status());
        $this->assertSame(2, ProducaoItem::where('status', StatusItemProducao::Expedido)->count());

        config(['cozinha.usa_expedicao' => false]);
        $this->assertFalse(Expedicao::canAccess());
    }

    public function test_consulta_de_fichas_abre_a_ficha_do_produto(): void
    {
        $this->actingAs($this->cozinheira());
        $produto = $this->produto('Calabresa', $this->categoria('Pizza Grande'));
        FichaTecnica::factory()->doProduto($produto)->create(['pontos_criticos' => 'Borda dourada']);

        Livewire::test(ConsultaFichas::class)
            ->assertSee('Calabresa')
            ->set('busca', 'xyz')
            ->assertDontSee('Calabresa')
            ->set('busca', 'cala')
            ->mountAction('ficha', ['produto' => $produto->id])
            ->assertMountedActionModalSee('Borda dourada');
    }

    public function test_checklists_no_tablet_usam_o_operador_e_a_execucao_avisa_item_novo(): void
    {
        $tablet = $this->tabletLogado();
        $modelo = ChecklistModelo::factory()->setor(ChecklistSetor::Cozinha)->paraPapeis(['Cozinha'])->comItens(1)->create(['nome' => 'Abertura da Pizzaria']);

        Livewire::test(ChecklistsCozinha::class)->assertSee('sem operador, os checklists abrem só para leitura');

        $ana = $this->cozinheira();
        $operador = app(OperadorCozinhaService::class)->selecionar($ana);
        $execucao = app(ChecklistExecucaoService::class)->iniciar($modelo, $tablet->usuario, $operador, $tablet);

        $this->withCookie(IdentificaDispositivoCozinha::COOKIE, IdentificaDispositivoCozinha::valorDoCookie($tablet))
            ->get(ChecklistsCozinha::getUrl())
            ->assertSuccessful()
            ->assertSee('Abertura da Pizzaria');

        $tela = Livewire::test(ExecutarChecklistCozinha::class, ['execucao' => $execucao->id])
            ->assertSee('Voltar à fila')
            ->assertDontSee('novos)');

        $this->travel(1)->minutes();
        $this->pedidoNaFila();

        $tela->call('verificarVersao')
            ->assertSee('Voltar à fila (2 novos)')
            ->assertDispatched('cozinha-som');

        $this->assertSame($tablet->id, $execucao->fresh()->dispositivo_cozinha_id);
    }

    public function test_estacao_mostra_a_faixa_do_checklist_pendente(): void
    {
        $this->tabletLogado();
        app(OperadorCozinhaService::class)->selecionar($this->cozinheira());
        ChecklistModelo::factory()->setor(ChecklistSetor::Cozinha)->paraPapeis(['Cozinha'])->comItens(1)->create([
            'nome' => 'Abertura da Pizzaria',
            'dias_semana' => ['3'],
            'janela_inicio' => '18:00',
            'janela_fim' => '19:20',
        ]);

        Livewire::test(Estacao::class)
            ->assertSee(['Checklist: Abertura da Pizzaria', 'faltam 20 min para o fim da janela']);
    }
}
