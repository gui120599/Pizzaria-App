<?php

namespace Tests\Feature;

use App\Enums\Checklist\ChecklistTipo;
use App\Enums\Checklist\ExecucaoStatus;
use App\Enums\Checklist\ExigenciaFoto;
use App\Enums\Checklist\NaoConformidadeStatus;
use App\Exceptions\ChecklistException;
use App\Exceptions\ChecklistFinalizacaoException;
use App\Models\ChecklistExecucao;
use App\Models\ChecklistExecucaoItem;
use App\Models\ChecklistModelo;
use App\Models\ChecklistModeloItem;
use App\Models\ChecklistModeloSecao;
use App\Models\ChecklistNaoConformidade;
use App\Models\User;
use App\Notifications\Checklist\ChecklistAguardandoAprovacaoNotification;
use App\Notifications\Checklist\ChecklistItemCriticoNotification;
use App\Notifications\Checklist\ChecklistNaoRealizadoNotification;
use App\Services\Checklist\ChecklistExecucaoService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ChecklistExecucaoServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_iniciar_cria_execucao_em_andamento_com_snapshot_dos_itens_na_ordem_das_secoes(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $cozinheira = $this->cozinheira();
        $modelo = ChecklistModelo::factory()->paraPapeis(['Cozinha'])->create(['nome' => 'Abertura Cozinha']);
        $higiene = ChecklistModeloSecao::factory()->for($modelo, 'modelo')->create(['titulo' => 'Higiene', 'ordem' => 2]);
        $equipamentos = ChecklistModeloSecao::factory()->for($modelo, 'modelo')->create(['titulo' => 'Equipamentos', 'ordem' => 1]);
        $this->itemDoModelo($modelo, ['titulo' => 'Bancadas higienizadas', 'checklist_modelo_secao_id' => $higiene->id, 'ordem' => 1]);
        $this->itemDoModelo($modelo, ['titulo' => 'Forno ligado', 'checklist_modelo_secao_id' => $equipamentos->id, 'ordem' => 1]);
        $this->itemDoModelo($modelo, ['titulo' => 'Gás aberto', 'checklist_modelo_secao_id' => $equipamentos->id, 'ordem' => 2]);

        $execucao = $this->servico()->iniciar($modelo, $cozinheira);

        $this->assertSame(ExecucaoStatus::EmAndamento, $execucao->status);
        $this->assertSame('2026-10-13', $execucao->data_referencia->toDateString());
        $this->assertSame('Abertura Cozinha', $execucao->modelo_nome);
        $this->assertSame($cozinheira->id, $execucao->iniciado_por);
        $this->assertSame(3, $execucao->total_itens);
        $this->assertSame(
            ['Equipamentos — Forno ligado', 'Equipamentos — Gás aberto', 'Higiene — Bancadas higienizadas'],
            $execucao->itens()->get()->map(fn (ChecklistExecucaoItem $item): string => "{$item->secao_titulo} — {$item->titulo}")->all(),
        );
    }

    public function test_iniciar_de_novo_no_mesmo_dia_retoma_a_mesma_execucao(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $modelo = $this->modeloCozinha();
        $primeira = $this->servico()->iniciar($modelo, $this->cozinheira());

        $segunda = $this->servico()->iniciar($modelo, $this->cozinheira());

        $this->assertSame($primeira->id, $segunda->id);
        $this->assertSame(1, ChecklistExecucao::count());
    }

    public function test_iniciar_assume_a_pendente_gerada_pelo_agendamento(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $cozinheira = $this->cozinheira();
        $modelo = $this->modeloCozinha();
        $this->servico()->gerarPendentesDoDia(today());

        $execucao = $this->servico()->iniciar($modelo, $cozinheira);

        $this->assertSame(1, ChecklistExecucao::count());
        $this->assertSame(ExecucaoStatus::EmAndamento, $execucao->status);
        $this->assertSame($cozinheira->id, $execucao->iniciado_por);
    }

    public function test_banco_recusa_segunda_execucao_do_mesmo_modelo_no_mesmo_dia(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $existente = $this->servico()->iniciar($this->modeloCozinha(), $this->cozinheira());

        $this->expectException(UniqueConstraintViolationException::class);

        ChecklistExecucao::factory()->create([
            'checklist_modelo_id' => $existente->checklist_modelo_id,
            'chave_unica' => $existente->chave_unica,
        ]);
    }

    public function test_modelo_com_multiplas_execucoes_por_dia_cria_nova_execucao_a_cada_inicio(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $modelo = ChecklistModelo::factory()->multiplasPorDia()->comItens(1)->paraPapeis(['Cozinha'])->create();

        $primeira = $this->servico()->iniciar($modelo, $this->cozinheira());
        $segunda = $this->servico()->iniciar($modelo, $this->cozinheira());

        $this->assertNotSame($primeira->id, $segunda->id);
        $this->assertNull($segunda->chave_unica);
    }

    public function test_iniciar_recusa_usuario_sem_papel_atribuido_ao_modelo(): void
    {
        $garcom = User::factory()->garcom()->create(['name_first' => 'Bruno']);

        $this->expectExceptionObject(new ChecklistException('Você não está atribuído a este checklist.'));

        $this->servico()->iniciar($this->modeloCozinha(), $garcom);
    }

    public function test_fechamento_feito_depois_da_meia_noite_pertence_ao_dia_anterior(): void
    {
        $this->travelTo('2026-10-11 00:40:00');
        $modelo = ChecklistModelo::factory()
            ->tipo(ChecklistTipo::Fechamento)
            ->janela('22:30:00', '01:30:00')
            ->comItens(1)
            ->paraPapeis(['Cozinha'])
            ->create();

        $execucao = $this->servico()->iniciar($modelo, $this->cozinheira());

        $this->assertSame('2026-10-10', $execucao->data_referencia->toDateString());
    }

    public function test_responder_item_de_execucao_pendente_coloca_a_execucao_em_andamento(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $cozinheira = $this->cozinheira();
        $modelo = $this->modeloCozinha();
        $this->servico()->gerarPendentesDoDia(today());
        $item = ChecklistExecucaoItem::first();

        $respondido = $this->servico()->responder($item, ['valor' => true], $cozinheira);

        $this->assertTrue($respondido->conforme);
        $this->assertSame($cozinheira->id, $respondido->respondido_por);
        $execucao = $respondido->execucao->fresh();
        $this->assertSame(ExecucaoStatus::EmAndamento, $execucao->status);
        $this->assertSame($cozinheira->id, $execucao->iniciado_por);
        $this->assertSame(1, $execucao->itens_respondidos);
    }

    public function test_temperatura_critica_fora_da_faixa_notifica_os_gestores_na_hora_da_resposta(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        Notification::fake();
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);
        $cozinheira = $this->cozinheira();
        $modelo = ChecklistModelo::factory()->paraPapeis(['Cozinha'])->create();
        ChecklistModeloItem::factory()->for($modelo, 'modelo')->temperatura(-25, -18)->critico()->create(['titulo' => 'Freezer']);
        $item = $this->servico()->iniciar($modelo, $cozinheira)->itens()->first();

        $respondido = $this->servico()->responder($item, ['valor' => '-12,5'], $cozinheira);

        $this->assertFalse($respondido->conforme);
        $this->assertSame('-12.50', $respondido->valor_numero);
        Notification::assertSentTo($gerente, ChecklistItemCriticoNotification::class);
        Notification::assertNotSentTo($cozinheira, ChecklistItemCriticoNotification::class);
    }

    public function test_item_critico_ja_nao_conforme_nao_notifica_de_novo_ao_receber_observacao(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        Notification::fake();
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);
        $cozinheira = $this->cozinheira();
        $modelo = ChecklistModelo::factory()->paraPapeis(['Cozinha'])->create();
        ChecklistModeloItem::factory()->for($modelo, 'modelo')->temperatura(-25, -18)->critico()->create();
        $item = $this->servico()->iniciar($modelo, $cozinheira)->itens()->first();
        $this->servico()->responder($item, ['valor' => -12], $cozinheira);

        $this->servico()->responder($item, ['observacao' => 'Porta do freezer ficou aberta.'], $cozinheira);

        Notification::assertSentToTimes($gerente, ChecklistItemCriticoNotification::class, 1);
    }

    public function test_responder_opcao_que_nao_existe_no_item_e_recusado(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $cozinheira = $this->cozinheira();
        $modelo = ChecklistModelo::factory()->paraPapeis(['Cozinha'])->create();
        ChecklistModeloItem::factory()->for($modelo, 'modelo')->selecao()->create();
        $item = $this->servico()->iniciar($modelo, $cozinheira)->itens()->first();

        try {
            $this->servico()->responder($item, ['valor' => 'quebrada'], $cozinheira);
            $this->fail('Era esperada uma ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame(['valor' => ['Opção inválida.']], $e->errors());
        }

        $this->assertNull($item->fresh()->respondido_em);
    }

    public function test_finalizar_bloqueia_item_obrigatorio_sem_resposta(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $cozinheira = $this->cozinheira();
        $modelo = ChecklistModelo::factory()->paraPapeis(['Cozinha'])->create();
        $this->itemDoModelo($modelo, ['titulo' => 'Forno ligado', 'ordem' => 1]);
        $this->itemDoModelo($modelo, ['titulo' => 'Observações', 'ordem' => 2, 'obrigatorio' => false]);
        $execucao = $this->servico()->iniciar($modelo, $cozinheira);
        $forno = $execucao->itens()->where('titulo', 'Forno ligado')->first();

        $erros = $this->errosAoFinalizar($execucao, $cozinheira);

        $this->assertSame([$forno->id => ['Responda este item.']], $erros);
        $this->assertSame(ExecucaoStatus::EmAndamento, $execucao->fresh()->status);
    }

    public function test_finalizar_bloqueia_item_que_exige_foto_sem_anexo(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $cozinheira = $this->cozinheira();
        $modelo = ChecklistModelo::factory()->paraPapeis(['Cozinha'])->create();
        ChecklistModeloItem::factory()->for($modelo, 'modelo')->exigeFoto(ExigenciaFoto::Sempre)->create();
        $execucao = $this->servico()->iniciar($modelo, $cozinheira);
        $item = $execucao->itens()->first();
        $this->servico()->responder($item, ['valor' => true], $cozinheira);

        $erros = $this->errosAoFinalizar($execucao, $cozinheira);

        $this->assertSame([$item->id => ['Este item exige foto.']], $erros);
    }

    public function test_finalizar_bloqueia_item_nao_conforme_sem_observacao_e_sem_foto_exigida(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $cozinheira = $this->cozinheira();
        $modelo = ChecklistModelo::factory()->paraPapeis(['Cozinha'])->create();
        ChecklistModeloItem::factory()->for($modelo, 'modelo')->temperatura(0, 5)->exigeFoto(ExigenciaFoto::SeNaoConforme)->create();
        $execucao = $this->servico()->iniciar($modelo, $cozinheira);
        $item = $execucao->itens()->first();
        $this->servico()->responder($item, ['valor' => 9], $cozinheira);

        $erros = $this->errosAoFinalizar($execucao, $cozinheira);

        $this->assertSame([$item->id => ['Anexe uma foto do problema.', 'Descreva o problema na observação.']], $erros);
    }

    public function test_finalizar_com_item_nao_conforme_gera_nao_conformidade_e_conclui_com_pendencias(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $cozinheira = $this->cozinheira();
        $modelo = ChecklistModelo::factory()->paraPapeis(['Cozinha'])->create();
        $this->itemDoModelo($modelo, ['titulo' => 'Forno ligado', 'ordem' => 1]);
        ChecklistModeloItem::factory()->for($modelo, 'modelo')->temperatura(0, 5)->critico()->create(['titulo' => 'Câmara fria', 'ordem' => 2]);
        $execucao = $this->servico()->iniciar($modelo, $cozinheira);
        [$forno, $camara] = $execucao->itens()->get()->all();
        $this->servico()->responder($forno, ['valor' => true], $cozinheira);
        $this->servico()->responder($camara, ['valor' => 8, 'observacao' => 'Porta mal fechada.'], $cozinheira);

        $finalizada = $this->servico()->finalizar($execucao, $cozinheira, 'Tudo certo no resto.');

        $this->assertSame(ExecucaoStatus::ConcluidoComPendencias, $finalizada->status);
        $this->assertSame('50.00', $finalizada->percentual_conformidade);
        $this->assertSame(1, $finalizada->itens_nao_conformes);
        $this->assertSame($cozinheira->id, $finalizada->concluido_por);
        $this->assertSame('Tudo certo no resto.', $finalizada->observacao_geral);
        $nc = ChecklistNaoConformidade::sole();
        $this->assertSame($camara->id, $nc->checklist_execucao_item_id);
        $this->assertSame('Câmara fria — resposta: 8,00 °C. Obs.: Porta mal fechada.', $nc->descricao);
        $this->assertTrue($nc->critica);
        $this->assertSame(NaoConformidadeStatus::Aberta, $nc->status);
    }

    public function test_finalizar_sem_itens_nao_conformes_conclui_sem_gerar_nao_conformidade(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $cozinheira = $this->cozinheira();
        $execucao = $this->servico()->iniciar($this->modeloCozinha(), $cozinheira);
        $this->servico()->responder($execucao->itens()->first(), ['valor' => true], $cozinheira);

        $finalizada = $this->servico()->finalizar($execucao, $cozinheira);

        $this->assertSame(ExecucaoStatus::Concluido, $finalizada->status);
        $this->assertSame('100.00', $finalizada->percentual_conformidade);
        $this->assertSame(0, ChecklistNaoConformidade::count());
    }

    public function test_finalizar_modelo_que_exige_aprovacao_notifica_os_gestores(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        Notification::fake();
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);
        $cozinheira = $this->cozinheira();
        $modelo = ChecklistModelo::factory()->exigeAprovacao()->paraPapeis(['Cozinha'])->create();
        $this->itemDoModelo($modelo);
        $execucao = $this->servico()->iniciar($modelo, $cozinheira);
        $this->servico()->responder($execucao->itens()->first(), ['valor' => true], $cozinheira);

        $this->servico()->finalizar($execucao, $cozinheira);

        Notification::assertSentTo($gerente, ChecklistAguardandoAprovacaoNotification::class);
    }

    public function test_responder_checklist_ja_finalizado_e_recusado(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $cozinheira = $this->cozinheira();
        $execucao = $this->servico()->iniciar($this->modeloCozinha(), $cozinheira);
        $item = $execucao->itens()->first();
        $this->servico()->responder($item, ['valor' => true], $cozinheira);
        $this->servico()->finalizar($execucao, $cozinheira);

        $this->expectExceptionObject(new ChecklistException('Este checklist já foi finalizado.'));

        $this->servico()->responder($item, ['valor' => false], $cozinheira);
    }

    public function test_reprovar_sem_motivo_e_recusado(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $execucao = $this->execucaoConcluida();

        $this->expectException(ValidationException::class);

        $this->servico()->reprovar($execucao, $this->gerente(), '   ');
    }

    public function test_aprovar_execucao_concluida_registra_quem_aprovou(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $execucao = $this->execucaoConcluida();
        $gerente = $this->gerente();

        $this->servico()->aprovar($execucao, $gerente);

        $execucao->refresh();
        $this->assertSame(ExecucaoStatus::Aprovado, $execucao->status);
        $this->assertSame($gerente->id, $execucao->aprovado_por);
    }

    public function test_aprovar_execucao_ainda_em_andamento_e_recusado(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $execucao = $this->servico()->iniciar($this->modeloCozinha(), $this->cozinheira());

        $this->expectExceptionObject(new ChecklistException('Só é possível aprovar ou reprovar um checklist concluído.'));

        $this->servico()->aprovar($execucao, $this->gerente());
    }

    public function test_execucao_reprovada_so_aceita_respostas_depois_de_reaberta(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $cozinheira = $this->cozinheira();
        $execucao = $this->execucaoConcluida($cozinheira);
        $item = $execucao->itens()->first();
        $this->servico()->reprovar($execucao, $this->gerente(), 'Foto da bancada ilegível.');

        try {
            $this->servico()->responder($item, ['valor' => false], $cozinheira);
            $this->fail('Era esperada uma ChecklistException.');
        } catch (ChecklistException $e) {
            $this->assertSame('Este checklist foi reprovado. Reabra-o para corrigir.', $e->getMessage());
        }

        $reaberta = $this->servico()->reabrir($execucao, $cozinheira);

        $this->assertSame(ExecucaoStatus::EmAndamento, $reaberta->status);
        $this->assertSame('Foto da bancada ilegível.', $reaberta->motivo_reprovacao);
        $this->assertNull($reaberta->concluido_em);
        $this->assertTrue($this->servico()->responder($item, ['valor' => true], $cozinheira)->conforme);
    }

    public function test_corrigir_item_apos_reabrir_cancela_a_nao_conformidade_dele(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $cozinheira = $this->cozinheira();
        $execucao = $this->servico()->iniciar($this->modeloCozinha(), $cozinheira);
        $item = $execucao->itens()->first();
        $this->servico()->responder($item, ['valor' => false, 'observacao' => 'Forno não acendeu.'], $cozinheira);
        $this->servico()->finalizar($execucao, $cozinheira);
        $this->servico()->reprovar($execucao, $this->gerente(), 'Verificar o forno de novo.');
        $this->servico()->reabrir($execucao, $cozinheira);
        $this->servico()->responder($item, ['valor' => true], $cozinheira);

        $finalizada = $this->servico()->finalizar($execucao, $cozinheira);

        $this->assertSame(ExecucaoStatus::Concluido, $finalizada->status);
        $nc = ChecklistNaoConformidade::sole();
        $this->assertSame(NaoConformidadeStatus::Cancelada, $nc->status);
        $this->assertSame('Item corrigido antes da finalização.', $nc->observacao_resolucao);
    }

    public function test_anexar_foto_responde_item_do_tipo_foto(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        Storage::fake('public');
        $cozinheira = $this->cozinheira();
        $modelo = ChecklistModelo::factory()->paraPapeis(['Cozinha'])->create();
        ChecklistModeloItem::factory()->for($modelo, 'modelo')->foto()->create();
        $item = $this->servico()->iniciar($modelo, $cozinheira)->itens()->first();

        $anexo = $this->servico()->anexarFoto($item, UploadedFile::fake()->image('bancada.jpg'), $cozinheira);

        Storage::disk('public')->assertExists($anexo->caminho);
        $item->refresh();
        $this->assertTrue($item->conforme);
        $this->assertSame($cozinheira->id, $item->respondido_por);
        $this->assertSame(1, $item->execucao->itens_respondidos);
    }

    public function test_anexar_arquivo_que_nao_e_imagem_e_recusado_sem_gravar_nada(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        Storage::fake('public');
        $cozinheira = $this->cozinheira();
        $item = $this->servico()->iniciar($this->modeloCozinha(), $cozinheira)->itens()->first();

        try {
            $this->servico()->anexarFoto($item, UploadedFile::fake()->create('nota.pdf', 10, 'application/pdf'), $cozinheira);
            $this->fail('Era esperada uma ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame(['foto' => ['O arquivo enviado não é uma imagem.']], $e->errors());
        }

        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame(0, $item->anexos()->count());
    }

    public function test_expirar_marca_nao_realizado_depois_da_janela_e_da_tolerancia_mantendo_as_respostas(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        Notification::fake();
        $gerente = User::factory()->gerente()->create(['name_first' => 'Gerente']);
        $cozinheira = $this->cozinheira();
        $modelo = ChecklistModelo::factory()->janela('16:00:00', '18:30:00')->comItens(2)->paraPapeis(['Cozinha'])->create();
        $execucao = $this->servico()->iniciar($modelo, $cozinheira);
        $this->servico()->responder($execucao->itens()->first(), ['valor' => true], $cozinheira);

        $expiradas = $this->servico()->expirarForaDaJanela(now()->setTime(19, 1));

        $this->assertSame(1, $expiradas);
        $execucao->refresh();
        $this->assertSame(ExecucaoStatus::NaoRealizado, $execucao->status);
        $this->assertSame(1, $execucao->itens_respondidos);
        Notification::assertSentTo($gerente, ChecklistNaoRealizadoNotification::class);
    }

    public function test_expirar_respeita_a_tolerancia_depois_do_fim_da_janela(): void
    {
        $this->travelTo('2026-10-13 16:10:00');
        $modelo = ChecklistModelo::factory()->janela('16:00:00', '18:30:00')->comItens(1)->paraPapeis(['Cozinha'])->create();
        $execucao = $this->servico()->iniciar($modelo, $this->cozinheira());

        $expiradas = $this->servico()->expirarForaDaJanela(now()->setTime(18, 59));

        $this->assertSame(0, $expiradas);
        $this->assertSame(ExecucaoStatus::EmAndamento, $execucao->fresh()->status);
    }

    public function test_expirar_fechamento_que_cruza_a_meia_noite_so_no_dia_seguinte(): void
    {
        $this->travelTo('2026-10-10 23:00:00');
        $modelo = ChecklistModelo::factory()->janela('22:30:00', '01:30:00')->comItens(1)->paraPapeis(['Cozinha'])->create();
        $execucao = $this->servico()->iniciar($modelo, $this->cozinheira());

        $antes = $this->servico()->expirarForaDaJanela(now()->setTime(23, 59));
        $depois = $this->servico()->expirarForaDaJanela(now()->addDay()->setTime(2, 1));

        $this->assertSame(0, $antes);
        $this->assertSame(1, $depois);
        $this->assertSame(ExecucaoStatus::NaoRealizado, $execucao->fresh()->status);
    }

    public function test_gerar_pendentes_cria_so_para_modelos_ativos_agendados_no_dia_e_nao_duplica(): void
    {
        $this->travelTo('2026-10-13 05:05:00');
        $terca = ChecklistModelo::factory()->dias([2])->comItens(2)->create(['nome' => 'Terça']);
        ChecklistModelo::factory()->dias([1])->comItens(1)->create(['nome' => 'Segunda']);
        ChecklistModelo::factory()->inativo()->comItens(1)->create(['nome' => 'Inativo']);
        ChecklistModelo::factory()->sobDemanda()->comItens(1)->create(['nome' => 'Sob demanda']);

        $criadas = $this->servico()->gerarPendentesDoDia(today());
        $repetidas = $this->servico()->gerarPendentesDoDia(today());

        $this->assertSame(1, $criadas);
        $this->assertSame(0, $repetidas);
        $execucao = ChecklistExecucao::sole();
        $this->assertSame($terca->id, $execucao->checklist_modelo_id);
        $this->assertSame(ExecucaoStatus::Pendente, $execucao->status);
        $this->assertNull($execucao->iniciado_por);
        $this->assertSame(2, $execucao->total_itens);
    }

    private function servico(): ChecklistExecucaoService
    {
        return app(ChecklistExecucaoService::class);
    }

    private function cozinheira(): User
    {
        return User::factory()->cozinha()->create(['name_first' => 'Ana']);
    }

    private function gerente(): User
    {
        return User::factory()->gerente()->create(['name_first' => 'Gerente']);
    }

    /** Modelo da cozinha com um item "check" obrigatório. */
    private function modeloCozinha(): ChecklistModelo
    {
        return ChecklistModelo::factory()->comItens(1)->paraPapeis(['Cozinha'])->create();
    }

    /** @param  array<string, mixed>  $atributos */
    private function itemDoModelo(ChecklistModelo $modelo, array $atributos = []): ChecklistModeloItem
    {
        return ChecklistModeloItem::factory()->for($modelo, 'modelo')->create($atributos);
    }

    private function execucaoConcluida(?User $cozinheira = null): ChecklistExecucao
    {
        $cozinheira ??= $this->cozinheira();
        $execucao = $this->servico()->iniciar($this->modeloCozinha(), $cozinheira);
        $this->servico()->responder($execucao->itens()->first(), ['valor' => true], $cozinheira);

        return $this->servico()->finalizar($execucao, $cozinheira);
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
