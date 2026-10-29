<?php

namespace App\Services\Stone;

use App\Enums\MotivoCancelamentoEnum;
use App\Enums\StonePedidoModo;
use App\Enums\StonePedidoOrigem;
use App\Enums\StonePedidoStatus;
use App\Exceptions\NfeIoException;
use App\Exceptions\StoneConnectException;
use App\Exceptions\StoneResultadoDesconhecidoException;
use App\Models\ItensPedido;
use App\Models\Maquininha;
use App\Models\MovimentacoesSessaoCaixa;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosPedido;
use App\Models\PagamentosVenda;
use App\Models\Pedido;
use App\Models\SessaoMesa;
use App\Models\StonePedido;
use App\Models\StoneWebhook;
use App\Models\Venda;
use App\Services\CancelamentoVendaService;
use App\Services\MovimentacaoCaixaService;
use App\Services\VendaService;
use App\Support\ContaMesa;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orquestra o fluxo de recebimento em maquininha Stone:
 *  - iniciarCobranca(): PDV cria o pedido na Stone (modelo Direto ou Listado)
 *  - processarWebhook(): charge.paid lança o PagamentosVenda e fecha o pedido;
 *    charge.refunded reverte o pagamento
 *  - cancelarCobranca(): cancela um pedido ainda não pago
 *
 * Tudo síncrono (o projeto não tem filas). O fechamento do pedido na Stone é
 * feito FORA da transação do pagamento — falha nele nunca desfaz o pagamento,
 * só fica pendente para o comando stone:conciliar-pedidos.
 */
class StoneRecebimentoService
{
    /** Diferença de centavos tolerada nas comparações de valor. */
    private const TOLERANCIA = 0.005;

    /** A Pagar.me guarda a Idempotency-Key por 24 h: dentro disso o reenvio do mesmo POST devolve o mesmo pedido. */
    private const JANELA_IDEMPOTENCIA_HORAS = 24;

    /** Aliases de scheme_name da Stone que não normalizam igual ao valor na coluna. */
    private const ALIAS_BANDEIRA = [
        'amex' => 'americanexpress',
        'mastercardmaestro' => 'mastercard',
        'maestro' => 'mastercard',
    ];

    public function __construct(
        private readonly StoneConnectService $connect,
        private readonly VendaService $vendas,
        private readonly MovimentacaoCaixaService $movimentacaoCaixa,
    ) {}

    // ── Valor devido e elegibilidade ─────────────────────────────────────────

    /**
     * Quanto ainda pode ser cobrado do alvo na maquininha, calculado no
     * servidor a partir do registro persistido — nunca do estado da tela:
     *  - Pedido: total − formas combinadas já efetuadas (pg_pedido_pago) − o
     *    que as cobranças Stone do pedido já cobriram (pago + saldo das
     *    pendentes, de qualquer modo) − pagamentos manuais lançados na venda
     *    que a própria cobrança Stone gerou.
     *  - Venda: total − já pago − saldo das cobranças pendentes.
     *  - SessaoMesa: conta em aberto (ContaMesa) − pedidos da mesa já pagos no
     *    pedido − saldo das cobranças pendentes.
     * O saldo de uma cobrança pendente é solicitado − pago. Usa as relações já
     * carregadas (lista de pedidos) para não consultar linha a linha.
     */
    public function valorDevido(Pedido|SessaoMesa|Venda $alvo): float
    {
        $stones = $this->stonePedidosDoAlvo($alvo);

        $devido = match (true) {
            $alvo instanceof Venda => (float) $alvo->venda_valor_total - (float) $alvo->venda_valor_pago - $this->saldoPendente($stones),
            $alvo instanceof SessaoMesa => ContaMesa::para($alvo)['total'] - $this->pagoNoPedidoDaMesa($alvo) - $this->saldoPendente($stones),
            default => $this->valorDevidoDoPedido($alvo, $stones),
        };

        return max(0.0, round($devido, 2));
    }

    /**
     * O alvo pode receber uma cobrança na maquininha agora? `oculto` separa o
     * que a tela esconde (pedido encerrado, cobrança em andamento, nada a
     * cobrar) do que mostra desabilitado com o motivo no tooltip (integração
     * sem configuração, maquininha sem vaga).
     *
     * @param  ?bool  $haMaquininhaConnect  Quem já sabe (lista de pedidos) evita a consulta por linha
     * @param  bool  $verificarVagas  O lote confere as vagas uma vez, sob lock, e não por pedido
     * @return array{elegivel: bool, motivo: ?string, oculto: bool}
     */
    public function elegibilidade(Pedido|SessaoMesa|Venda $alvo, ?Maquininha $maquininha = null, ?bool $haMaquininhaConnect = null, bool $verificarVagas = true): array
    {
        $negar = fn (string $motivo, bool $oculto = true): array => ['elegivel' => false, 'motivo' => $motivo, 'oculto' => $oculto];

        if (($motivo = $this->motivoAlvoNaoCobravel($alvo)) !== null) {
            return $negar($motivo);
        }

        if (! ($haMaquininhaConnect ?? Maquininha::stoneDisponivel()->exists())) {
            return $negar('Nenhuma maquininha com Stone Connect ativo e número de série cadastrado.');
        }

        if (blank(config('services.stone.secret_key')) || blank(config('services.stone.service_referer_name'))) {
            return $negar('A integração com a Stone Connect não está configurada.', oculto: false);
        }

        $stones = $this->stonePedidosDoAlvo($alvo);

        if ($stones->contains(fn (StonePedido $stp): bool => $stp->stp_status === StonePedidoStatus::Indeterminado)) {
            return $negar('O resultado de uma cobrança anterior é desconhecido: reconsulte na Stone antes de enviar de novo.');
        }

        if ($stones->contains(fn (StonePedido $stp): bool => $stp->stp_modo === StonePedidoModo::Direto
            && in_array($stp->stp_status, [StonePedidoStatus::Aguardando, StonePedidoStatus::PagoParcial], true))) {
            return $negar('Já existe uma cobrança aguardando pagamento na maquininha.');
        }

        if ($this->valorDevido($alvo) <= 0) {
            return $negar('Não há valor a cobrar na maquininha.');
        }

        if ($verificarVagas && $maquininha && $this->vagasAbertas($maquininha) <= 0) {
            return $negar($this->mensagemSemVagas($maquininha), oculto: false);
        }

        return ['elegivel' => true, 'motivo' => null, 'oculto' => false];
    }

    /** Quantas cobranças a maquininha ainda comporta (o POS da Stone lista no máximo `limite_pedidos_abertos`). */
    public function vagasAbertas(Maquininha $maquininha): int
    {
        return max(0, $this->limiteDePedidosAbertos() - $this->pedidosAbertos($maquininha));
    }

    public function limiteDePedidosAbertos(): int
    {
        return max(1, (int) config('services.stone.limite_pedidos_abertos', 30));
    }

    /**
     * Pedidos que ocupam o POS: cobranças pendentes (inclusive as ainda sem
     * order_id — já reservam a vaga enquanto o POST está em curso) e as pagas
     * cujo fechamento na Stone ainda não foi confirmado. Cancelada e falha não contam.
     */
    private function pedidosAbertos(Maquininha $maquininha): int
    {
        return StonePedido::query()
            ->where('stp_maquininha_id', $maquininha->id)
            ->where(fn ($query) => $query
                ->whereIn('stp_status', StonePedidoStatus::valoresPendentes())
                ->orWhere(fn ($pago) => $pago
                    ->where('stp_status', StonePedidoStatus::Pago->value)
                    ->whereNotNull('stp_order_id')
                    ->whereNull('stp_fechado_em')))
            ->count();
    }

    private function mensagemSemVagas(Maquininha $maquininha): string
    {
        return "A maquininha {$maquininha->nome} já tem {$this->limiteDePedidosAbertos()} pedidos abertos. Feche ou cancele pedidos em Financeiro › Pedidos Stone.";
    }

    /** Motivo pelo qual o alvo, em si, não é cobrável (null = cobrável). */
    private function motivoAlvoNaoCobravel(Pedido|SessaoMesa|Venda $alvo): ?string
    {
        if ($alvo instanceof Venda) {
            return $alvo->venda_status === 'INICIADA' ? null : 'A venda já foi finalizada ou cancelada.';
        }

        if ($alvo instanceof SessaoMesa) {
            return in_array($alvo->sessao_mesa_status, ['CANCELADA', 'FINALIZADA'], true) ? 'A conta da mesa já foi encerrada.' : null;
        }

        if ($alvo->trashed() || in_array($alvo->pedido_status, ['CANCELADO', 'FINALIZADO'], true)) {
            return 'O pedido está cancelado, finalizado ou excluído.';
        }

        if (filled($alvo->pedido_venda_id)) {
            return 'O pedido já foi recebido no PDV.';
        }

        // Itens lançados em venda do PDV: o recebimento é de lá. A venda criada pela
        // própria cobrança Stone (pagamento em parte) não conta como "alheia".
        $vendasDaStone = $this->stonePedidosDoAlvo($alvo)->pluck('stp_venda_id')->filter()->unique();
        $semItemVendido = $alvo->hasAttribute('tem_item_vendido') && ! $alvo->getAttribute('tem_item_vendido');
        $itemEmVendaAlheia = ! $semItemVendido
            && $alvo->item_pedido_pedido_id()
                ->whereNotNull('item_pedido_venda_id')
                ->when($vendasDaStone->isNotEmpty(), fn ($query) => $query->whereNotIn('item_pedido_venda_id', $vendasDaStone))
                ->exists();

        return $itemEmVendaAlheia ? 'O pedido já foi lançado no PDV: receba por lá.' : null;
    }

    /**
     * Cobranças Stone do alvo, usando a relação carregada quando houver. Pedido
     * só conta as de origem Pedido: as do PDV (origem Venda) guardam o pedido
     * apenas como informação e pertencem à venda.
     *
     * @return Collection<int, StonePedido>
     */
    private function stonePedidosDoAlvo(Pedido|SessaoMesa|Venda $alvo): Collection
    {
        return match (true) {
            $alvo instanceof Venda => StonePedido::where('stp_venda_id', $alvo->id)->get(),
            $alvo instanceof SessaoMesa => StonePedido::where('stp_sessao_mesa_id', $alvo->id)->get(),
            default => ($alvo->relationLoaded('stonePedidos') ? $alvo->stonePedidos : $alvo->stonePedidos()->get())
                ->filter(fn (StonePedido $stp): bool => $stp->stp_origem === StonePedidoOrigem::Pedido)
                ->values(),
        };
    }

    /**
     * @param  Collection<int, StonePedido>  $stones
     */
    private function saldoPendente(Collection $stones): float
    {
        return $stones
            ->filter(fn (StonePedido $stp): bool => $stp->stp_status->pendente())
            ->sum(fn (StonePedido $stp): float => max(0.0, (float) $stp->stp_valor_solicitado - (float) $stp->stp_valor_pago));
    }

    /**
     * @param  Collection<int, StonePedido>  $stones
     */
    private function valorDevidoDoPedido(Pedido $pedido, Collection $stones): float
    {
        $combinados = $pedido->relationLoaded('pagamentosCombinados') ? $pedido->pagamentosCombinados : $pedido->pagamentosCombinados()->get();
        $efetuados = $combinados->where('pg_pedido_pago', true)->sum(fn (PagamentosPedido $pagamento): float => (float) $pagamento->pg_pedido_valor);

        // Cobertura das cobranças: o que já entrou (stp_valor_pago, mesmo se a cobrança foi
        // cancelada depois) mais o saldo das ainda pendentes.
        $coberto = $stones->sum(fn (StonePedido $stp): float => (float) $stp->stp_valor_pago)
            + $this->saldoPendente($stones);

        // Venda gerada pela própria cobrança (pagamento em parte): o que o PDV recebeu
        // à mão nela também abate o pedido. Os pagamentos vindos das formas combinadas já
        // pagas estão em $efetuados, e os da Stone em stp_valor_pago.
        $vendaIds = $stones->pluck('stp_venda_id')->filter()->unique();
        $lancadosManuais = $vendaIds->isEmpty() ? 0.0 : (float) PagamentosVenda::query()
            ->whereIn('pg_venda_venda_id', $vendaIds)
            ->where('pg_venda_tipo_integracao', '!=', 'integrated')
            ->where(fn ($query) => $query
                ->whereNull('pg_venda_pagamento_pedido_id')
                ->orWhereDoesntHave('pagamentoPedido', fn ($pagamento) => $pagamento->where('pg_pedido_pago', true)))
            ->sum('pg_venda_valor_pagamento');

        return (float) $pedido->pedido_valor_total - $efetuados - $coberto - $lancadosManuais;
    }

    private function pagoNoPedidoDaMesa(SessaoMesa $sessao): float
    {
        return (float) PagamentosPedido::query()
            ->where('pg_pedido_pago', true)
            ->whereHas('pedido', fn ($query) => $query
                ->where('pedido_sessao_mesa_id', $sessao->id)
                ->whereNotIn('pedido_status', ContaMesa::STATUS_FORA_DA_CONTA))
            ->sum('pg_pedido_valor');
    }

    // ── Envio ────────────────────────────────────────────────────────────────

    /**
     * Cria o pedido na Stone a partir do PDV. Devolve o StonePedido já com
     * stp_order_id preenchido (status Aguardando), ou lança StoneConnectException
     * (StoneResultadoDesconhecidoException quando não se sabe se o pedido foi criado).
     *
     * Modo Direto (padrão, balcão): a forma de pagamento é obrigatória e define
     * o tipo da transação (crédito/débito/PIX). Modo Listado (botão "Lançar
     * pedido total", recebimento na entrega): forma opcional — o tipo é
     * escolhido na maquininha e resolvido depois pelo webhook. Sem $valor, cobra
     * o valor devido; com $valor, ele não pode passar do valor devido.
     */
    public function iniciarCobranca(
        Venda $venda,
        ?OpcoesPagamento $opcao,
        Maquininha $maquininha,
        ?float $valor = null,
        StonePedidoModo $modo = StonePedidoModo::Direto,
    ): StonePedido {
        return $this->enviar($venda, $opcao, $maquininha, $valor, $modo);
    }

    /**
     * Cria o pedido na Stone a partir de um Pedido avulso (balcão, retirada,
     * delivery) ou da conta de uma SessaoMesa — sem Venda prévia. O webhook
     * charge.paid cria a Venda quando o pagamento chegar (ver
     * StoneVendaAutomaticaService). Mesmas validações e camadas de
     * idempotência de iniciarCobranca(); o valor cobrado é sempre o devido
     * (ou menos, no Listado, quando $valor é informado).
     */
    public function iniciarCobrancaDePedido(
        Pedido|SessaoMesa $alvo,
        ?OpcoesPagamento $opcao,
        Maquininha $maquininha,
        ?float $valor = null,
        StonePedidoModo $modo = StonePedidoModo::Direto,
    ): StonePedido {
        return $this->enviar($alvo, $opcao, $maquininha, $valor, $modo);
    }

    /**
     * Envia vários pedidos de uma vez para a mesma maquininha (envio em lote do
     * painel). Os elegíveis precisam caber nas vagas da maquininha: se não
     * couberem, NENHUM é enviado. A checagem e a criação dos registros acontecem
     * numa transação com lock da maquininha (serializa envios concorrentes ao
     * mesmo aparelho); o HTTP vem depois, um por vez.
     *
     * @param  iterable<Pedido>  $pedidos
     * @return array{recusado: ?string, enviados: list<StonePedido>, indeterminados: list<StonePedido>, falhas: array<int, string>, ignorados: array<int, string>} Pedidos ignorados e com falha vêm indexados pelo id do Pedido
     */
    public function iniciarCobrancasEmLote(iterable $pedidos, ?OpcoesPagamento $opcao, Maquininha $maquininha, StonePedidoModo $modo = StonePedidoModo::Listado): array
    {
        $modoEfetivo = $this->validarFormaEMaquininha($opcao, $maquininha, $modo);
        $resultado = ['recusado' => null, 'enviados' => [], 'indeterminados' => [], 'falhas' => [], 'ignorados' => []];

        $criados = DB::transaction(function () use ($pedidos, $opcao, $maquininha, $modoEfetivo, &$resultado): Collection {
            $maquininhaTravada = $this->travarMaquininha($maquininha);
            $elegiveis = collect();

            foreach ($pedidos as $pedido) {
                $travado = $this->travarAlvo($pedido);
                $elegibilidade = $this->elegibilidade($travado, $maquininhaTravada, haMaquininhaConnect: true, verificarVagas: false);

                if ($elegibilidade['elegivel']) {
                    $elegiveis->push($travado);
                } else {
                    $resultado['ignorados'][$pedido->id] = $elegibilidade['motivo'];
                }
            }

            $vagas = $this->vagasAbertas($maquininhaTravada);
            if ($elegiveis->count() > $vagas) {
                $excedente = $elegiveis->count() - $vagas;
                $resultado['recusado'] = "A maquininha {$maquininhaTravada->nome} tem {$vagas} vaga(s) livre(s) e você selecionou {$elegiveis->count()} pedido(s) elegível(eis). Desmarque {$excedente} ou feche pedidos pagos.";

                return collect();
            }

            $criados = collect();
            foreach ($elegiveis as $travado) {
                try {
                    $criados->push($this->criarRegistroPendente($travado, $opcao, $maquininhaTravada, null, $modoEfetivo));
                } catch (StoneConnectException $e) {
                    $resultado['ignorados'][$travado->id] = $e->getMessage();
                }
            }

            return $criados;
        });

        foreach ($criados as $stonePedido) {
            try {
                $resultado['enviados'][] = $this->enviarParaStone($stonePedido);
            } catch (StoneResultadoDesconhecidoException) {
                $resultado['indeterminados'][] = $stonePedido->fresh();
            } catch (StoneConnectException $e) {
                $resultado['falhas'][(int) $stonePedido->stp_pedido_id] = $e->getMessage();
            }
        }

        return $resultado;
    }

    /**
     * Registra a cobrança sob lock (maquininha, depois alvo), faz commit e só
     * então fala com a Stone: nenhum HTTP dentro de transação, e uma segunda
     * tentativa já enxerga a linha pendente.
     */
    private function enviar(Pedido|SessaoMesa|Venda $alvo, ?OpcoesPagamento $opcao, Maquininha $maquininha, ?float $valor, StonePedidoModo $modo): StonePedido
    {
        $modoEfetivo = $this->validarFormaEMaquininha($opcao, $maquininha, $modo);

        if ($modo === StonePedidoModo::Direto && $modoEfetivo === StonePedidoModo::Listado) {
            Log::channel('stone')->info('STONE_CONNECT_PEDIDO_DIRETO desligado — pedido criado como Listado', [
                'origem' => $this->origemDoAlvo($alvo)->value, 'alvo_id' => $alvo->id,
            ]);
        }

        $stonePedido = DB::transaction(function () use ($alvo, $opcao, $maquininha, $valor, $modoEfetivo): StonePedido {
            $maquininhaTravada = $this->travarMaquininha($maquininha);
            $alvoTravado = $this->travarAlvo($alvo);

            $elegibilidade = $this->elegibilidade($alvoTravado, $maquininhaTravada, haMaquininhaConnect: true);
            if (! $elegibilidade['elegivel']) {
                throw new StoneConnectException($elegibilidade['motivo']);
            }

            return $this->criarRegistroPendente($alvoTravado, $opcao, $maquininhaTravada, $valor, $modoEfetivo);
        });

        return $this->enviarParaStone($stonePedido);
    }

    /**
     * Valida forma de pagamento e maquininha (regras comuns aos pontos de
     * disparo) e resolve o modo efetivo — Pedido Direto exige a conta
     * credenciada para o modelo; com a flag services.stone.pedido_direto
     * desligada, cai para Listado sem quebrar (só degrada a UX no POS).
     */
    private function validarFormaEMaquininha(?OpcoesPagamento $opcao, Maquininha $maquininha, StonePedidoModo $modo): StonePedidoModo
    {
        if ($modo === StonePedidoModo::Direto) {
            if (! $opcao || ! $opcao->permiteCobrarStone()) {
                throw new StoneConnectException('Esta forma de pagamento não pode ser cobrada na maquininha Stone.');
            }
            if (blank($opcao->tipoStone())) {
                throw new StoneConnectException('A forma de pagamento não tem um tipo Stone (crédito, débito ou PIX).');
            }
        } elseif ($opcao && ! $opcao->permiteCobrarStone()) {
            throw new StoneConnectException('Esta forma de pagamento não pode ser cobrada na maquininha Stone.');
        }

        if (! $maquininha->maquininha_connect_ativo || blank($maquininha->numero_serie)) {
            throw new StoneConnectException('A maquininha selecionada não recebe pedidos Stone Connect ou está sem número de série cadastrado.');
        }

        return ($modo === StonePedidoModo::Direto && config('services.stone.pedido_direto'))
            ? StonePedidoModo::Direto
            : StonePedidoModo::Listado;
    }

    private function travarMaquininha(Maquininha $maquininha): Maquininha
    {
        return Maquininha::whereKey($maquininha->id)->lockForUpdate()->first()
            ?? throw new StoneConnectException('A maquininha selecionada não foi encontrada.');
    }

    private function travarAlvo(Pedido|SessaoMesa|Venda $alvo): Pedido|SessaoMesa|Venda
    {
        return $alvo->newQueryWithoutScopes()->whereKey($alvo->getKey())->lockForUpdate()->first()
            ?? throw new StoneConnectException('O pedido ou a conta a cobrar não foi encontrado.');
    }

    private function origemDoAlvo(Pedido|SessaoMesa|Venda $alvo): StonePedidoOrigem
    {
        return match (true) {
            $alvo instanceof Venda => StonePedidoOrigem::Venda,
            $alvo instanceof SessaoMesa => StonePedidoOrigem::SessaoMesa,
            default => StonePedidoOrigem::Pedido,
        };
    }

    /**
     * Cria a linha pendente (ainda sem order_id). Chamado dentro da transação
     * que já travou maquininha e alvo e conferiu a elegibilidade. O índice
     * único de stp_chave_pendente é a última barreira contra duas cobranças
     * Direto no mesmo alvo.
     */
    private function criarRegistroPendente(Pedido|SessaoMesa|Venda $alvo, ?OpcoesPagamento $opcao, Maquininha $maquininha, ?float $valor, StonePedidoModo $modo): StonePedido
    {
        $devido = $this->valorDevido($alvo);
        $valorCobrado = $valor === null ? $devido : round($valor, 2);

        if ($valorCobrado <= 0) {
            throw new StoneConnectException('Informe um valor maior que zero para enviar à maquininha.');
        }

        if ($valorCobrado > $devido + self::TOLERANCIA) {
            throw new StoneConnectException('O valor a cobrar (R$ '.number_format($valorCobrado, 2, ',', '.').') passa do saldo devido (R$ '.number_format($devido, 2, ',', '.').').');
        }

        try {
            return StonePedido::create([
                'stp_venda_id' => $alvo instanceof Venda ? $alvo->id : null,
                'stp_pedido_id' => match (true) {
                    $alvo instanceof Pedido => $alvo->id,
                    $alvo instanceof Venda => $this->pedidoVinculadoAVenda($alvo),
                    default => null,
                },
                'stp_sessao_mesa_id' => $alvo instanceof SessaoMesa ? $alvo->id : null,
                'stp_origem' => $this->origemDoAlvo($alvo),
                'stp_usuario_id' => Auth::id(),
                'stp_maquininha_id' => $maquininha->id,
                'stp_opcaopagamento_id' => $opcao?->id,
                'stp_valor_solicitado' => $valorCobrado,
                'stp_valor_pago' => 0,
                'stp_status' => StonePedidoStatus::Aguardando,
                'stp_modo' => $modo,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new StoneConnectException('Já existe uma cobrança aguardando na maquininha para este pedido. Acompanhe a existente antes de enviar outra.');
        }
    }

    /**
     * Fala com a Stone para uma linha já gravada. Recusa comprovada (4xx) vira
     * Falha; timeout, rede e 5xx viram Indeterminado — o pedido pode existir na
     * Stone, então a linha NUNCA é substituída: reenvia-se esta mesma, com a
     * mesma Idempotency-Key (ver reconsultar()).
     */
    private function enviarParaStone(StonePedido $stonePedido): StonePedido
    {
        try {
            $resposta = $this->connect->criarPedido($stonePedido);
        } catch (StoneResultadoDesconhecidoException $e) {
            $stonePedido->update(['stp_status' => StonePedidoStatus::Indeterminado, 'stp_erro' => $e->getMessage()]);

            throw $e->paraStonePedido($stonePedido->id);
        } catch (StoneConnectException $e) {
            $stonePedido->update(['stp_status' => StonePedidoStatus::Falha, 'stp_erro' => $e->getMessage()]);

            throw $e;
        }

        // Só tira do Indeterminado: um webhook que chegou antes (resolvido pelo code)
        // pode já ter levado a linha para Pago, e isso não pode regredir.
        $atualizacao = [
            'stp_order_id' => $resposta['id'] ?? null,
            'stp_order_code' => $resposta['code'] ?? null,
            'stp_erro' => null,
        ];

        if (StonePedido::find($stonePedido->id)?->stp_status === StonePedidoStatus::Indeterminado) {
            $atualizacao['stp_status'] = StonePedidoStatus::Aguardando;
        }

        $stonePedido->update($atualizacao);

        return $stonePedido->fresh();
    }

    /**
     * Resolve uma cobrança de resultado desconhecido (ou Aguardando que ficou
     * sem order_id):
     *  1. com order_id → GET /orders/{id} e aplica o que a Stone responde;
     *  2. sem order_id → repete o POST com a MESMA Idempotency-Key, dentro das 24 h
     *     em que a Pagar.me guarda a chave: se o pedido já existe, devolve o mesmo.
     * Passadas as 24 h sem order_id, mantém Indeterminado e pede conferência no
     * dashboard Pagar.me pelo code rf-{id}.
     *
     * @throws StoneConnectException
     */
    public function reconsultar(StonePedido $stonePedido): StonePedido
    {
        $stonePedido = $stonePedido->fresh();

        if (! in_array($stonePedido->stp_status, [StonePedidoStatus::Indeterminado, StonePedidoStatus::Aguardando], true)) {
            return $stonePedido;
        }

        if (filled($stonePedido->stp_order_id)) {
            $this->aplicarPedidoDaStone($stonePedido, $this->connect->consultarPedido($stonePedido->stp_order_id));

            return $stonePedido->fresh();
        }

        if ($stonePedido->created_at->lt(now()->subHours(self::JANELA_IDEMPOTENCIA_HORAS))) {
            // Sem order_id e fora da janela: segue como Indeterminado (nunca vira Falha sozinho).
            $stonePedido->update(['stp_status' => StonePedidoStatus::Indeterminado]);

            throw new StoneConnectException('Passaram mais de '.self::JANELA_IDEMPOTENCIA_HORAS.' h sem resposta da Stone: confira no dashboard Pagar.me o pedido de code '.StoneConnectService::codigoDoPedido($stonePedido).' antes de enviar de novo.');
        }

        return $this->enviarParaStone($stonePedido);
    }

    /**
     * Aplica o estado do pedido na Stone (resposta de GET /orders/{id}) ao
     * StonePedido: cancelado/falho, pedido aberto (sai do Indeterminado) e
     * charges pagas (reprocessadas como charge.paid, sem duplicar).
     *
     * @param  array<string, mixed>  $order
     * @return int Quantidade de charges pagas recuperadas
     */
    public function aplicarPedidoDaStone(StonePedido $stonePedido, array $order): int
    {
        $status = $order['status'] ?? null;

        if (in_array($status, ['canceled', 'failed'], true)) {
            $stonePedido->update([
                'stp_status' => $status === 'canceled' ? StonePedidoStatus::Cancelado : StonePedidoStatus::Falha,
                'stp_order_id' => $stonePedido->stp_order_id ?? ($order['id'] ?? null),
            ]);

            return 0;
        }

        if ($stonePedido->stp_status === StonePedidoStatus::Indeterminado) {
            $stonePedido->update([
                'stp_status' => StonePedidoStatus::Aguardando,
                'stp_order_id' => $order['id'] ?? $stonePedido->stp_order_id,
                'stp_order_code' => $order['code'] ?? $stonePedido->stp_order_code,
                'stp_erro' => null,
            ]);
        }

        $recuperados = 0;
        foreach ($order['charges'] ?? [] as $charge) {
            if (($charge['status'] ?? null) !== 'paid') {
                continue;
            }

            $webhook = StoneWebhook::create([
                'stw_evento' => 'charge.paid',
                'stw_hook_id' => 'sync-'.($charge['id'] ?? uniqid()),
                'stw_charge_id' => $charge['id'] ?? null,
                'stw_charge_code' => $charge['code'] ?? null,
                'stw_order_id' => $stonePedido->stp_order_id,
                'stw_order_code' => $order['code'] ?? $stonePedido->stp_order_code,
                'stw_venda_id' => $stonePedido->stp_venda_id,
                'stw_stone_pedido_id' => $stonePedido->id,
                'stw_payload' => $this->payloadSintetico($order, $charge),
                'stw_processado_em' => now(),
            ]);

            try {
                $this->processarWebhook($webhook);
                $recuperados++;
            } catch (\Throwable $e) {
                Log::channel('stone')->error('Falha ao reprocessar charge recuperada da Stone', [
                    'stone_pedido_id' => $stonePedido->id, 'charge_id' => $charge['id'] ?? null, 'erro' => $e->getMessage(),
                ]);
            }
        }

        return $recuperados;
    }

    /**
     * @param  array<string, mixed>  $order
     * @param  array<string, mixed>  $charge
     * @return array<string, mixed>
     */
    private function payloadSintetico(array $order, array $charge): array
    {
        return [
            'id' => 'sync-'.($charge['id'] ?? uniqid()),
            'type' => 'charge.paid',
            'data' => [
                'id' => $charge['id'] ?? null,
                'code' => $charge['code'] ?? null,
                'amount' => $charge['amount'] ?? null,
                'paid_amount' => $charge['paid_amount'] ?? $charge['amount'] ?? null,
                'status' => 'paid',
                'payment_method' => $charge['payment_method'] ?? null,
                'order' => ['id' => $order['id'] ?? null, 'code' => $order['code'] ?? null],
                'metadata' => $charge['last_transaction']['metadata'] ?? $charge['metadata'] ?? [],
                // Necessário para inferir a forma de pagamento nos pedidos Listado
                // (sem stp_opcaopagamento_id).
                'last_transaction' => $charge['last_transaction'] ?? [],
            ],
        ];
    }

    /** Id do Pedido de origem cujos itens já estão lançados nesta venda (informativo). */
    private function pedidoVinculadoAVenda(Venda $venda): ?int
    {
        return ItensPedido::where('item_pedido_venda_id', $venda->id)
            ->whereNotNull('item_pedido_pedido_id')
            ->value('item_pedido_pedido_id');
    }

    /**
     * Cancela uma cobrança que ainda não recebeu pagamento. Resultado
     * desconhecido sem order_id não se cancela: o pedido pode existir na Stone.
     */
    public function cancelarCobranca(StonePedido $pedido): void
    {
        if (! $pedido->stp_status->pendente()) {
            return;
        }

        if ($pedido->stp_status === StonePedidoStatus::Indeterminado && blank($pedido->stp_order_id)) {
            throw new StoneConnectException('O resultado desta cobrança é desconhecido: reconsulte na Stone antes de cancelar.');
        }

        if (filled($pedido->stp_order_id)) {
            $this->connect->cancelarPedido($pedido->stp_order_id);
        }

        $pedido->update(['stp_status' => StonePedidoStatus::Cancelado]);
    }

    /** Ponto de entrada do webhook: encaminha por tipo de evento. */
    public function processarWebhook(StoneWebhook $webhook): void
    {
        match ($webhook->stw_evento) {
            'charge.paid' => $this->processarChargePaid($webhook),
            'charge.refunded' => $this->processarChargeRefunded($webhook),
            default => null,
        };
    }

    public function processarChargePaid(StoneWebhook $webhook): void
    {
        $payload = $webhook->stw_payload ?? [];
        $charge = $payload['data'] ?? [];

        $pedido = $this->resolverPedido($charge['order'] ?? []);
        if (! $pedido) {
            // charge avulsa (criada direto na maquininha, sem pedido do PDV) ou
            // order_id não persistido no StonePedido — não deixa passar em silêncio.
            Log::channel('stone')->warning('charge.paid sem StonePedido correspondente (charge avulsa ou order_id não persistido)', [
                'order_id' => $charge['order']['id'] ?? null,
                'order_code' => $charge['order']['code'] ?? null,
                'charge_id' => $charge['id'] ?? null,
            ]);

            return;
        }

        $webhook->forceFill(['stw_stone_pedido_id' => $pedido->id])->save();

        // Dedupe: outra entrega do mesmo charge já foi processada.
        $jaProcessado = StoneWebhook::where('stw_charge_id', $webhook->stw_charge_id)
            ->where('id', '!=', $webhook->id)
            ->whereNotNull('stw_pagamento_venda_id')
            ->exists();

        if ($jaProcessado) {
            return;
        }

        $valor = round((float) ($charge['paid_amount'] ?? $charge['amount'] ?? 0) / 100, 2);
        $meta = $charge['metadata'] ?? [];

        // Pedido/SessaoMesa sem Venda ainda (origem Pedido/SessaoMesa — ver
        // StonePedidoOrigem): tenta criar a Venda agora. Sem sessão de caixa
        // aberta (ou mais de uma) ou sem pedido ativo, cai no fallback que só
        // registra o valor no hub (registrarChargeSemVenda) — recuperável
        // depois por stone:conciliar-pedidos.
        if (blank($pedido->stp_venda_id)) {
            $venda = app(StoneVendaAutomaticaService::class)->resolverOuCriarVenda($pedido);

            if (! $venda) {
                $this->registrarChargeSemVenda($pedido, $charge, $valor);

                return;
            }

            $pedido->forceFill(['stp_venda_id' => $venda->id])->save();
        }

        $fechar = DB::transaction(function () use ($webhook, $pedido, $charge, $meta, $valor) {
            $pedido = StonePedido::whereKey($pedido->id)->lockForUpdate()->first();
            $venda = Venda::find($pedido->stp_venda_id);

            if (! $venda || $valor <= 0) {
                return false;
            }

            $bandeiraId = $this->resolverBandeira($meta['scheme_name'] ?? null);
            $opcaoId = $pedido->stp_opcaopagamento_id ?? $this->resolverOpcaoStone($charge);

            $pagamento = PagamentosVenda::create([
                'pg_venda_venda_id' => $venda->id,
                'pg_venda_opcaopagamento_id' => $opcaoId,
                'pg_venda_cartao_id' => $bandeiraId,
                'pg_venda_maquininha_id' => $pedido->stp_maquininha_id,
                'pg_venda_numero_autorizacao_cartao' => $meta['authorization_code'] ?? $charge['code'] ?? null,
                'pg_venda_tipo_integracao' => 'integrated',
                'pg_venda_valor_pagamento' => $valor,
                'pg_venda_valor_recebido' => $valor,
                'pg_venda_valor_pago_pelo_cliente' => $valor,
                'pg_venda_valor_troco' => 0,
                'pg_venda_valor_acrescimo' => 0,
                'pg_venda_valor_desconto' => 0,
            ]);

            $webhook->forceFill(['stw_pagamento_venda_id' => $pagamento->id])->save();

            $pago = round((float) $pedido->stp_valor_pago + $valor, 2);
            $completo = $pago + 0.005 >= (float) $pedido->stp_valor_solicitado;

            $pedido->update([
                'stp_valor_pago' => $pago,
                'stp_charge_id' => $charge['id'] ?? $pedido->stp_charge_id,
                'stp_charge_code' => $charge['code'] ?? $pedido->stp_charge_code,
                'stp_status' => $completo ? StonePedidoStatus::Pago : StonePedidoStatus::PagoParcial,
            ]);

            if ($venda->venda_status === 'FINALIZADA') {
                // Não deveria acontecer (finalização é bloqueada com pedido pendente).
                Log::channel('stone')->warning('charge.paid recebido para venda já FINALIZADA', [
                    'venda_id' => $venda->id, 'stone_pedido_id' => $pedido->id, 'charge_id' => $charge['id'] ?? null,
                ]);
            } else {
                $venda->venda_valor_pago = round((float) $venda->venda_valor_pago + $valor, 2);
                $venda->save();
            }

            Log::channel('stone')->info('Pagamento Stone lançado', [
                'venda_id' => $venda->id, 'stone_pedido_id' => $pedido->id,
                'pagamento_venda_id' => $pagamento->id, 'valor' => $valor,
                'status_pedido' => $completo ? 'pago' : 'pago_parcial',
            ]);

            return $completo && filled($pedido->stp_order_id) && $pedido->stp_fechado_em === null;
        });

        if ($fechar) {
            $this->fecharPedidoNaStone($pedido->fresh());
        }

        // No-op para StonePedido de origem Venda (fluxo do PDV) — quem
        // finaliza continua sendo o operador em OperarVenda.
        $vendaAtualizada = Venda::find($pedido->fresh()->stp_venda_id);
        if ($vendaAtualizada) {
            app(StoneVendaAutomaticaService::class)->finalizarSePago($vendaAtualizada, $pedido->fresh());
        }
    }

    public function processarChargeRefunded(StoneWebhook $webhook): void
    {
        $payload = $webhook->stw_payload ?? [];
        $charge = $payload['data'] ?? [];

        $pedido = $this->resolverPedido($charge['order'] ?? []);
        if (! $pedido) {
            Log::channel('stone')->warning('charge.refunded sem StonePedido correspondente', [
                'order_id' => $charge['order']['id'] ?? null,
                'order_code' => $charge['order']['code'] ?? null,
                'charge_id' => $charge['id'] ?? null,
            ]);

            return;
        }

        $webhook->forceFill(['stw_stone_pedido_id' => $pedido->id])->save();

        $valorSemVenda = round((float) ($charge['canceled_amount'] ?? $charge['amount'] ?? 0) / 100, 2);
        if (blank($pedido->stp_venda_id)) {
            $pago = max(0, round((float) $pedido->stp_valor_pago - $valorSemVenda, 2));
            $pedido->update([
                'stp_valor_pago' => $pago,
                'stp_status' => $pago <= 0.005 ? StonePedidoStatus::Estornado : StonePedidoStatus::PagoParcial,
            ]);

            Log::channel('stone')->warning('charge.refunded de StonePedido sem venda — recebimento na entrega ainda não integrado', [
                'stone_pedido_id' => $pedido->id,
                'pedido_id' => $pedido->stp_pedido_id,
                'charge_id' => $webhook->stw_charge_id,
                'valor' => $valorSemVenda,
            ]);

            return;
        }

        $original = StoneWebhook::where('stw_charge_id', $charge['id'] ?? '__none__')
            ->whereNotNull('stw_pagamento_venda_id')
            ->orderByDesc('id')
            ->first();

        $valor = round((float) ($charge['canceled_amount'] ?? $charge['amount'] ?? 0) / 100, 2);
        $vendaCancelada = null;

        DB::transaction(function () use ($webhook, $pedido, $original, $valor, &$vendaCancelada) {
            $pedido = StonePedido::whereKey($pedido->id)->lockForUpdate()->first();
            $pagamento = $original?->stw_pagamento_venda_id
                ? PagamentosVenda::find($original->stw_pagamento_venda_id)
                : null;
            $pagamentoId = null;

            if ($pagamento) {
                $venda = Venda::find($pagamento->pg_venda_venda_id);
                $vendaFinalizada = $venda && $venda->venda_status === 'FINALIZADA';
                $valorEstorno = $valor > 0 ? $valor : (float) $pagamento->pg_venda_valor_pagamento;

                $pagamentoId = $pagamento->id;
                $pagamento->delete();

                if ($venda) {
                    $this->vendas->atualizarValoresdaVenda($venda->id);
                }

                if ($vendaFinalizada && $venda) {
                    $this->reverterEfeitosNoCaixa($venda, $valorEstorno);

                    // Estorno total (nada mais pago na venda): a venda é desfeita.
                    // Parcial: segue finalizada com saldo em aberto, para o caixa tratar.
                    if ((float) $venda->fresh()->venda_valor_pago <= 0.005) {
                        $vendaCancelada = app(CancelamentoVendaService::class)
                            ->cancelarFinalizada($venda, MotivoCancelamentoEnum::PROBLEMA_PAGAMENTO, $pedido->stp_usuario_id);
                    } elseif (filled($venda->venda_id_nfe)) {
                        Log::channel('stone')->error('Estorno Stone parcial em venda com NFC-e emitida — avaliar cancelamento manual', [
                            'venda_id' => $venda->id, 'venda_id_nfe' => $venda->venda_id_nfe,
                        ]);
                    }
                }
            }

            $pago = max(0, round((float) $pedido->stp_valor_pago - $valor, 2));
            $pedido->update([
                'stp_valor_pago' => $pago,
                'stp_status' => $pago <= 0.005 ? StonePedidoStatus::Estornado : StonePedidoStatus::PagoParcial,
            ]);

            Log::channel('stone')->warning('Estorno Stone processado', [
                'stone_pedido_id' => $pedido->id, 'venda_id' => $pedido->stp_venda_id,
                'charge_id' => $webhook->stw_charge_id, 'valor' => $valor,
                'pagamento_removido' => $pagamentoId,
            ]);
        });

        if ($vendaCancelada) {
            $this->cancelarNfeDaVendaEstornada($vendaCancelada);
        }
    }

    /** Fora da transação: chamada HTTP à NFe.io. Recusa fica no log para cancelamento manual. */
    private function cancelarNfeDaVendaEstornada(Venda $venda): void
    {
        try {
            app(CancelamentoVendaService::class)->solicitarCancelamentoNfe($venda);
        } catch (NfeIoException $e) {
            Log::channel('stone')->error('Venda cancelada por estorno Stone, mas a NFC-e não pôde ser cancelada — cancelar manualmente', [
                'venda_id' => $venda->id, 'venda_id_nfe' => $venda->venda_id_nfe, 'erro' => $e->getMessage(),
            ]);
        }
    }

    /**
     * charge.paid de um StonePedido sem venda (recebimento na entrega). O fluxo
     * de lançamento ainda não existe — só registra o valor no hub e fecha o
     * pedido na Stone para não estourar o limite de pedidos abertos.
     */
    private function registrarChargeSemVenda(StonePedido $pedido, array $charge, float $valor): void
    {
        $chargeId = $charge['id'] ?? null;

        $fechar = DB::transaction(function () use ($pedido, $charge, $chargeId, $valor) {
            $pedido = StonePedido::whereKey($pedido->id)->lockForUpdate()->first();

            if ($valor <= 0 || (filled($chargeId) && $pedido->stp_charge_id === $chargeId)) {
                return false;
            }

            $pago = round((float) $pedido->stp_valor_pago + $valor, 2);
            $completo = $pago + 0.005 >= (float) $pedido->stp_valor_solicitado;

            $pedido->update([
                'stp_valor_pago' => $pago,
                'stp_charge_id' => $chargeId ?? $pedido->stp_charge_id,
                'stp_charge_code' => $charge['code'] ?? $pedido->stp_charge_code,
                'stp_status' => $completo ? StonePedidoStatus::Pago : StonePedidoStatus::PagoParcial,
            ]);

            Log::channel('stone')->warning('charge.paid de StonePedido sem venda — recebimento na entrega ainda não integrado (valor só registrado no hub)', [
                'stone_pedido_id' => $pedido->id,
                'pedido_id' => $pedido->stp_pedido_id,
                'charge_id' => $chargeId,
                'valor' => $valor,
            ]);

            return $completo && filled($pedido->stp_order_id) && $pedido->stp_fechado_em === null;
        });

        if ($fechar) {
            $this->fecharPedidoNaStone($pedido->fresh());
        }
    }

    /**
     * Infere a OpcoesPagamento integrada à Stone a partir do tipo da transação
     * do charge — usado no Pedido Listado, onde o operador só escolhe o tipo
     * (crédito/débito/PIX) na própria maquininha.
     */
    private function resolverOpcaoStone(array $charge): ?int
    {
        $tx = $charge['last_transaction'] ?? [];
        $tipo = strtolower((string) (
            $tx['transaction_type']
            ?? ($tx['card']['type'] ?? null)
            ?? $charge['payment_method']
            ?? ''
        ));

        $descNfe = match (true) {
            str_contains($tipo, 'debit') => 'debitCard',
            str_contains($tipo, 'credit') => 'creditCard',
            str_contains($tipo, 'pix') => 'InstantPayment',
            default => null,
        };

        if ($descNfe === null) {
            Log::channel('stone')->warning('Não foi possível inferir a forma de pagamento Stone do charge', [
                'charge_id' => $charge['id'] ?? null, 'tipo' => $tipo,
            ]);

            return null;
        }

        $opcaoId = OpcoesPagamento::where('opcaopag_stone_integrada', true)
            ->where('opcaopag_desc_nfe', $descNfe)
            ->value('id');

        if ($opcaoId === null) {
            Log::channel('stone')->warning('charge Stone sem OpcoesPagamento integrada correspondente', [
                'charge_id' => $charge['id'] ?? null, 'desc_nfe' => $descNfe,
            ]);
        }

        return $opcaoId ? (int) $opcaoId : null;
    }

    /**
     * Lança um movimento compensatório de saída no caixa e recomputa o saldo
     * final da sessão via MovimentacaoCaixaService::recalcularSaldoFinal (fonte
     * única do recompute — ver a classe pra detalhes da fórmula).
     */
    private function reverterEfeitosNoCaixa(Venda $venda, float $valor): void
    {
        if (blank($venda->venda_sessao_caixa_id) || $valor <= 0) {
            return;
        }

        MovimentacoesSessaoCaixa::create([
            'mov_sessaocaixa_id' => $venda->venda_sessao_caixa_id,
            'mov_venda_id' => $venda->id,
            'mov_descricao' => "ESTORNO STONE: venda {$venda->id}",
            'mov_tipo' => 'SAIDA',
            'mov_valor' => $valor,
        ]);

        $sessao = $venda->sessaoCaixa()->first();
        if ($sessao) {
            $this->movimentacaoCaixa->recalcularSaldoFinal($sessao);
        }
    }

    /** Fecha o pedido na Stone; falha só loga (o comando de conciliação reprocessa). */
    public function fecharPedidoNaStone(StonePedido $pedido, string $status = 'paid'): void
    {
        if (blank($pedido->stp_order_id) || $pedido->stp_fechado_em !== null) {
            return;
        }

        try {
            $this->connect->fecharPedido($pedido->stp_order_id, $status);
            $pedido->update(['stp_fechado_em' => now()]);
        } catch (StoneConnectException $e) {
            Log::channel('stone')->error('Falha ao fechar pedido na Stone (será reprocessado pela conciliação)', [
                'stone_pedido_id' => $pedido->id, 'order_id' => $pedido->stp_order_id, 'erro' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Resolve o StonePedido pelo id/código do pedido no payload — nunca por
     * metadata. O code rf-{id} é o nosso identificador e vale mesmo quando o
     * webhook chega antes de gravarmos o stp_order_id (corrida com a resposta
     * do POST /orders).
     */
    public function resolverPedido(array $order): ?StonePedido
    {
        if (filled($order['id'] ?? null)) {
            $pedido = StonePedido::where('stp_order_id', $order['id'])->first();
            if ($pedido) {
                return $pedido;
            }
        }

        if (filled($order['code'] ?? null)) {
            $pedido = StonePedido::where('stp_order_code', $order['code'])->first();
            if ($pedido) {
                return $pedido;
            }

            if (preg_match('/^rf-(\d+)$/', (string) $order['code'], $partes)) {
                $pedido = StonePedido::find((int) $partes[1]);

                // Grava o order_id que a resposta do POST ainda não tinha gravado.
                if ($pedido && blank($pedido->stp_order_id) && filled($order['id'] ?? null)) {
                    $pedido->update(['stp_order_id' => $order['id'], 'stp_order_code' => $order['code']]);
                }

                return $pedido;
            }
        }

        return null;
    }

    /**
     * Casa o scheme_name da Stone com um CartoesPagamento já cadastrado.
     * Não cria registro novo (o enum/mutator de cartoes_pagamentos é frágil e
     * o app nem tem tela de cadastro) e lê a coluna crua via query builder,
     * ignorando o accessor quebrado do model. Sem correspondência → null + log.
     */
    public function resolverBandeira(?string $schemeName): ?int
    {
        if (blank($schemeName)) {
            return null;
        }

        $alvo = $this->normalizarBandeira($schemeName);

        $cartoes = DB::table('cartoes_pagamentos')
            ->whereNull('deleted_at')
            ->get(['id', 'cartao_bandeira']);

        foreach ($cartoes as $cartao) {
            if ($this->normalizarBandeira((string) $cartao->cartao_bandeira) === $alvo) {
                return (int) $cartao->id;
            }
        }

        Log::channel('stone')->warning('Bandeira da Stone sem CartoesPagamento correspondente', [
            'scheme_name' => $schemeName,
        ]);

        return null;
    }

    private function normalizarBandeira(string $valor): string
    {
        $slug = strtolower((string) preg_replace('/[^a-z]/i', '', $valor));

        return self::ALIAS_BANDEIRA[$slug] ?? $slug;
    }
}
