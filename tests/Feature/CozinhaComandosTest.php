<?php

namespace Tests\Feature;

use App\Models\ProducaoItem;
use App\Models\SetorProducao;
use App\Models\User;
use App\Notifications\Cozinha\ProducaoAtrasadaNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\CenarioCozinha;
use Tests\TestCase;

/** Fase 2: comandos agendados e de suporte do módulo de cozinha. */
class CozinhaComandosTest extends TestCase
{
    use CenarioCozinha, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_alertar_atrasos_avisa_os_gestores_uma_vez_por_item(): void
    {
        Carbon::setTestNow('2026-10-14 20:00:00');
        Notification::fake();
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);
        $setor = SetorProducao::factory()->create(['tempo_alvo_min' => 15]);
        $atrasado = ProducaoItem::factory()->doSetor($setor)->create(['liberado_em' => now()->subMinutes(31)]);
        ProducaoItem::factory()->doSetor($setor)->create(['liberado_em' => now()->subMinutes(20)]);

        $this->artisan('cozinha:alertar-atrasos')->assertSuccessful();
        $this->artisan('cozinha:alertar-atrasos')->assertSuccessful();

        Notification::assertSentToTimes($gerente, ProducaoAtrasadaNotification::class, 1);
        Notification::assertSentTo($gerente, fn (ProducaoAtrasadaNotification $n) => $n->item->is($atrasado));
    }

    public function test_reconstruir_regera_o_que_falta_sem_duplicar(): void
    {
        $this->actingAs(User::factory()->gerente()->create(['name_first' => 'Gerente']));
        $pizzaria = SetorProducao::factory()->create();
        $pizzas = $this->categoria('Pizzas');
        $this->rotaCategoria($pizzas, $pizzaria);
        $pedido = $this->pedidoMesa();
        $this->item($pedido, $this->produto('Calabresa', $pizzas), 2);
        $this->abrir($pedido);

        DB::table('producao_eventos')->where('producao_item_id', ProducaoItem::max('id'))->delete();
        DB::table('producao_itens')->where('id', ProducaoItem::max('id'))->delete();
        $this->assertSame(1, ProducaoItem::count());

        $this->artisan('cozinha:reconstruir', ['pedido' => $pedido->id])->assertSuccessful();
        $this->artisan('cozinha:reconstruir', ['pedido' => $pedido->id])->assertSuccessful();

        $this->assertSame(2, ProducaoItem::count());
    }
}
