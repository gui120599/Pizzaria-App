<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Models\Categoria;
use App\Models\Produto;
use App\Models\PromocaoRelampago;
use App\Services\CardapioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Cronômetro da relâmpago no cardápio: quando termina a ocorrência vigente
 * (TemVigenciaRecorrente::fimDaOcorrencia) e os segundos que faltam no card.
 */
class PromocaoRelampagoCronometroTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function promocao(array $attrs): PromocaoRelampago
    {
        return PromocaoRelampago::create($attrs + [
            'promocao_nome' => 'Relâmpago',
            'promocao_ativa' => true,
            'promocao_inicio' => Carbon::parse('2026-11-01 00:00'),
        ]);
    }

    public function test_janela_unica_termina_no_fim(): void
    {
        Carbon::setTestNow('2026-11-27 19:00:00');
        $promocao = $this->promocao(['promocao_fim' => Carbon::parse('2026-11-27 22:50')]);

        $this->assertSame('2026-11-27 22:50:00', $promocao->fimDaOcorrencia()->format('Y-m-d H:i:s'));
    }

    public function test_recorrente_termina_no_horario_de_hoje_e_fora_da_janela_e_nulo(): void
    {
        Carbon::setTestNow('2026-11-27 19:00:00'); // sexta
        $promocao = $this->promocao(['promocao_recorrente' => true, 'promocao_dias_semana' => [5], 'promocao_hora_inicio' => '18:00', 'promocao_hora_fim' => '21:30']);

        $this->assertSame('2026-11-27 21:30:00', $promocao->fimDaOcorrencia()->format('Y-m-d H:i:s'));
        $this->assertNull($promocao->fimDaOcorrencia(Carbon::parse('2026-11-27 22:00')));
    }

    public function test_janela_que_atravessa_a_meia_noite_termina_no_dia_seguinte(): void
    {
        Carbon::setTestNow('2026-11-27 23:00:00');
        $promocao = $this->promocao(['promocao_recorrente' => true, 'promocao_dias_semana' => [5], 'promocao_hora_inicio' => '22:00', 'promocao_hora_fim' => '02:00']);

        $this->assertSame('2026-11-28 02:00:00', $promocao->fimDaOcorrencia()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-28 02:00:00', $promocao->fimDaOcorrencia(Carbon::parse('2026-11-28 01:00'))->format('Y-m-d H:i:s'));
    }

    public function test_dia_inteiro_vai_ate_o_ultimo_dia_seguido_e_respeita_a_data_final(): void
    {
        Carbon::setTestNow('2026-11-27 10:00:00'); // sexta
        $promocao = $this->promocao(['promocao_recorrente' => true, 'promocao_dias_semana' => [5, 6]]);
        $this->assertSame('2026-11-28 23:59:59', $promocao->fimDaOcorrencia()->format('Y-m-d H:i:s'));

        $todoDia = $this->promocao(['promocao_recorrente' => true, 'promocao_dias_semana' => []]);
        $this->assertNull($todoDia->fimDaOcorrencia());

        $todoDia->update(['promocao_data_final_recorrencia' => '2026-11-30']);
        $this->assertSame('2026-11-30 23:59:59', $todoDia->fresh()->fimDaOcorrencia()->format('Y-m-d H:i:s'));
    }

    public function test_card_do_cardapio_leva_os_segundos_restantes(): void
    {
        Carbon::setTestNow('2026-11-27 19:00:00');
        $promocao = $this->promocao(['promocao_fim' => Carbon::parse('2026-11-27 20:30')]);
        $promocao->promocaoProdutos()->create(['prp_produto_id' => Produto::create([
            'produto_descricao' => 'Calabresa',
            'produto_categoria_id' => Categoria::create(['categoria_nome' => 'Pizzas', 'categoria_cardapio' => true])->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 59.90,
            'produto_cardapio' => true,
        ])->id, 'prp_preco_promocional' => 39.90]);

        $dados = app(CardapioService::class)->dados();

        $this->assertSame(5400, $dados['promocoesRelampago']->first()['segundos_restantes']);
        $this->assertSame(5400, $dados['anunciosPromocoes'][0]['segundos_restantes']);
        $this->get(route('cardapio'))->assertOk()->assertSee('cronometro(5400)', false);
    }
}
