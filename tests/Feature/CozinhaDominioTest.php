<?php

namespace Tests\Feature;

use App\Enums\Cozinha\EscopoIngrediente;
use App\Enums\Cozinha\PrioridadeProducao;
use App\Enums\Cozinha\StatusItemProducao;
use App\Exceptions\RegistroImutavelException;
use App\Models\Categoria;
use App\Models\DispositivoCozinha;
use App\Models\FichaTecnica;
use App\Models\FichaTecnicaItem;
use App\Models\FichaTecnicaPasso;
use App\Models\ProducaoEvento;
use App\Models\ProducaoItem;
use App\Models\RotaProducao;
use App\Models\SetorProducao;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/** Fase 1 do módulo de cozinha: banco, enums e models (sem regras de serviço). */
class CozinhaDominioTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_maquina_de_estados_do_item_recusa_pulos_e_saidas_de_terminais(): void
    {
        $this->assertTrue(StatusItemProducao::Aguardando->podeTransitarPara(StatusItemProducao::NaFila));
        $this->assertTrue(StatusItemProducao::Pronto->podeTransitarPara(StatusItemProducao::EmPreparo));
        $this->assertTrue(StatusItemProducao::EmPreparo->podeTransitarPara(StatusItemProducao::NaFila));

        $this->assertFalse(StatusItemProducao::Aguardando->podeTransitarPara(StatusItemProducao::Pronto));
        $this->assertFalse(StatusItemProducao::NaFila->podeTransitarPara(StatusItemProducao::Expedido));
        $this->assertFalse(StatusItemProducao::Expedido->podeTransitarPara(StatusItemProducao::Pronto));
        $this->assertFalse(StatusItemProducao::Cancelado->podeTransitarPara(StatusItemProducao::NaFila));
    }

    public function test_evento_de_producao_nao_pode_ser_alterado_nem_apagado(): void
    {
        $evento = ProducaoEvento::factory()->create();

        try {
            $evento->update(['motivo' => 'mexi']);
            $this->fail('Update deveria ser recusado.');
        } catch (RegistroImutavelException) {
        }

        $this->expectException(RegistroImutavelException::class);
        $evento->delete();
    }

    public function test_rota_pertence_a_produto_ou_categoria_nunca_aos_dois_nem_a_nenhum(): void
    {
        $setor = SetorProducao::factory()->create();
        $categoria = Categoria::create(['categoria_nome' => 'Pizzas']);

        $rota = RotaProducao::factory()->daCategoria($categoria)->create(['setor_producao_id' => $setor->id]);
        $this->assertSame($categoria->id, $rota->categoria_id);

        $this->expectException(InvalidArgumentException::class);
        RotaProducao::create(['setor_producao_id' => $setor->id, 'etapa' => 2]);
    }

    public function test_unique_impede_item_duplicado_na_mesma_etapa_unidade_e_geracao(): void
    {
        $item = ProducaoItem::factory()->create();

        $this->expectException(QueryException::class);
        ProducaoItem::factory()->create([
            'item_pedido_id' => $item->item_pedido_id,
            'pedido_id' => $item->pedido_id,
            'setor_producao_id' => $item->setor_producao_id,
        ]);
    }

    public function test_fila_ordena_por_prioridade_e_depois_pelo_mais_antigo(): void
    {
        $setor = SetorProducao::factory()->create();
        $antigo = ProducaoItem::factory()->doSetor($setor)->create(['liberado_em' => now()->subMinutes(10)]);
        $novo = ProducaoItem::factory()->doSetor($setor)->create(['liberado_em' => now()->subMinute()]);
        $urgente = ProducaoItem::factory()->doSetor($setor)->prioridade(PrioridadeProducao::Urgente)->create(['liberado_em' => now()]);
        ProducaoItem::factory()->doSetor($setor)->status(StatusItemProducao::EmPreparo)->create();
        ProducaoItem::factory()->create(); // outro setor

        $fila = ProducaoItem::doSetor($setor->id)->naFila()->pluck('id')->all();

        $this->assertSame([$urgente->id, $antigo->id, $novo->id], $fila);
    }

    public function test_prontos_recentes_respeita_a_janela_de_recall(): void
    {
        $recente = ProducaoItem::factory()->status(StatusItemProducao::Pronto)->create(['concluido_em' => now()->subMinutes(3)]);
        ProducaoItem::factory()->status(StatusItemProducao::Pronto)->create(['concluido_em' => now()->subMinutes(30)]);

        $this->assertSame([$recente->id], ProducaoItem::prontosRecentes(10)->pluck('id')->all());
    }

    public function test_cor_do_timer_segue_os_tempos_do_setor(): void
    {
        Carbon::setTestNow('2026-10-14 20:00:00');
        $setor = SetorProducao::factory()->create(['tempo_alerta_min' => 10, 'tempo_alvo_min' => 15]);
        $item = ProducaoItem::factory()->doSetor($setor)->create(['liberado_em' => now()]);

        $this->assertSame('verde', $item->corTimer(now()->addMinutes(9)));
        $this->assertSame('amarelo', $item->corTimer(now()->addMinutes(10)));
        $this->assertSame('vermelho', $item->corTimer(now()->addMinutes(15)));
        $this->assertSame('atrasado', $item->corTimer(now()->addMinutes(30)));
        $this->assertSame(0, ProducaoItem::factory()->status(StatusItemProducao::Aguardando)->create()->idadeSegundos());
    }

    public function test_ficha_le_ingredientes_da_ficha_de_custo_e_filtra_passos_por_setor(): void
    {
        $pizzaria = SetorProducao::factory()->create();
        $forno = SetorProducao::factory()->create();
        $ficha = FichaTecnica::factory()->create();
        $base = FichaTecnicaItem::factory()->escopo(EscopoIngrediente::Base)->create(['fti_produto_id' => $ficha->produto_id, 'fti_ordem' => 1]);
        $cobertura = FichaTecnicaItem::factory()->create(['fti_produto_id' => $ficha->produto_id, 'fti_ordem' => 2]);
        $geral = FichaTecnicaPasso::factory()->create(['ficha_tecnica_id' => $ficha->id, 'ordem' => 1]);
        $montagem = FichaTecnicaPasso::factory()->doSetor($pizzaria)->create(['ficha_tecnica_id' => $ficha->id, 'ordem' => 2]);
        FichaTecnicaPasso::factory()->doSetor($forno)->create(['ficha_tecnica_id' => $ficha->id, 'ordem' => 3]);

        $this->assertSame([$base->id, $cobertura->id], $ficha->ingredientes->pluck('id')->all());
        $this->assertSame(EscopoIngrediente::Base, $ficha->ingredientes->first()->fti_escopo);
        $this->assertSame([$geral->id, $montagem->id], $ficha->passos()->doSetor($pizzaria->id)->pluck('id')->all());
    }

    public function test_dispositivo_encontrado_pelos_setores_pareados(): void
    {
        $pizzaria = SetorProducao::factory()->create();
        $bar = SetorProducao::factory()->bar()->create();
        $tablet = DispositivoCozinha::factory()->paraSetores($pizzaria)->create();
        DispositivoCozinha::factory()->paraSetores($bar)->create();

        $this->assertSame([$tablet->id], DispositivoCozinha::doSetor($pizzaria->id)->pluck('id')->all());
        $this->assertTrue($tablet->atendeSetor($pizzaria->id));
        $this->assertFalse($tablet->atendeSetor($bar->id));
    }

    public function test_migration_copia_linhas_de_producao_para_setores_e_rotas(): void
    {
        $pizzas = Categoria::create(['categoria_nome' => 'Pizza Grande']);
        $pastel = Categoria::create(['categoria_nome' => 'Pastel']);
        $linha1 = DB::table('linhas_producao')->insertGetId(['linha_nome' => 'Cozinha Pizzas']);
        $linha2 = DB::table('linhas_producao')->insertGetId(['linha_nome' => 'Cozinha 2']);
        DB::table('categoria_linha_producao')->insert([
            ['categoria_id' => $pizzas->id, 'linha_producao_id' => $linha1],
            ['categoria_id' => $pastel->id, 'linha_producao_id' => $linha2],
            ['categoria_id' => $pastel->id, 'linha_producao_id' => $linha1],
        ]);

        (require database_path('migrations/2026_10_14_100003_copy_linhas_producao_to_setores_producao.php'))->up();

        $setores = SetorProducao::orderBy('ordem')->pluck('id', 'nome');
        $this->assertSame(['Cozinha Pizzas', 'Cozinha 2'], $setores->keys()->all());
        $this->assertSame($setores['Cozinha Pizzas'], RotaProducao::where('categoria_id', $pizzas->id)->value('setor_producao_id'));
        // Categoria em duas linhas: fica a de menor id.
        $this->assertSame($setores['Cozinha Pizzas'], RotaProducao::where('categoria_id', $pastel->id)->sole()->setor_producao_id);
    }
}
