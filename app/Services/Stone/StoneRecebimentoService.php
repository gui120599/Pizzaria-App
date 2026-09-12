<?php

namespace App\Services\Stone;

use App\Enums\OperadoraMaquininha;
use App\Enums\StonePedidoModo;
use App\Enums\StonePedidoStatus;
use App\Exceptions\StoneConnectException;
use App\Models\ItensPedido;
use App\Models\Maquininha;
use App\Models\MovimentacoesSessaoCaixa;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosVenda;
use App\Models\StonePedido;
use App\Models\StoneWebhook;
use App\Models\Venda;
use App\Services\MovimentacaoCaixaService;
use App\Services\VendaService;
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

    /**
     * Cria o pedido na Stone a partir do PDV. Devolve o StonePedido já com
     * stp_order_id preenchido (status Aguardando), ou lança StoneConnectException.
     *
     * Modo Direto (padrão, balcão): a forma de pagamento é obrigatória e define
     * o tipo da transação (crédito/débito/PIX). Modo Listado (botão "Lançar
     * pedido total", recebimento na entrega): forma opcional — o tipo é
     * escolhido na maquininha e resolvido depois pelo webhook.
     */
    public function iniciarCobranca(
        Venda $venda,
        ?OpcoesPagamento $opcao,
        Maquininha $maquininha,
        float $valor,
        StonePedidoModo $modo = StonePedidoModo::Direto,
    ): StonePedido {
        if ($modo === StonePedidoModo::Direto) {
            if (! $opcao || ! $opcao->ehIntegracaoStone()) {
                throw new StoneConnectException('Esta forma de pagamento não está integrada à maquininha Stone.');
            }
            if (blank($opcao->tipoStone())) {
                throw new StoneConnectException('A forma de pagamento não tem um tipo Stone (crédito, débito ou PIX).');
            }
        } elseif ($opcao && ! $opcao->ehIntegracaoStone()) {
            throw new StoneConnectException('Esta forma de pagamento não está integrada à maquininha Stone.');
        }

        if ($maquininha->operadora !== OperadoraMaquininha::Stone || blank($maquininha->numero_serie)) {
            throw new StoneConnectException('A maquininha selecionada não é Stone ou está sem número de série cadastrado.');
        }

        if ($valor <= 0) {
            throw new StoneConnectException('Informe um valor maior que zero para enviar à maquininha.');
        }

        // Pedido Direto exige a conta credenciada para o modelo Direto. Com a
        // flag desligada, cai para Listado sem quebrar (só degrada a UX no POS).
        $modoEfetivo = ($modo === StonePedidoModo::Direto && config('services.stone.pedido_direto'))
            ? StonePedidoModo::Direto
            : StonePedidoModo::Listado;

        if ($modo === StonePedidoModo::Direto && $modoEfetivo === StonePedidoModo::Listado) {
            Log::channel('stone')->info('STONE_CONNECT_PEDIDO_DIRETO desligado — pedido criado como Listado', [
                'venda_id' => $venda->id,
            ]);
        }

        $pedido = StonePedido::create([
            'stp_venda_id' => $venda->id,
            'stp_pedido_id' => $this->pedidoVinculadoAVenda($venda),
            'stp_maquininha_id' => $maquininha->id,
            'stp_opcaopagamento_id' => $opcao?->id,
            'stp_valor_solicitado' => round($valor, 2),
            'stp_valor_pago' => 0,
            'stp_status' => StonePedidoStatus::Aguardando,
            'stp_modo' => $modoEfetivo,
        ]);

        try {
            $resposta = $this->connect->criarPedido($pedido);
        } catch (StoneConnectException $e) {
            $pedido->update(['stp_status' => StonePedidoStatus::Falha]);
            throw $e;
        }

        $pedido->update([
            'stp_order_id' => $resposta['id'] ?? null,
            'stp_order_code' => $resposta['code'] ?? null,
        ]);

        return $pedido->fresh();
    }

    /** Id do Pedido de origem cujos itens já estão lançados nesta venda (informativo). */
    private function pedidoVinculadoAVenda(Venda $venda): ?int
    {
        return ItensPedido::where('item_pedido_venda_id', $venda->id)
            ->whereNotNull('item_pedido_pedido_id')
            ->value('item_pedido_pedido_id');
    }

    /** Cancela um pedido que ainda não recebeu pagamento. */
    public function cancelarCobranca(StonePedido $pedido): void
    {
        if (! $pedido->stp_status->pendente()) {
            return;
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

        // Pedido Stone sem venda (recebimento na entrega) — fluxo ainda não
        // integrado: registra o valor no hub e loga, sem criar pagamento.
        if (blank($pedido->stp_venda_id)) {
            $this->registrarChargeSemVenda($pedido, $charge, $valor);

            return;
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

        DB::transaction(function () use ($webhook, $pedido, $original, $valor) {
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

                    if (filled($venda->venda_id_nfe)) {
                        Log::channel('stone')->error('Estorno Stone recebido, mas a NFC-e já foi emitida — avaliar cancelamento manual', [
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

    /** Resolve o StonePedido pelo id/código do pedido no payload — nunca por metadata. */
    public function resolverPedido(array $order): ?StonePedido
    {
        if (filled($order['id'] ?? null)) {
            $pedido = StonePedido::where('stp_order_id', $order['id'])->first();
            if ($pedido) {
                return $pedido;
            }
        }

        if (filled($order['code'] ?? null)) {
            return StonePedido::where('stp_order_code', $order['code'])->first();
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
