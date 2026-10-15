<?php

namespace App\Services\Ponto;

use App\Enums\Ponto\NaturezaAdiantamento;
use App\Enums\Ponto\NaturezaItem;
use App\Enums\Ponto\StatusAdiantamento;
use App\Enums\Ponto\StatusPeriodo;
use App\Enums\Ponto\StatusRepasse;
use App\Enums\Ponto\VinculoColaborador;
use App\Enums\StatusLancamento;
use App\Exceptions\PontoException;
use App\Models\Adiantamento;
use App\Models\Colaborador;
use App\Models\ColaboradorRemuneracao;
use App\Models\Lancamento;
use App\Models\PontoDia;
use App\Models\PontoHistorico;
use App\Models\PontoPeriodo;
use App\Models\Repasse;
use App\Models\User;
use App\Notifications\Ponto\RepasseDisponivelNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Repasse do colaborador no período: cálculo dos itens, aprovação (gera 1
 * título no contas a pagar), cancelamento e sincronização com a baixa
 * (spec §5.4/§5.5). Toda escrita em transação com lock no repasse.
 */
class RepasseService
{
    private const MOTIVO_MINIMO = 10;

    public function __construct(
        private readonly CalculadoraRepasse $calculadora,
        private readonly LancamentoGateway $lancamentos,
    ) {}

    /**
     * Um repasse por colaborador da periodicidade ativo no período. Os que
     * já existem em rascunho são recalculados; aprovados, pagos e cancelados
     * ficam como estão.
     *
     * @return Collection<int, Repasse>
     */
    public function gerarRepasses(PontoPeriodo $periodo): Collection
    {
        return Colaborador::query()->doPeriodo($periodo)->orderBy('nome')->get()
            ->map(function (Colaborador $colaborador) use ($periodo): Repasse {
                $repasse = Repasse::query()->firstOrCreate(
                    ['ponto_periodo_id' => $periodo->id, 'colaborador_id' => $colaborador->id],
                    ['status' => StatusRepasse::Rascunho],
                );

                if ($repasse->podeEditar()) {
                    $this->calcularItens($repasse);
                }

                return $repasse->refresh();
            });
    }

    /**
     * Recria os itens automáticos (jornada + adiantamentos abertos até o fim
     * do período) e recalcula os totais. Itens manuais ficam.
     *
     * @throws PontoException
     */
    public function calcularItens(Repasse $repasse): void
    {
        DB::transaction(function () use ($repasse): void {
            $repasse = Repasse::query()->lockForUpdate()->findOrFail($repasse->id);
            $this->garantirRascunho($repasse);

            $periodo = $repasse->periodo;
            $colaborador = $repasse->colaborador;
            $remuneracao = $this->remuneracaoDoPeriodo($colaborador, $periodo);
            $dias = PontoDia::query()
                ->where('colaborador_id', $colaborador->id)
                ->whereDate('data_referencia', '>=', $periodo->data_inicio->toDateString())
                ->whereDate('data_referencia', '<=', $periodo->data_fim->toDateString())
                ->get();

            $repasse->itens()->where('automatico', true)->delete();

            foreach ($this->calculadora->calcular($colaborador, $remuneracao, $periodo, $dias) as $item) {
                $repasse->itens()->create([...$item, 'automatico' => true]);
            }

            foreach ($colaborador->adiantamentos()->descontaveisAte($periodo->data_fim)->orderBy('data')->get() as $adiantamento) {
                $repasse->itens()->create([
                    'natureza' => $adiantamento->natureza === NaturezaAdiantamento::Consumo ? NaturezaItem::Consumo : NaturezaItem::Adiantamento,
                    'descricao' => "{$adiantamento->natureza->getLabel()} de {$adiantamento->data->format('d/m')}".(filled($adiantamento->descricao) ? " — {$adiantamento->descricao}" : ''),
                    'quantidade' => 1,
                    'valor_unitario' => $adiantamento->valor,
                    'valor' => $adiantamento->valor,
                    'automatico' => true,
                    'adiantamento_id' => $adiantamento->id,
                ]);
            }

            $repasse->forceFill([
                'remuneracao_snapshot' => $remuneracao->only([
                    'id', 'modalidade', 'valor_base', 'valor_por_entrega', 'carga_diaria_minutos', 'carga_semanal_minutos',
                    'percentual_extra_util', 'percentual_extra_domingo_feriado', 'percentual_adicional_noturno',
                    'considera_hora_noturna_reduzida',
                ]) + ['vigente_desde' => $remuneracao->vigente_desde->toDateString(), 'valor_hora' => $this->calculadora->valorHora($remuneracao)],
                'resumo_jornada' => $this->calculadora->resumir($dias),
            ]);
            $repasse->recalcularTotais();
        });
    }

    /**
     * Aprova o repasse e cria exatamente 1 título no contas a pagar com o
     * líquido; os adiantamentos descontados ficam ligados ao repasse. Líquido
     * zero (adiantamentos cobriram tudo) não gera título e o repasse já nasce
     * pago.
     *
     * @throws PontoException
     */
    public function aprovar(Repasse $repasse, User $usuario): ?Lancamento
    {
        $this->garantirPermissao($usuario, 'aprovar:repasse', 'aprovar repasses');

        $lancamento = DB::transaction(function () use ($repasse, $usuario): ?Lancamento {
            $repasse = Repasse::query()->lockForUpdate()->findOrFail($repasse->id);
            $this->garantirRascunho($repasse);

            $periodo = $repasse->periodo;
            $colaborador = $repasse->colaborador;
            $liquido = round((float) $repasse->valor_liquido, 2);

            if ($periodo->status !== StatusPeriodo::EmConferencia) {
                throw new PontoException('Repasse só é aprovado com o período em conferência. Inicie o fechamento.');
            }

            if ($liquido < 0) {
                throw new PontoException('O líquido está negativo. Transfira o saldo devedor para o próximo período antes de aprovar.');
            }

            if ($colaborador->vinculo === VinculoColaborador::Clt && ! $repasse->conferido_holerite) {
                throw new PontoException('Repasse de CLT só é aprovado depois de conferido com o holerite da contabilidade.');
            }

            $this->descontarAdiantamentos($repasse);

            $lancamento = $liquido > 0 ? $this->lancamentos->criarContaPagar(new ContaPagarDados(
                favorecidoId: $this->lancamentos->favorecidoDe($colaborador),
                planoDespesaId: $this->lancamentos->planoDe($colaborador),
                valor: $liquido,
                vencimento: $periodo->data_pagamento ?? $periodo->data_fim->copy()->addDays((int) config('ponto.dias_para_pagamento')),
                competencia: $periodo->data_fim,
                descricao: "Repasse {$periodo->rotulo()} – {$colaborador->nome}",
                observacoes: $this->lancamentos->observacaoPix($colaborador),
                formaPagamento: $this->lancamentos->formaPagamentoDe($colaborador),
                repasseId: $repasse->id,
            )) : null;

            $repasse->forceFill([
                'status' => $lancamento === null ? StatusRepasse::Pago : StatusRepasse::Aprovado,
                'aprovado_por' => $usuario->id,
                'aprovado_em' => now(),
                'pago_em' => $lancamento === null ? now() : null,
            ])->save();

            $this->atualizarStatusDoPeriodo($periodo);

            return $lancamento;
        });

        $repasse->refresh();
        $repasse->colaborador->user?->notify(new RepasseDisponivelNotification($repasse));

        return $lancamento;
    }

    /**
     * Volta o repasse aprovado para rascunho: cancela o título (só se não
     * tiver pagamento) e devolve os adiantamentos para "aberto".
     *
     * @throws PontoException
     */
    public function cancelarAprovacao(Repasse $repasse, User $usuario, string $motivo): void
    {
        $this->garantirPermissao($usuario, 'aprovar:repasse', 'cancelar a aprovação de repasses');
        self::garantirMotivo($motivo);

        DB::transaction(function () use ($repasse, $usuario, $motivo): void {
            $repasse = Repasse::query()->lockForUpdate()->findOrFail($repasse->id);

            if ($repasse->status !== StatusRepasse::Aprovado) {
                throw new PontoException($repasse->status === StatusRepasse::Pago
                    ? 'O repasse já foi pago. Para desfazer, estorne o pagamento no financeiro.'
                    : 'Só um repasse aprovado tem aprovação para cancelar.');
            }

            $lancamento = $repasse->lancamento()->first();

            if ($lancamento !== null && $this->lancamentos->temPagamento($lancamento)) {
                throw new PontoException('O título deste repasse já tem pagamento no financeiro. Estorne o pagamento antes de cancelar a aprovação.');
            }

            // O repasse volta primeiro: assim o aviso de "título cancelado" que
            // o financeiro dispara já encontra o repasse em rascunho.
            $this->voltarParaRascunho($repasse);

            if ($lancamento !== null) {
                $this->lancamentos->cancelar($lancamento, $motivo);
            }

            PontoHistorico::registrar($repasse, 'aprovacao_cancelada', ['motivo' => trim($motivo)], $usuario->id);
            $this->atualizarStatusDoPeriodo($repasse->periodo);
        });
    }

    /**
     * Rascunho que não será pago (ex.: colaborador sem nada a receber).
     *
     * @throws PontoException
     */
    public function cancelar(Repasse $repasse, User $usuario, string $motivo): void
    {
        $this->garantirPermissao($usuario, 'aprovar:repasse', 'cancelar repasses');
        self::garantirMotivo($motivo);

        DB::transaction(function () use ($repasse, $usuario, $motivo): void {
            $repasse = Repasse::query()->lockForUpdate()->findOrFail($repasse->id);
            $this->garantirRascunho($repasse);

            $repasse->forceFill(['status' => StatusRepasse::Cancelado, 'observacao' => trim($motivo)])->save();
            PontoHistorico::registrar($repasse, 'cancelado', ['motivo' => trim($motivo)], $usuario->id);
            $this->atualizarStatusDoPeriodo($repasse->periodo);
        });
    }

    /**
     * Líquido negativo: o saldo vira adiantamento "saldo devedor" aberto no
     * primeiro dia do próximo período, e um item manual zera este repasse.
     *
     * @throws PontoException
     */
    public function transferirSaldoDevedor(Repasse $repasse, User $usuario): Adiantamento
    {
        $this->garantirPermissao($usuario, 'aprovar:repasse', 'transferir saldo devedor');

        return DB::transaction(function () use ($repasse, $usuario): Adiantamento {
            $repasse = Repasse::query()->lockForUpdate()->findOrFail($repasse->id);
            $this->garantirRascunho($repasse);

            $saldo = round(-(float) $repasse->valor_liquido, 2);

            if ($saldo <= 0) {
                throw new PontoException('O líquido deste repasse não está negativo.');
            }

            $periodo = $repasse->periodo;
            $adiantamento = Adiantamento::create([
                'colaborador_id' => $repasse->colaborador_id,
                'data' => $periodo->data_fim->copy()->addDay()->toDateString(),
                'natureza' => NaturezaAdiantamento::SaldoDevedor,
                'valor' => $saldo,
                'descricao' => "Saldo devedor do repasse {$periodo->rotulo()}",
                'status' => StatusAdiantamento::Aberto,
                'registrado_por' => $usuario->id,
            ]);

            $repasse->itens()->create([
                'natureza' => NaturezaItem::OutrosProventos,
                'descricao' => 'Saldo devedor transferido para o próximo período',
                'quantidade' => 1,
                'valor_unitario' => $saldo,
                'valor' => $saldo,
                'automatico' => false,
            ]);
            $repasse->recalcularTotais();

            return $adiantamento;
        });
    }

    /**
     * "Li e confere" do próprio colaborador: grava quando e de qual IP, uma
     * vez só, em repasse aprovado ou pago.
     *
     * @throws PontoException
     */
    public function darCiencia(Repasse $repasse, User $usuario, ?string $ip): Repasse
    {
        return DB::transaction(function () use ($repasse, $usuario, $ip): Repasse {
            $repasse = Repasse::query()->with('colaborador')->lockForUpdate()->findOrFail($repasse->id);

            if ($repasse->colaborador->user_id !== $usuario->id) {
                throw new PontoException('Só o próprio colaborador dá ciência do repasse.');
            }

            if (! in_array($repasse->status, [StatusRepasse::Aprovado, StatusRepasse::Pago], true)) {
                throw new PontoException('Este repasse ainda não foi aprovado.');
            }

            if ($repasse->ciente_em !== null) {
                throw new PontoException("Você já deu ciência em {$repasse->ciente_em->format('d/m/Y H:i')}.");
            }

            $repasse->forceFill(['ciente_em' => now(), 'ciente_ip' => $ip])->save();

            return $repasse;
        });
    }

    /**
     * Chamado quando o status do título muda no financeiro (observer do
     * Lancamento): baixa → repasse pago; estorno → volta a aprovado; título
     * cancelado lá → repasse volta a rascunho. Por fim, o período acompanha.
     */
    public function sincronizarPagamento(Lancamento $lancamento): void
    {
        DB::transaction(function () use ($lancamento): void {
            $repasse = Repasse::query()->lockForUpdate()->find($lancamento->repasse_id);

            if ($repasse === null) {
                return;
            }

            $vigente = $repasse->lancamento()->first();

            if ($lancamento->status === StatusLancamento::Cancelado) {
                if ($vigente === null && $repasse->status === StatusRepasse::Aprovado) {
                    $this->voltarParaRascunho($repasse);
                }
            } elseif ($vigente?->is($lancamento)) {
                $pago = $this->lancamentos->estaPago($lancamento);

                if ($pago && $repasse->status === StatusRepasse::Aprovado) {
                    $repasse->forceFill(['status' => StatusRepasse::Pago, 'pago_em' => $lancamento->data_pagamento ?? now()])->save();
                } elseif (! $pago && $repasse->status === StatusRepasse::Pago) {
                    $repasse->forceFill(['status' => StatusRepasse::Aprovado, 'pago_em' => null])->save();
                }
            }

            $this->atualizarStatusDoPeriodo($repasse->periodo);
        });
    }

    /**
     * Em conferência → fechado quando todos os repasses foram aprovados ou
     * cancelados; fechado → pago quando todos os aprovados foram pagos (e
     * volta, se um pagamento for estornado).
     */
    public function atualizarStatusDoPeriodo(PontoPeriodo $periodo): void
    {
        $periodo = PontoPeriodo::query()->lockForUpdate()->findOrFail($periodo->id);

        if ($periodo->status === StatusPeriodo::Aberto) {
            return;
        }

        $repasses = $periodo->repasses()->get();
        $decididos = $repasses->every(fn (Repasse $r): bool => $r->status !== StatusRepasse::Rascunho);
        $ativos = $repasses->reject(fn (Repasse $r): bool => $r->status === StatusRepasse::Cancelado);
        $pagos = $ativos->isNotEmpty() && $ativos->every(fn (Repasse $r): bool => $r->status === StatusRepasse::Pago);

        $novo = match (true) {
            ! $decididos => StatusPeriodo::EmConferencia,
            $pagos => StatusPeriodo::Pago,
            default => StatusPeriodo::Fechado,
        };

        if ($novo === $periodo->status) {
            return;
        }

        $periodo->forceFill([
            'status' => $novo,
            'fechado_por' => $novo === StatusPeriodo::EmConferencia ? null : ($periodo->fechado_por ?? auth()->id()),
            'fechado_em' => $novo === StatusPeriodo::EmConferencia ? null : ($periodo->fechado_em ?? now()),
        ])->save();
    }

    /**
     * Vigência no último dia ativo do colaborador dentro do período.
     *
     * @throws PontoException
     */
    public function remuneracaoDoPeriodo(Colaborador $colaborador, PontoPeriodo $periodo): ColaboradorRemuneracao
    {
        $referencia = $colaborador->data_desligamento?->lt($periodo->data_fim)
            ? CarbonImmutable::instance($colaborador->data_desligamento)
            : CarbonImmutable::instance($periodo->data_fim);

        return $colaborador->remuneracaoVigente($referencia)
            ?? throw new PontoException("{$colaborador->nome} não tem remuneração vigente em {$referencia->format('d/m/Y')}.");
    }

    /** @throws PontoException */
    private function descontarAdiantamentos(Repasse $repasse): void
    {
        $ids = $repasse->itens()->whereNotNull('adiantamento_id')->pluck('adiantamento_id');
        $adiantamentos = Adiantamento::query()->whereKey($ids)->lockForUpdate()->get();

        if ($adiantamentos->contains(fn (Adiantamento $a): bool => $a->status !== StatusAdiantamento::Aberto)) {
            throw new PontoException('Um dos adiantamentos deste repasse já foi descontado ou cancelado. Recalcule o repasse.');
        }

        Adiantamento::query()->whereKey($adiantamentos->modelKeys())->get()
            ->each(fn (Adiantamento $a) => $a->update(['status' => StatusAdiantamento::Descontado, 'repasse_id' => $repasse->id]));
    }

    private function voltarParaRascunho(Repasse $repasse): void
    {
        $repasse->adiantamentos()->get()
            ->each(fn (Adiantamento $a) => $a->update(['status' => StatusAdiantamento::Aberto, 'repasse_id' => null]));

        $repasse->forceFill([
            'status' => StatusRepasse::Rascunho,
            'aprovado_por' => null,
            'aprovado_em' => null,
            'pago_em' => null,
        ])->save();
    }

    /** @throws PontoException */
    private function garantirRascunho(Repasse $repasse): void
    {
        if (! $repasse->podeEditar()) {
            throw new PontoException("O repasse está {$repasse->status->getLabel()}: só rascunho pode ser alterado.");
        }
    }

    /** @throws PontoException */
    private function garantirPermissao(User $usuario, string $permissao, string $acao): void
    {
        if (! $usuario->can($permissao)) {
            throw new PontoException("Você não tem permissão para {$acao}.");
        }
    }

    /** @throws PontoException */
    private static function garantirMotivo(string $motivo): void
    {
        if (mb_strlen(trim($motivo)) < self::MOTIVO_MINIMO) {
            throw new PontoException('Informe o motivo (mínimo de '.self::MOTIVO_MINIMO.' caracteres).');
        }
    }
}
