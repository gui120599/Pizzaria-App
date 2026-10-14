<?php

namespace App\Services\Cozinha;

use App\Enums\Checklist\ChecklistTipo;
use App\Enums\Checklist\ExecucaoStatus;
use App\Enums\Cozinha\AcaoProducao;
use App\Enums\Cozinha\MotivoCancelamentoProducao;
use App\Enums\Cozinha\MotivoRefacao;
use App\Enums\Cozinha\PrioridadeProducao;
use App\Enums\Cozinha\StatusItemProducao;
use App\Enums\Cozinha\StatusProducaoPedido;
use App\Exceptions\ProducaoException;
use App\Models\ChecklistExecucao;
use App\Models\ChecklistModelo;
use App\Models\CozinhaHistorico;
use App\Models\DispositivoCozinha;
use App\Models\Pedido;
use App\Models\ProducaoEvento;
use App\Models\ProducaoItem;
use App\Models\SetorProducao;
use App\Models\User;
use App\Notifications\Cozinha\ItemCanceladoEmPreparoNotification;
use App\Support\Cozinha\Operador;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Todas as transições dos itens de produção (spec §3.3, §5.2). Cada uma roda
 * em DB::transaction com o pedido e o item travados (sempre nessa ordem, a
 * mesma do RoteadorProducao), valida a máquina de estados e a `versao` vista
 * pelo tablet, grava o evento, recalcula o pedido e avisa as telas.
 */
class ProducaoService
{
    public const EVENTO_LIBERACAO_SEM_ABERTURA = 'liberacao_sem_abertura';

    public function __construct(
        private SincronizadorPedidoProducao $sincronizador,
        private PedidoStatusGateway $gateway,
        private CozinhaTempoReal $tempoReal,
    ) {}

    public function iniciar(ProducaoItem $item, ?Operador $operador = null, ?int $versao = null, ?DispositivoCozinha $dispositivo = null): ProducaoItem
    {
        return $this->transitar($item, StatusItemProducao::EmPreparo, AcaoProducao::Iniciado, $versao, $operador, $dispositivo, function (ProducaoItem $linha) use ($operador): void {
            if ($linha->status !== StatusItemProducao::NaFila) {
                throw ProducaoException::transicaoInvalida($linha->status, StatusItemProducao::EmPreparo);
            }

            $this->garantirOperador($linha, $operador);
            $this->garantirAberturaDoSetor($linha->setor);

            $linha->fill([
                'iniciado_em' => now(),
                'iniciado_por' => auth()->id(),
                'operador_id' => $operador?->userId,
                'operador_nome' => $operador?->nome,
            ]);
        });
    }

    /** Pronto: de "em preparo", ou direto da fila se o setor permite pular o início. */
    public function concluir(ProducaoItem $item, ?Operador $operador = null, ?int $versao = null, ?DispositivoCozinha $dispositivo = null): ProducaoItem
    {
        return $this->transitar($item, StatusItemProducao::Pronto, AcaoProducao::Concluido, $versao, $operador, $dispositivo, function (ProducaoItem $linha) use ($operador): void {
            if ($linha->status === StatusItemProducao::NaFila) {
                if (! $linha->setor->permite_pular_inicio) {
                    throw ProducaoException::transicaoInvalida($linha->status, StatusItemProducao::Pronto);
                }

                $this->garantirOperador($linha, $operador);
                $linha->fill([
                    'iniciado_em' => now(),
                    'iniciado_por' => auth()->id(),
                    'operador_id' => $operador?->userId,
                    'operador_nome' => $operador?->nome,
                ]);
            }

            $linha->fill(['concluido_em' => now(), 'concluido_por' => auth()->id()]);
        }, depois: fn (ProducaoItem $linha) => $this->liberarSeguintes($linha));
    }

    /** Iniciou por engano: volta para a fila. */
    public function devolverParaFila(ProducaoItem $item, ?Operador $operador = null, ?string $motivo = null, ?int $versao = null, ?DispositivoCozinha $dispositivo = null): ProducaoItem
    {
        return $this->transitar($item, StatusItemProducao::NaFila, AcaoProducao::Devolvido, $versao, $operador, $dispositivo, function (ProducaoItem $linha): void {
            if ($linha->status !== StatusItemProducao::EmPreparo) {
                throw ProducaoException::transicaoInvalida($linha->status, StatusItemProducao::NaFila);
            }

            $linha->fill(['iniciado_em' => null, 'iniciado_por' => null, 'operador_id' => null, 'operador_nome' => null]);
        }, motivo: $motivo);
    }

    /**
     * Desfazer / recall: pronto volta ao preparo (até expedir). Em etapa
     * intermediária, só se a etapa seguinte ainda não começou — ela volta a
     * aguardar.
     */
    public function desfazer(ProducaoItem $item, ?Operador $operador = null, ?int $versao = null, ?DispositivoCozinha $dispositivo = null): ProducaoItem
    {
        return $this->transitar($item, StatusItemProducao::EmPreparo, AcaoProducao::Desfeito, $versao, $operador, $dispositivo, function (ProducaoItem $linha): void {
            if ($linha->status !== StatusItemProducao::Pronto) {
                throw ProducaoException::transicaoInvalida($linha->status, StatusItemProducao::EmPreparo);
            }

            $seguintes = $this->seguintes($linha);

            if ($seguintes->contains(fn (ProducaoItem $s) => ! in_array($s->status, [StatusItemProducao::Aguardando, StatusItemProducao::NaFila], true))) {
                throw new ProducaoException('A próxima etapa deste item já começou.');
            }

            foreach ($seguintes->where('status', StatusItemProducao::NaFila) as $seguinte) {
                $seguinte->fill(['status' => StatusItemProducao::Aguardando, 'liberado_em' => null, 'versao' => $seguinte->versao + 1])->save();
                $this->registrarEvento($seguinte, AcaoProducao::Devolvido, StatusItemProducao::NaFila, StatusItemProducao::Aguardando, motivo: 'etapa anterior desfeita');
            }

            $linha->fill(['concluido_em' => null, 'concluido_por' => null]);
        });
    }

    /** "Iniciar pedido" no modo por pedido: todos os itens do setor que estão na fila. */
    public function iniciarPedidoNoSetor(Pedido $pedido, SetorProducao $setor, ?Operador $operador = null, ?DispositivoCozinha $dispositivo = null): Collection
    {
        return ProducaoItem::where('pedido_id', $pedido->id)
            ->doSetor($setor->id)
            ->naFila()
            ->get()
            ->map(fn (ProducaoItem $linha) => $this->iniciar($linha, $operador, dispositivo: $dispositivo));
    }

    /** Aguardando → fila (etapa anterior pronta, horário do disparo atingido ou "liberar agora"). */
    public function liberar(ProducaoItem $item, ?string $motivo = null): ProducaoItem
    {
        return $this->transitar($item, StatusItemProducao::NaFila, AcaoProducao::Liberado, null, null, null, function (ProducaoItem $linha): void {
            if ($linha->status !== StatusItemProducao::Aguardando) {
                throw ProducaoException::transicaoInvalida($linha->status, StatusItemProducao::NaFila);
            }

            $linha->fill(['liberado_em' => now(), 'liberar_em' => null]);
        }, motivo: $motivo);
    }

    /** Disparo por tempo de preparo: libera os itens cujo horário chegou. */
    public function liberarVencidos(CarbonInterface $agora): int
    {
        $vencidos = ProducaoItem::where('status', StatusItemProducao::Aguardando)
            ->where('etapa', 1)
            ->whereNotNull('liberar_em')
            ->where('liberar_em', '<=', $agora)
            ->get();

        foreach ($vencidos as $linha) {
            $this->liberar($linha, 'disparo por tempo de preparo');
        }

        return $vencidos->count();
    }

    /**
     * "Liberar agora" do Painel de Pedidos: solta na fila os itens do pedido
     * que o disparo por tempo de preparo ainda segurava (só a 1ª etapa; as
     * seguintes esperam a anterior ficar pronta).
     */
    public function liberarPedido(Pedido $pedido): int
    {
        $segurados = $this->seguradosDoPedido($pedido);

        foreach ($segurados as $linha) {
            $this->liberar($linha, 'liberado pelo painel de pedidos');
        }

        return $segurados->count();
    }

    /** @return Collection<int, ProducaoItem> */
    public function seguradosDoPedido(Pedido $pedido): Collection
    {
        return ProducaoItem::where('pedido_id', $pedido->id)
            ->where('status', StatusItemProducao::Aguardando)
            ->where('etapa', 1)
            ->get();
    }

    /**
     * Cancela a linha. Se já tinha começado, o tablet mostra "CANCELADO — NÃO
     * PRODUZIR" até alguém tocar "Ciente", e o gerente é avisado.
     */
    public function cancelar(ProducaoItem $item, MotivoCancelamentoProducao $motivo, string $origem = 'sistema', bool $recalcular = true): ProducaoItem
    {
        $comecado = false;

        $linha = $this->transitar($item, StatusItemProducao::Cancelado, AcaoProducao::Cancelado, null, null, null, function (ProducaoItem $linha) use ($motivo, &$comecado): void {
            $comecado = $linha->iniciado_em !== null;
            $linha->fill([
                'cancelado_em' => now(),
                'motivo_cancelamento' => $motivo,
                // Nunca chegou a ser feito: não há o que avisar no tablet.
                'cancelamento_ciente_em' => $linha->iniciado_em === null ? now() : null,
            ]);
        }, motivo: $motivo->getLabel(), dados: ['origem' => $origem], recalcular: $recalcular);

        if ($comecado) {
            DB::afterCommit(fn () => Notification::send(self::gestores(), new ItemCanceladoEmPreparoNotification($linha)));
        }

        return $linha;
    }

    /** "Ciente" no card cancelado ou alterado: o card sai da tela. */
    public function confirmarCiencia(ProducaoItem $item, ?Operador $operador = null, ?DispositivoCozinha $dispositivo = null): ProducaoItem
    {
        return DB::transaction(function () use ($item, $operador, $dispositivo): ProducaoItem {
            [, $linha] = $this->travar($item);

            if ($linha->status === StatusItemProducao::Cancelado && $linha->cancelamento_ciente_em === null) {
                $linha->cancelamento_ciente_em = now();
                $acao = AcaoProducao::CienciaCancelamento;
            } elseif ($linha->alterado_apos_inicio_em !== null && $linha->alteracao_ciente_em === null) {
                $linha->alteracao_ciente_em = now();
                $acao = AcaoProducao::CienciaAlteracao;
            } else {
                return $linha;
            }

            $linha->versao++;
            $linha->save();
            $this->registrarEvento($linha, $acao, $linha->status, $linha->status, $operador, $dispositivo);
            $this->tempoReal->tocar([$linha->setor_producao_id], $linha->pedido_id);

            return $linha;
        });
    }

    /**
     * Item mudou no pedido (RoteadorProducao): troca o snapshot/quantidade.
     * Depois de iniciado, o card fica ALTERADO até a ciência.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function registrarAlteracao(ProducaoItem $linha, array $snapshot, float $quantidade, bool $exigeCiencia): void
    {
        $antes = $linha->snapshot;
        $linha->fill([
            'snapshot' => $snapshot,
            'quantidade' => $quantidade,
            'alterado_apos_inicio_em' => $exigeCiencia ? now() : $linha->alterado_apos_inicio_em,
            'alteracao_ciente_em' => $exigeCiencia ? null : $linha->alteracao_ciente_em,
            'versao' => $linha->versao + 1,
        ])->save();

        $this->registrarEvento($linha, AcaoProducao::Alterado, $linha->status, $linha->status, dados: [
            'exige_ciencia' => $exigeCiencia,
            'antes' => $antes['observacao'] ?? null,
            'depois' => $snapshot['observacao'] ?? null,
        ]);
    }

    /** "Urgente" no painel de pedidos ou na Expedição: todos os itens ativos do pedido sobem na fila. */
    public function priorizar(Pedido $pedido, PrioridadeProducao $prioridade): void
    {
        DB::transaction(function () use ($pedido, $prioridade): void {
            $pedido = Pedido::whereKey($pedido->id)->lockForUpdate()->firstOrFail();
            Pedido::whereKey($pedido->id)->update(['pedido_prioridade_producao' => $prioridade->value]);
            $setores = [];

            foreach (ProducaoItem::where('pedido_id', $pedido->id)->ativos()->lockForUpdate()->get() as $linha) {
                if ($linha->prioridade === $prioridade) {
                    continue;
                }

                $linha->fill(['prioridade' => $prioridade, 'versao' => $linha->versao + 1])->save();
                $this->registrarEvento($linha, AcaoProducao::Priorizado, $linha->status, $linha->status, dados: ['prioridade' => $prioridade->value]);
                $setores[] = $linha->setor_producao_id;
            }

            $this->tempoReal->tocar($setores, $pedido->id);
        });
    }

    /**
     * Refazer (Expedição/admin): a linha antiga é cancelada com motivo
     * "refeito" e a unidade recomeça da etapa 1 numa nova geração, urgente.
     * Pedido já despachado não retrocede (o gateway nunca volta status).
     */
    public function refazer(ProducaoItem $item, MotivoRefacao $motivo, User $user, ?string $observacao = null): ProducaoItem
    {
        return DB::transaction(function () use ($item, $motivo, $user, $observacao): ProducaoItem {
            [$pedido, $linha] = $this->travar($item);

            if (! $linha->ehUltimaEtapa() || ! in_array($linha->status, [StatusItemProducao::Pronto, StatusItemProducao::Expedido], true)) {
                throw new ProducaoException('Só dá para refazer um item pronto ou já despachado.');
            }

            $daUnidade = ProducaoItem::where('item_pedido_id', $linha->item_pedido_id)
                ->where('unidade_seq', $linha->unidade_seq)
                ->get();
            $geracao = (int) $daUnidade->max('geracao') + 1;
            $modelos = $daUnidade->where('geracao', $linha->geracao)->sortBy('etapa');
            $snapshot = $linha->snapshot;
            unset($snapshot['antes']);
            $primeira = null;

            foreach ($modelos as $modelo) {
                $nova = ProducaoItem::create([
                    ...$modelo->only(['pedido_id', 'item_pedido_id', 'setor_producao_id', 'etapa', 'etapa_total', 'unidade_seq', 'quantidade', 'origem', 'referencia', 'ficha_tecnica_id', 'ficha_versao', 'tempo_previsto_min']),
                    'geracao' => $geracao,
                    'status' => $modelo->etapa === 1 ? StatusItemProducao::NaFila : StatusItemProducao::Aguardando,
                    'prioridade' => PrioridadeProducao::Urgente,
                    'snapshot' => $snapshot,
                    'liberado_em' => $modelo->etapa === 1 ? now() : null,
                    'refeito_de_id' => $linha->id,
                    'motivo_refacao' => $motivo,
                ]);
                $this->registrarEvento($nova, AcaoProducao::Gerado, null, $nova->status, motivo: 'refazer', dados: ['refeito_de_id' => $linha->id]);
                $primeira ??= $nova;
            }

            $de = $linha->status;
            $linha->fill([
                'status' => StatusItemProducao::Cancelado,
                'cancelado_em' => now(),
                'motivo_cancelamento' => MotivoCancelamentoProducao::Refeito,
                'cancelamento_ciente_em' => now(),
                'motivo_refacao' => $motivo,
                'versao' => $linha->versao + 1,
            ])->save();
            $this->registrarEvento($linha, AcaoProducao::Refeito, $de, StatusItemProducao::Cancelado, motivo: $observacao ?: $motivo->getLabel(), dados: ['motivo' => $motivo->value], userId: $user->id);

            $this->sincronizador->recalcular($pedido);
            $this->tempoReal->tocar($modelos->pluck('setor_producao_id')->all(), $pedido->id);

            return $primeira;
        });
    }

    /**
     * Despacho na Expedição (ou no painel de pedidos): itens prontos viram
     * expedidos e o pedido segue para ao_expedir do tipo de atendimento.
     */
    public function expedirPedido(Pedido $pedido, ?User $user = null): void
    {
        DB::transaction(function () use ($pedido, $user): void {
            $pedido = Pedido::whereKey($pedido->id)->lockForUpdate()->firstOrFail();
            $linhas = ProducaoItem::where('pedido_id', $pedido->id)->lockForUpdate()->get();
            $status = $this->sincronizador->statusDoPedido($pedido, $linhas);

            if (! in_array($status, [StatusProducaoPedido::Pronto, StatusProducaoPedido::SemProducao, StatusProducaoPedido::Expedido], true)) {
                throw ProducaoException::pedidoComItensEmProducao($this->pendentes($linhas));
            }

            foreach ($linhas->where('status', StatusItemProducao::Pronto)->filter(fn (ProducaoItem $l) => $l->ehUltimaEtapa()) as $linha) {
                $linha->fill(['status' => StatusItemProducao::Expedido, 'expedido_em' => now(), 'versao' => $linha->versao + 1])->save();
                $this->registrarEvento($linha, AcaoProducao::Expedido, StatusItemProducao::Pronto, StatusItemProducao::Expedido, userId: $user?->id);
            }

            $this->gateway->marcarExpedido($pedido);
            $this->sincronizador->recalcular($pedido->refresh());

            if (config('cozinha.usa_expedicao')) {
                $this->sincronizador->avisarGarcom($pedido);
            }

            $this->tempoReal->tocar($linhas->pluck('setor_producao_id')->all(), $pedido->id);
        });
    }

    /**
     * O pedido foi marcado pronto/saiu por fora da cozinha (painel de
     * pedidos, entregador, caixa): os itens pendentes acompanham, com o
     * evento `forcado_pelo_painel`. $expedir = o pedido já saiu.
     */
    public function forcarPeloPainel(Pedido $pedido, bool $expedir, ?User $user = null): int
    {
        return DB::transaction(function () use ($pedido, $expedir, $user): int {
            $pedido = Pedido::whereKey($pedido->id)->lockForUpdate()->firstOrFail();
            $linhas = ProducaoItem::where('pedido_id', $pedido->id)->ativos()->lockForUpdate()->get();
            $forcados = 0;

            foreach ($linhas as $linha) {
                $para = $expedir && $linha->ehUltimaEtapa() ? StatusItemProducao::Expedido : StatusItemProducao::Pronto;

                if ($linha->status === $para) {
                    continue;
                }

                $de = $linha->status;
                $linha->fill([
                    'status' => $para,
                    'liberado_em' => $linha->liberado_em ?? now(),
                    'concluido_em' => $linha->concluido_em ?? now(),
                    'expedido_em' => $para === StatusItemProducao::Expedido ? now() : null,
                    'versao' => $linha->versao + 1,
                ])->save();
                $this->registrarEvento($linha, AcaoProducao::ForcadoPeloPainel, $de, $para, userId: $user?->id ?? auth()->id());
                $forcados++;
            }

            if ($forcados > 0) {
                $this->sincronizador->recalcular($pedido);
                $this->tempoReal->tocar($linhas->pluck('setor_producao_id')->all(), $pedido->id);
            }

            return $forcados;
        });
    }

    /** Quantos itens ainda estão em produção (para o modal "há 2 itens em produção" do admin). */
    public function pendentesDoPedido(Pedido $pedido): int
    {
        return $this->pendentes(ProducaoItem::where('pedido_id', $pedido->id)->get());
    }

    /**
     * Gerente/admin libera o setor a produzir sem o checklist de abertura,
     * até o fim do dia operacional (bloqueio de config
     * cozinha.checklists.bloquear_producao_sem_abertura).
     */
    public function liberarSemAbertura(SetorProducao $setor, User $user, string $justificativa): CozinhaHistorico
    {
        $justificativa = trim($justificativa);

        if ($justificativa === '') {
            throw new ProducaoException('Informe a justificativa da liberação.');
        }

        return CozinhaHistorico::registrar($setor, self::EVENTO_LIBERACAO_SEM_ABERTURA, [
            'data_referencia' => ChecklistExecucao::dataOperacional()->toDateString(),
            'justificativa' => $justificativa,
        ], $user->id);
    }

    /** O setor está bloqueado por falta do checklist de abertura do dia? */
    public function aberturaPendente(SetorProducao $setor): bool
    {
        if (! config('cozinha.checklists.bloquear_producao_sem_abertura')) {
            return false;
        }

        $dia = ChecklistExecucao::dataOperacional();
        $modelos = ChecklistModelo::ativos()
            ->agendadosPara($dia)
            ->paraPainel(ChecklistModelo::PAINEL_COZINHA, new DispositivoCozinha(['setores' => [$setor->id]]))
            ->where('tipo', ChecklistTipo::Abertura)
            ->pluck('id');

        if ($modelos->isEmpty()) {
            return false;
        }

        $concluidos = ChecklistExecucao::whereIn('checklist_modelo_id', $modelos)
            ->doDia($dia)
            ->whereIn('status', [ExecucaoStatus::Concluido, ExecucaoStatus::ConcluidoComPendencias, ExecucaoStatus::Aprovado])
            ->distinct()
            ->count('checklist_modelo_id');

        if ($concluidos >= $modelos->count()) {
            return false;
        }

        return ! $setor->historicos()
            ->where('evento', self::EVENTO_LIBERACAO_SEM_ABERTURA)
            ->where('dados->data_referencia', $dia->toDateString())
            ->exists();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, User> */
    public static function gestores(): \Illuminate\Database\Eloquent\Collection
    {
        return User::whereHas('roles', fn ($roles) => $roles->whereIn('name', ['Admin', 'Gerente']))->get();
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param  Closure(ProducaoItem): void  $preparar  valida e preenche a linha (status atual ainda é o de origem)
     * @param  (Closure(ProducaoItem): void)|null  $depois  roda já com o novo status gravado
     * @param  array<string, mixed>|null  $dados
     */
    private function transitar(
        ProducaoItem $item,
        StatusItemProducao $para,
        AcaoProducao $acao,
        ?int $versao,
        ?Operador $operador,
        ?DispositivoCozinha $dispositivo,
        Closure $preparar,
        ?Closure $depois = null,
        ?string $motivo = null,
        ?array $dados = null,
        bool $recalcular = true,
    ): ProducaoItem {
        return DB::transaction(function () use ($item, $para, $acao, $versao, $operador, $dispositivo, $preparar, $depois, $motivo, $dados, $recalcular): ProducaoItem {
            [$pedido, $linha] = $this->travar($item);

            if ($versao !== null && $versao !== $linha->versao) {
                throw ProducaoException::versaoDesatualizada();
            }

            if (! $linha->podeTransitarPara($para)) {
                throw ProducaoException::transicaoInvalida($linha->status, $para);
            }

            $de = $linha->status;
            $preparar($linha);
            $linha->status = $para;
            $linha->versao++;
            $linha->save();

            $this->registrarEvento($linha, $acao, $de, $para, $operador, $dispositivo, $motivo, $dados);

            if ($depois !== null) {
                $depois($linha);
            }

            if ($recalcular) {
                $this->sincronizador->recalcular($pedido);
            }

            $this->tempoReal->tocar([$linha->setor_producao_id, ...$this->seguintes($linha)->pluck('setor_producao_id')->all()], $pedido->id);

            return $linha;
        });
    }

    /** @return array{0: Pedido, 1: ProducaoItem} */
    private function travar(ProducaoItem $item): array
    {
        $pedido = Pedido::whereKey($item->pedido_id)->lockForUpdate()->firstOrFail();
        $linha = ProducaoItem::with('setor')->whereKey($item->id)->lockForUpdate()->firstOrFail();

        return [$pedido, $linha];
    }

    /**
     * Linhas da etapa seguinte que dependem desta: a mesma unidade/geração
     * quando existe; senão (setor seguinte não explode unidades) todas.
     *
     * @return Collection<int, ProducaoItem>
     */
    private function seguintes(ProducaoItem $linha): Collection
    {
        if ($linha->ehUltimaEtapa()) {
            return collect();
        }

        $proximas = ProducaoItem::where('item_pedido_id', $linha->item_pedido_id)
            ->where('etapa', $linha->etapa + 1)
            ->where('status', '!=', StatusItemProducao::Cancelado)
            ->get();

        $mesmaUnidade = $proximas->where('unidade_seq', $linha->unidade_seq)->where('geracao', $linha->geracao);

        return $mesmaUnidade->isNotEmpty() ? $mesmaUnidade->values() : $proximas;
    }

    /** Concluir uma etapa libera a seguinte quando todas as linhas de que ela depende estão prontas. */
    private function liberarSeguintes(ProducaoItem $linha): void
    {
        foreach ($this->seguintes($linha)->where('status', StatusItemProducao::Aguardando) as $seguinte) {
            $anteriores = ProducaoItem::where('item_pedido_id', $linha->item_pedido_id)
                ->where('etapa', $linha->etapa)
                ->where('status', '!=', StatusItemProducao::Cancelado)
                ->get();
            $correspondente = $anteriores->where('unidade_seq', $seguinte->unidade_seq)->where('geracao', $seguinte->geracao);
            $exigidas = $correspondente->isNotEmpty() ? $correspondente : $anteriores;

            if ($exigidas->every(fn (ProducaoItem $a) => $a->status === StatusItemProducao::Pronto)) {
                $seguinte->fill(['status' => StatusItemProducao::NaFila, 'liberado_em' => now(), 'versao' => $seguinte->versao + 1])->save();
                $this->registrarEvento($seguinte, AcaoProducao::Liberado, StatusItemProducao::Aguardando, StatusItemProducao::NaFila, motivo: 'etapa anterior pronta');
            }
        }
    }

    private function garantirOperador(ProducaoItem $linha, ?Operador $operador): void
    {
        if ($linha->setor->exige_operador && $operador === null) {
            throw ProducaoException::operadorObrigatorio();
        }
    }

    private function garantirAberturaDoSetor(SetorProducao $setor): void
    {
        if ($this->aberturaPendente($setor)) {
            throw ProducaoException::semAberturaDoSetor($setor->nome);
        }
    }

    /** @param  Collection<int, ProducaoItem>  $linhas */
    private function pendentes(Collection $linhas): int
    {
        return $linhas
            ->filter(fn (ProducaoItem $l) => $l->ehUltimaEtapa() && in_array($l->status, [StatusItemProducao::Aguardando, StatusItemProducao::NaFila, StatusItemProducao::EmPreparo], true))
            ->count();
    }

    /** @param  array<string, mixed>|null  $dados */
    private function registrarEvento(
        ProducaoItem $linha,
        AcaoProducao $acao,
        ?StatusItemProducao $de,
        ?StatusItemProducao $para,
        ?Operador $operador = null,
        ?DispositivoCozinha $dispositivo = null,
        ?string $motivo = null,
        ?array $dados = null,
        ?int $userId = null,
    ): ProducaoEvento {
        return ProducaoEvento::create([
            'producao_item_id' => $linha->id,
            'pedido_id' => $linha->pedido_id,
            'setor_producao_id' => $linha->setor_producao_id,
            'acao' => $acao,
            'de_status' => $de,
            'para_status' => $para,
            'user_id' => $userId ?? auth()->id(),
            'operador_id' => $operador?->userId,
            'dispositivo_cozinha_id' => $dispositivo?->id,
            'motivo' => $motivo,
            'dados' => $dados,
        ]);
    }
}
