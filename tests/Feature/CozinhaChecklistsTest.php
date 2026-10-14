<?php

namespace Tests\Feature;

use App\Enums\Checklist\ChecklistSetor;
use App\Enums\Checklist\ChecklistTipo;
use App\Enums\Checklist\ExecucaoStatus;
use App\Exceptions\ProducaoException;
use App\Models\ChecklistModelo;
use App\Models\DispositivoCozinha;
use App\Models\ProducaoItem;
use App\Models\SetorProducao;
use App\Models\User;
use App\Services\Checklist\ChecklistExecucaoService;
use App\Services\Cozinha\ProducaoService;
use App\Support\Cozinha\Operador;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CenarioCozinha;
use Tests\TestCase;

/** Fase 2: checklists da cozinha (§5.10) — destino por tablet, operador e bloqueio opcional. */
class CozinhaChecklistsTest extends TestCase
{
    use CenarioCozinha, RefreshDatabase;

    private SetorProducao $pizzaria;

    private SetorProducao $bar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-14 16:10:00');
        $this->pizzaria = SetorProducao::factory()->create(['nome' => 'Pizzaria']);
        $this->bar = SetorProducao::factory()->bar()->create(['nome' => 'Bar']);
    }

    /** @return list<int> */
    private function idsNoPainel(string $painel, ?DispositivoCozinha $dispositivo = null): array
    {
        return ChecklistModelo::paraPainel($painel, $dispositivo)->orderBy('id')->pluck('id')->all();
    }

    public function test_destino_dos_modelos_por_painel_e_tablet(): void
    {
        config(['cozinha.checklists.exibir_tambem_no_garcom' => false]);
        $cozinha = ChecklistModelo::factory()->setor(ChecklistSetor::Cozinha)->create();
        $doBar = ChecklistModelo::factory()->setor(ChecklistSetor::Cozinha)->create(['setor_producao_id' => $this->bar->id]);
        $salao = ChecklistModelo::factory()->setor(ChecklistSetor::Salao)->create();
        $tabletPizzaria = DispositivoCozinha::factory()->paraSetores($this->pizzaria)->create();
        $tabletBar = DispositivoCozinha::factory()->paraSetores($this->bar)->create();

        $this->assertSame([$cozinha->id], $this->idsNoPainel(ChecklistModelo::PAINEL_COZINHA, $tabletPizzaria));
        $this->assertSame([$cozinha->id, $doBar->id], $this->idsNoPainel(ChecklistModelo::PAINEL_COZINHA, $tabletBar));
        $this->assertSame([$salao->id], $this->idsNoPainel(ChecklistModelo::PAINEL_GARCOM));
        $this->assertSame('Cozinha — Bar', $doBar->painelDescricao());

        config(['cozinha.checklists.exibir_tambem_no_garcom' => true]);
        $this->assertSame([$cozinha->id, $doBar->id, $salao->id], $this->idsNoPainel(ChecklistModelo::PAINEL_GARCOM));
    }

    public function test_resposta_no_tablet_grava_a_conta_da_estacao_e_o_operador(): void
    {
        $estacao = User::factory()->create(['name_first' => 'Tablet']);
        $estacao->assignRole('EstacaoCozinha');
        $ana = Operador::doUsuario(User::factory()->cozinha()->create(['name_first' => 'Ana']));
        $tablet = DispositivoCozinha::factory()->paraSetores($this->pizzaria)->create(['user_id' => $estacao->id]);
        $modelo = ChecklistModelo::factory()->paraPapeis(['Cozinha'])->comItens(2)->create();
        $servico = app(ChecklistExecucaoService::class);

        $execucao = $servico->iniciar($modelo, $estacao, $ana, $tablet);
        $item = $servico->responder($execucao->itens()->first(), ['valor' => true], $estacao, $ana);

        $this->assertSame($ana->userId, $execucao->operador_id);
        $this->assertSame('Ana', $execucao->operador_nome);
        $this->assertSame($tablet->id, $execucao->dispositivo_cozinha_id);
        $this->assertSame($estacao->id, $item->respondido_por);
        $this->assertSame($ana->userId, $item->operador_id);

        // Item de produção chegando no meio do checklist não mexe nas respostas.
        $pizzas = $this->categoria('Pizzas');
        $this->rotaCategoria($pizzas, $this->pizzaria);
        $this->actingAs($estacao);
        $pedido = $this->pedidoMesa();
        $this->item($pedido, $this->produto('Calabresa', $pizzas));
        $this->abrir($pedido);

        $this->assertSame(1, ProducaoItem::count());
        $this->assertTrue((bool) $item->fresh()->valor_booleano);
        $this->assertSame(ExecucaoStatus::EmAndamento, $execucao->fresh()->status);
    }

    public function test_bloqueio_sem_abertura_recusa_iniciar_ate_concluir_ou_liberar(): void
    {
        config(['cozinha.checklists.bloquear_producao_sem_abertura' => true]);
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);
        $this->actingAs($gerente);
        $producao = app(ProducaoService::class);
        $pizzas = $this->categoria('Pizzas');
        $this->rotaCategoria($pizzas, $this->pizzaria);
        $pedido = $this->pedidoMesa();
        $this->item($pedido, $this->produto('Calabresa', $pizzas), 2);
        $this->abrir($pedido);
        [$primeira, $segunda] = ProducaoItem::orderBy('id')->get()->all();

        // Setor sem modelo de abertura hoje não bloqueia.
        $producao->iniciar($primeira, Operador::doUsuario($gerente));

        $abertura = ChecklistModelo::factory()->tipo(ChecklistTipo::Abertura)->setor(ChecklistSetor::Cozinha)->paraPapeis(['Cozinha'])->comItens(1)->create();

        try {
            $producao->iniciar($segunda, Operador::doUsuario($gerente));
            $this->fail('Sem a abertura concluída deveria bloquear.');
        } catch (ProducaoException $e) {
            $this->assertStringContainsString('abertura de Pizzaria', $e->getMessage());
        }

        $liberacao = $producao->liberarSemAbertura($this->pizzaria, $gerente, 'Checklist em papel hoje');
        $this->assertSame('Checklist em papel hoje', $liberacao->dados['justificativa']);
        $producao->iniciar($segunda, Operador::doUsuario($gerente));

        // Concluída a abertura, outro setor ligado aos mesmos modelos gerais também libera.
        $this->assertTrue($producao->aberturaPendente($this->bar));
        $cozinheira = User::factory()->cozinha()->create(['name_first' => 'Ana']);
        $servico = app(ChecklistExecucaoService::class);
        $execucao = $servico->iniciar($abertura, $cozinheira);
        $servico->responder($execucao->itens()->sole(), ['valor' => true], $cozinheira);
        $servico->finalizar($execucao, $cozinheira);

        $this->assertFalse($producao->aberturaPendente($this->bar));
    }
}
