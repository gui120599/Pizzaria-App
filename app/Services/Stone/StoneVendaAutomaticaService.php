<?php

namespace App\Services\Stone;

use App\Enums\StonePedidoOrigem;
use App\Exceptions\VendaNaoFinalizavelException;
use App\Jobs\EmitirNfeAutomaticaJob;
use App\Models\ItensPedido;
use App\Models\PagamentosVenda;
use App\Models\Pedido;
use App\Models\SessaoCaixa;
use App\Models\SessaoMesa;
use App\Models\StonePedido;
use App\Models\Venda;
use App\Services\FinalizacaoVendaService;
use App\Services\LancamentoItensVendaService;
use App\Services\VendaService;
use App\Support\ContaMesa;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Cria a Venda a partir de um StonePedido de origem Pedido/SessaoMesa (sem
 * Venda prévia — ver App\Enums\StonePedidoOrigem) e finaliza quando o
 * pagamento cobre o total. Chamado por
 * StoneRecebimentoService::processarChargePaid() no lugar de
 * registrarChargeSemVenda() sempre que o StonePedido ainda não tem
 * stp_venda_id.
 *
 * O fluxo original do PDV (StonePedidoOrigem::Venda) nunca passa por aqui: a
 * Venda já existe antes do envio à maquininha e quem finaliza continua sendo
 * o operador em OperarVenda.
 */
class StoneVendaAutomaticaService
{
    private const TOLERANCIA_CENTAVOS = 0.005;

    public function __construct(
        private readonly LancamentoItensVendaService $lancamento,
        private readonly VendaService $vendas,
    ) {}

    /**
     * Resolve os pedidos-alvo do StonePedido (um Pedido avulso ou todos os
     * pedidos ativos de uma SessaoMesa), reaproveita a Venda se o caixa já
     * lançou esses itens manualmente enquanto a maquininha processava, ou
     * cria uma Venda nova.
     *
     * O estado do caixa nunca impede a venda de nascer: sem $sessaoCaixaId,
     * usa a única SessaoCaixa ABERTA se houver exatamente uma; com zero ou 2+
     * sessões ABERTA, a venda é criada mesmo assim, com
     * venda_sessao_caixa_id null (órfã) — quem abrir/revisar uma sessão
     * depois vincula via SessaoCaixaService::vincularVendasOrfas(). Com
     * $sessaoCaixaId explícito (recuperação manual via StonePedidoResource —
     * ação "Gerar venda"), usa a sessão informada direto.
     *
     * Não persiste stp_venda_id — quem chama decide o momento (dentro da
     * transação do pagamento, junto do restante do estado do StonePedido).
     */
    public function resolverOuCriarVenda(StonePedido $stonePedido, ?int $sessaoCaixaId = null): ?Venda
    {
        if (filled($stonePedido->stp_venda_id)) {
            return Venda::find($stonePedido->stp_venda_id);
        }

        $pedidosAlvo = $this->resolverPedidosAlvo($stonePedido);
        if ($pedidosAlvo->isEmpty()) {
            $this->registrarErro($stonePedido, 'Nenhum pedido ativo encontrado para cobrar (cancelado, já finalizado, ou removido).');

            return null;
        }

        // Corrida com o PDV: o caixa pode ter lançado esses itens manualmente
        // (OperarVenda) enquanto a maquininha processava o pagamento. Reaproveita
        // a venda existente em vez de criar uma segunda.
        $vendaExistenteId = ItensPedido::whereIn('item_pedido_pedido_id', $pedidosAlvo->pluck('id'))
            ->whereNotNull('item_pedido_venda_id')
            ->value('item_pedido_venda_id');

        if (filled($vendaExistenteId)) {
            return Venda::find($vendaExistenteId);
        }

        // O estado do caixa nunca bloqueia o recebimento: com $sessaoCaixaId
        // explícito (recuperação manual) usa essa sessão; senão usa a única
        // sessão ABERTA se houver exatamente uma; com zero ou 2+ sessões
        // ABERTA, a venda nasce sem sessão (venda_sessao_caixa_id null) — fica
        // disponível pra alguém vincular depois via
        // SessaoCaixaService::vincularVendasOrfas() quando abrir/revisar uma
        // sessão (ver App\Livewire\VendasSemSessaoCaixa).
        $sessaoCaixaIdEscolhida = null;

        if ($sessaoCaixaId !== null) {
            $sessaoEscolhida = SessaoCaixa::where('id', $sessaoCaixaId)->where('sessaocaixa_status', 'ABERTA')->first();
            if (! $sessaoEscolhida) {
                $this->registrarErro($stonePedido, "Sessão de caixa #{$sessaoCaixaId} não está mais ABERTA.");

                return null;
            }
            $sessaoCaixaIdEscolhida = $sessaoEscolhida->id;
        } else {
            $sessoesAbertas = SessaoCaixa::where('sessaocaixa_status', 'ABERTA')->get();
            if ($sessoesAbertas->count() === 1) {
                $sessaoCaixaIdEscolhida = $sessoesAbertas->first()->id;
            }
        }

        $venda = Venda::create([
            'venda_status' => 'INICIADA',
            'venda_sessao_caixa_id' => $sessaoCaixaIdEscolhida,
            'venda_cliente_id' => $this->resolverClienteId($stonePedido, $pedidosAlvo),
            'venda_datahora_iniciada' => now(),
        ]);

        foreach ($pedidosAlvo as $pedido) {
            $this->lancamento->lancarPedido($venda, $pedido);
        }

        $this->vendas->atualizarValoresdaVenda($venda->id);

        return $venda->fresh();
    }

    /**
     * Recuperação completa (usada pela ação manual "Gerar venda" do
     * StonePedidoResource e pela etapa nova de stone:conciliar-pedidos):
     * resolve/cria a Venda, persiste stp_venda_id, lança o PagamentosVenda
     * pendente (a partir de stp_valor_pago — sem bandeira/autorização, que só
     * vinham no payload original do webhook) e tenta finalizar.
     *
     * @return bool true se a venda foi finalizada agora
     */
    public function recuperarVendaEPagamento(StonePedido $stonePedido, ?int $sessaoCaixaId = null): bool
    {
        $stonePedido = $stonePedido->fresh();
        $venda = $this->resolverOuCriarVenda($stonePedido, $sessaoCaixaId);

        if (! $venda) {
            return false;
        }

        if (blank($stonePedido->stp_venda_id)) {
            $stonePedido->forceFill(['stp_venda_id' => $venda->id])->save();
        }

        $jaLancado = PagamentosVenda::where('pg_venda_venda_id', $venda->id)
            ->where('pg_venda_tipo_integracao', 'integrated')
            ->exists();

        if (! $jaLancado && (float) $stonePedido->stp_valor_pago > 0) {
            PagamentosVenda::create([
                'pg_venda_venda_id' => $venda->id,
                'pg_venda_opcaopagamento_id' => $stonePedido->stp_opcaopagamento_id,
                'pg_venda_tipo_integracao' => 'integrated',
                'pg_venda_valor_pagamento' => $stonePedido->stp_valor_pago,
                'pg_venda_valor_recebido' => $stonePedido->stp_valor_pago,
                'pg_venda_valor_pago_pelo_cliente' => $stonePedido->stp_valor_pago,
                'pg_venda_valor_troco' => 0,
                'pg_venda_valor_acrescimo' => 0,
                'pg_venda_valor_desconto' => 0,
            ]);
            $this->vendas->atualizarValoresdaVenda($venda->id);
        }

        return $this->finalizarSePago($venda->fresh(), $stonePedido->fresh());
    }

    /**
     * Finaliza a venda quando o valor pago cobre o total, dando baixa nos
     * pedidos/sessões de mesa vinculados (via FinalizacaoVendaService — a
     * mesma rotina do PDV). Só se aplica a StonePedido de origem
     * Pedido/SessaoMesa; o fluxo do PDV (origem Venda) finaliza manualmente.
     *
     * @return bool true se a venda foi finalizada agora
     */
    public function finalizarSePago(Venda $venda, StonePedido $stonePedido): bool
    {
        if ($stonePedido->stp_origem === StonePedidoOrigem::Venda) {
            return false;
        }

        $venda = $venda->fresh();
        $total = round((float) $venda->venda_valor_total, 2);
        $pago = round((float) $venda->venda_valor_pago, 2);

        if ($venda->venda_status !== 'INICIADA' || $pago + self::TOLERANCIA_CENTAVOS < $total) {
            return false;
        }

        $pedidoIds = ItensPedido::where('item_pedido_venda_id', $venda->id)
            ->whereNotNull('item_pedido_pedido_id')
            ->distinct()
            ->pluck('item_pedido_pedido_id');

        $pedidosVinculados = Pedido::whereIn('id', $pedidoIds)->get();
        $idSessaoMesa = $pedidosVinculados->pluck('pedido_sessao_mesa_id')->filter()->unique()->values()->all();
        $idPedido = $pedidosVinculados->whereNull('pedido_sessao_mesa_id')->pluck('id')->all();

        try {
            app(FinalizacaoVendaService::class)->finalizar($venda, null, $idSessaoMesa, $idPedido);
        } catch (VendaNaoFinalizavelException|ValidationException $e) {
            Log::channel('stone')->error('Venda criada pelo webhook Stone não pôde ser finalizada automaticamente — segue aberta para conclusão manual', [
                'venda_id' => $venda->id, 'stone_pedido_id' => $stonePedido->id, 'erro' => $e->getMessage(),
            ]);

            return false;
        }

        // Mesma regra de NFC-e automática do PDV (forma de pagamento) — em
        // fila, depois do commit, para não segurar a resposta do webhook.
        EmitirNfeAutomaticaJob::dispatch($venda->id)->afterCommit();

        return true;
    }

    /**
     * @return Collection<int, Pedido>
     */
    private function resolverPedidosAlvo(StonePedido $stonePedido): Collection
    {
        if (filled($stonePedido->stp_sessao_mesa_id)) {
            // Rascunho de rodada (INICIADO) não foi enviado nem cobrado na maquininha.
            return Pedido::where('pedido_sessao_mesa_id', $stonePedido->stp_sessao_mesa_id)
                ->whereNotIn('pedido_status', ContaMesa::STATUS_FORA_DA_CONTA)
                ->get();
        }

        if (filled($stonePedido->stp_pedido_id)) {
            $pedido = Pedido::whereKey($stonePedido->stp_pedido_id)
                ->whereNotIn('pedido_status', ['CANCELADO', 'FINALIZADO'])
                ->first();

            return $pedido ? collect([$pedido]) : collect();
        }

        return collect();
    }

    /**
     * @param  Collection<int, Pedido>  $pedidosAlvo
     */
    private function resolverClienteId(StonePedido $stonePedido, Collection $pedidosAlvo): ?int
    {
        if (filled($stonePedido->stp_sessao_mesa_id)) {
            $clienteId = SessaoMesa::where('id', $stonePedido->stp_sessao_mesa_id)->value('sessao_mesa_cliente_id');
            if (filled($clienteId)) {
                return (int) $clienteId;
            }
        }

        $clienteId = $pedidosAlvo->pluck('pedido_cliente_id')->filter()->first();

        return $clienteId ? (int) $clienteId : null;
    }

    private function registrarErro(StonePedido $stonePedido, string $mensagem): void
    {
        $stonePedido->forceFill(['stp_erro' => $mensagem])->save();

        Log::channel('stone')->warning('StonePedido pago sem conseguir gerar a Venda automaticamente', [
            'stone_pedido_id' => $stonePedido->id,
            'origem' => $stonePedido->stp_origem?->value,
            'erro' => $mensagem,
        ]);
    }
}
