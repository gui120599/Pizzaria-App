<?php

namespace Tests\Feature;

use App\Enums\Checklist\ExecucaoStatus;
use App\Enums\Checklist\ExigenciaFoto;
use App\Enums\Checklist\NaoConformidadeStatus;
use App\Exceptions\ChecklistFinalizacaoException;
use App\Models\ChecklistExecucao;
use App\Models\ChecklistModelo;
use App\Models\ChecklistModeloItem;
use App\Models\ChecklistNaoConformidade;
use App\Models\User;
use App\Notifications\Checklist\ChecklistItemCriticoNotification;
use App\Services\Checklist\ChecklistExecucaoService;
use App\Services\Checklist\ChecklistModeloService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Tipo "Fazer se precisar" (Feito / Tinha estoque / Não feito) e dias da semana por item. */
class ChecklistProducaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_feito_e_tinha_estoque_sao_conformes_e_nao_feito_nao(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $cozinheira = $this->cozinheira();
        $modelo = $this->modeloComItensProducao(3);
        $itens = $this->servico()->iniciar($modelo, $cozinheira)->itens()->get();

        $feito = $this->servico()->responder($itens[0], ['valor' => 'feito'], $cozinheira);
        $estoque = $this->servico()->responder($itens[1], ['valor' => 'tinha_estoque'], $cozinheira);
        $naoFeito = $this->servico()->responder($itens[2], ['valor' => 'nao_feito'], $cozinheira);

        $this->assertTrue($feito->conforme);
        $this->assertTrue($estoque->conforme);
        $this->assertFalse($naoFeito->conforme);
        $this->assertSame('Tinha estoque', $estoque->respostaFormatada());
        $this->assertTrue($estoque->foiDispensado());
        $this->assertFalse($feito->foiDispensado());
    }

    public function test_tinha_estoque_em_item_critico_nao_gera_nc_nem_notificacao_e_fecha_com_100_por_cento(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        Notification::fake();
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);
        $cozinheira = $this->cozinheira();
        $modelo = ChecklistModelo::factory()->paraPapeis(['Cozinha'])->create();
        ChecklistModeloItem::factory()->for($modelo, 'modelo')->producao()->critico()->exigeFoto(ExigenciaFoto::SeNaoConforme)->create();
        $execucao = $this->servico()->iniciar($modelo, $cozinheira);
        $this->servico()->responder($execucao->itens()->first(), ['valor' => 'tinha_estoque'], $cozinheira);

        $finalizada = $this->servico()->finalizar($execucao, $cozinheira);

        $this->assertSame(ExecucaoStatus::Concluido, $finalizada->status);
        $this->assertSame('100.00', $finalizada->percentual_conformidade);
        $this->assertSame(0, ChecklistNaoConformidade::count());
        Notification::assertNotSentTo($gerente, ChecklistItemCriticoNotification::class);
    }

    public function test_nao_feito_em_item_critico_notifica_pede_foto_e_observacao_e_gera_nc(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        Notification::fake();
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);
        $cozinheira = $this->cozinheira();
        $modelo = ChecklistModelo::factory()->paraPapeis(['Cozinha'])->create();
        ChecklistModeloItem::factory()->for($modelo, 'modelo')->producao()->critico()->exigeFoto(ExigenciaFoto::SeNaoConforme)->create();
        $execucao = $this->servico()->iniciar($modelo, $cozinheira);
        $item = $execucao->itens()->first();
        $this->servico()->responder($item, ['valor' => 'nao_feito'], $cozinheira);

        Notification::assertSentTo($gerente, ChecklistItemCriticoNotification::class);
        $this->assertSame(
            [$item->id => ['Anexe uma foto do problema.', 'Descreva o problema na observação.']],
            $this->errosAoFinalizar($execucao, $cozinheira),
        );

        Storage::fake('public');
        $this->servico()->anexarFoto($item, UploadedFile::fake()->image('falta.jpg'), $cozinheira);
        $this->servico()->responder($item, ['observacao' => 'Acabou o presunto.'], $cozinheira);
        $finalizada = $this->servico()->finalizar($execucao, $cozinheira);

        $this->assertSame(ExecucaoStatus::ConcluidoComPendencias, $finalizada->status);
        $this->assertSame($item->titulo.' — resposta: Não feito. Obs.: Acabou o presunto.', ChecklistNaoConformidade::sole()->descricao);
    }

    public function test_tinha_estoque_com_exige_foto_estoque_bloqueia_a_finalizacao_sem_foto(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        Storage::fake('public');
        $cozinheira = $this->cozinheira();
        $modelo = ChecklistModelo::factory()->paraPapeis(['Cozinha'])->create();
        ChecklistModeloItem::factory()->for($modelo, 'modelo')->producao('menos de 10 sacos', exigeFotoEstoque: true)->create();
        $execucao = $this->servico()->iniciar($modelo, $cozinheira);
        $item = $execucao->itens()->first();
        $this->servico()->responder($item, ['valor' => 'tinha_estoque'], $cozinheira);

        $this->assertSame([$item->id => ['Tire uma foto do estoque.']], $this->errosAoFinalizar($execucao, $cozinheira));

        $this->servico()->anexarFoto($item, UploadedFile::fake()->image('estoque.jpg'), $cozinheira);

        $this->assertSame(ExecucaoStatus::Concluido, $this->servico()->finalizar($execucao, $cozinheira)->status);
        $this->assertSame('menos de 10 sacos', $item->fresh()->criterio());
    }

    public function test_foto_do_estoque_nao_e_exigida_quando_a_resposta_e_feito(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $cozinheira = $this->cozinheira();
        $modelo = ChecklistModelo::factory()->paraPapeis(['Cozinha'])->create();
        ChecklistModeloItem::factory()->for($modelo, 'modelo')->producao(exigeFotoEstoque: true)->create();
        $execucao = $this->servico()->iniciar($modelo, $cozinheira);
        $this->servico()->responder($execucao->itens()->first(), ['valor' => 'feito'], $cozinheira);

        $this->assertSame(ExecucaoStatus::Concluido, $this->servico()->finalizar($execucao, $cozinheira)->status);
    }

    public function test_valor_fora_das_tres_respostas_e_recusado(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $cozinheira = $this->cozinheira();
        $item = $this->servico()->iniciar($this->modeloComItensProducao(1), $cozinheira)->itens()->first();

        $this->expectException(ValidationException::class);

        $this->servico()->responder($item, ['valor' => 'talvez'], $cozinheira);
    }

    public function test_trocar_nao_feito_por_tinha_estoque_apos_reabrir_cancela_a_nc(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $cozinheira = $this->cozinheira();
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);
        $modelo = ChecklistModelo::factory()->paraPapeis(['Cozinha'])->create();
        ChecklistModeloItem::factory()->for($modelo, 'modelo')->producao()->create();
        $execucao = $this->servico()->iniciar($modelo, $cozinheira);
        $item = $execucao->itens()->first();
        $this->servico()->responder($item, ['valor' => 'nao_feito', 'observacao' => 'Faltou.'], $cozinheira);
        $this->servico()->finalizar($execucao, $cozinheira);
        $this->servico()->reprovar($execucao, $gerente, 'Conferir o estoque.');
        $this->servico()->reabrir($execucao, $cozinheira);
        $this->servico()->responder($item, ['valor' => 'tinha_estoque'], $cozinheira);

        $finalizada = $this->servico()->finalizar($execucao, $cozinheira);

        $this->assertSame(ExecucaoStatus::Concluido, $finalizada->status);
        $this->assertSame(NaoConformidadeStatus::Cancelada, ChecklistNaoConformidade::sole()->status);
    }

    public function test_item_restrito_a_dias_so_entra_na_execucao_dos_dias_marcados(): void
    {
        $cozinheira = $this->cozinheira();
        $modelo = ChecklistModelo::factory()->paraPapeis(['Cozinha'])->create();
        ChecklistModeloItem::factory()->for($modelo, 'modelo')->create(['titulo' => 'Todo dia', 'ordem' => 1]);
        ChecklistModeloItem::factory()->for($modelo, 'modelo')->producao()->create(['titulo' => 'Brigadeiro', 'ordem' => 2, 'dias_semana' => ['3']]);
        ChecklistModeloItem::factory()->for($modelo, 'modelo')->create(['titulo' => 'Último', 'ordem' => 3]);

        $this->travelTo('2026-10-13 16:10:00'); // terça
        $terca = $this->servico()->iniciar($modelo, $cozinheira);
        $this->travelTo('2026-10-14 16:10:00'); // quarta
        $quarta = $this->servico()->iniciar($modelo, $cozinheira);

        $this->assertSame(['Todo dia', 'Último'], $terca->itens()->pluck('titulo')->all());
        $this->assertSame([1, 2], $terca->itens()->pluck('ordem')->all());
        $this->assertSame(2, $terca->total_itens);
        $this->assertSame(['Todo dia', 'Brigadeiro', 'Último'], $quarta->itens()->pluck('titulo')->all());
        $this->assertSame([1, 2, 3], $quarta->itens()->pluck('ordem')->all());
    }

    public function test_alterar_dias_do_item_gera_nova_versao_e_regera_a_pendente_sem_respostas(): void
    {
        $this->travelTo('2026-10-13 05:05:00'); // terça
        $modelo = ChecklistModelo::factory()->create();
        ChecklistModeloItem::factory()->for($modelo, 'modelo')->create(['titulo' => 'Todo dia', 'ordem' => 1]);
        $brigadeiro = ChecklistModeloItem::factory()->for($modelo, 'modelo')->producao()->create(['titulo' => 'Brigadeiro', 'ordem' => 2]);
        $this->servico()->gerarPendentesDoDia(today());
        $modeloService = app(ChecklistModeloService::class);
        $assinatura = $modeloService->assinatura($modelo);

        $brigadeiro->update(['dias_semana' => [3]]);
        $gerouVersao = $modeloService->aplicarNovaVersao($modelo, $assinatura);

        $this->assertTrue($gerouVersao);
        $execucao = ChecklistExecucao::sole();
        $this->assertSame(2, $execucao->modelo_versao);
        $this->assertSame(['Todo dia'], $execucao->itens()->pluck('titulo')->all());
    }

    public function test_dias_da_semana_sao_gravados_como_inteiros_ordenados_e_vazio_vira_null(): void
    {
        $item = ChecklistModeloItem::factory()->create(['dias_semana' => ['5', '2', '5']]);
        $this->assertSame([2, 5], $item->fresh()->dias_semana);

        $item->update(['dias_semana' => []]);
        $this->assertNull($item->fresh()->dias_semana);
    }

    private function servico(): ChecklistExecucaoService
    {
        return app(ChecklistExecucaoService::class);
    }

    private function cozinheira(): User
    {
        return User::factory()->cozinha()->create(['name_first' => 'Ana']);
    }

    private function modeloComItensProducao(int $quantidade): ChecklistModelo
    {
        $modelo = ChecklistModelo::factory()->paraPapeis(['Cozinha'])->create();

        foreach (range(1, $quantidade) as $ordem) {
            ChecklistModeloItem::factory()->for($modelo, 'modelo')->producao()->create(['ordem' => $ordem]);
        }

        return $modelo;
    }

    /** @return array<int, list<string>> */
    private function errosAoFinalizar(ChecklistExecucao $execucao, User $user): array
    {
        try {
            $this->servico()->finalizar($execucao, $user);
        } catch (ChecklistFinalizacaoException $e) {
            return $e->erros;
        }

        $this->fail('Era esperada uma ChecklistFinalizacaoException.');
    }
}
