<?php

namespace App\Services;

use App\Enums\StatusLancamento;
use App\Enums\StonePedidoStatus;
use App\Enums\TipoLancamento;
use App\Exceptions\AlteracaoVendaException;
use App\Exceptions\VendaNaoFinalizavelException;
use App\Models\FechamentoCaixa;
use App\Models\ItensPedido;
use App\Models\Lancamento;
use App\Models\MovimentacoesSessaoCaixa;
use App\Models\Pedido;
use App\Models\SessaoCaixa;
use App\Models\StonePedido;
use App\Models\Venda;
use App\Models\VendaAlteracao;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Alteração completa de uma venda finalizada: itens (inclusive de pedidos e
 * mesas), cliente e pagamentos, feita no PDV (OperarVenda).
 *
 * reabrir() desfaz os efeitos da finalização (estoque da venda direta, entrada
 * de caixa, título fiado, pedidos e mesas) mantendo itens e pagamentos, guarda
 * um retrato em venda_alteracoes e deixa a venda INICIADA; o PDV edita e
 * finaliza de novo (o VendaObserver conclui a alteração). descartar() regrava
 * o retrato e reexecuta a finalização, voltando tudo ao original.
 */
class AlteracaoVendaService
{
    public function __construct(
        private readonly CorrecaoPagamentoService $correcoes,
        private readonly CancelamentoVendaService $cancelamento,
        private readonly FinalizacaoVendaService $finalizacao,
        private readonly MovimentacaoCaixaService $caixa,
        private readonly FechamentoCaixaService $fechamentos,
    ) {}

    /**
     * Por que esta venda finalizada não pode ser reaberta (null = pode).
     */
    public function motivoBloqueio(Venda $venda, ?int $operadorId = null): ?string
    {
        if ($venda->venda_status === 'INICIADA' && $venda->alteracaoAberta) {
            return 'A venda já está em alteração.';
        }

        if ($motivo = $this->correcoes->motivoBloqueioVenda($venda)) {
            return $motivo;
        }

        $operadorId ??= auth()->id();

        if (! $operadorId || ! SessaoCaixa::where('sessaocaixa_status', 'ABERTA')->where('sessaocaixa_user_id', $operadorId)->exists()) {
            return 'Abra uma sessão de caixa para operar a venda no PDV.';
        }

        if ($venda->pagamentos()->where('pg_venda_tipo_integracao', 'integrated')->exists() || StonePedido::where('stp_venda_id', $venda->id)->exists()) {
            return 'Venda com recebimento pela Stone: o estorno é feito na maquininha.';
        }

        if ($this->titulosFiado($venda)->get()->contains(fn (Lancamento $titulo): bool => $titulo->status !== StatusLancamento::Pendente || $titulo->pagamentos()->exists())) {
            return 'O título a receber desta venda já tem recebimento.';
        }

        return null;
    }

    /**
     * @throws AlteracaoVendaException
     */
    public function reabrir(Venda $venda, string $motivo, ?int $userId = null): VendaAlteracao
    {
        $userId ??= auth()->id();

        return DB::transaction(function () use ($venda, $motivo, $userId): VendaAlteracao {
            $venda = Venda::whereKey($venda->id)->lockForUpdate()->firstOrFail();

            if ($bloqueio = $this->motivoBloqueio($venda, $userId)) {
                throw new AlteracaoVendaException($bloqueio);
            }

            $alteracao = VendaAlteracao::create([
                'venda_id' => $venda->id,
                'user_id' => $userId,
                'motivo' => $motivo,
                'status' => VendaAlteracao::ABERTA,
                'antes' => $this->retrato($venda),
            ]);

            $this->cancelamento->devolverEstoqueVendaDireta($venda, $userId, "Reabertura para alteração — Venda #{$venda->id}");

            MovimentacoesSessaoCaixa::where('mov_venda_id', $venda->id)->where('mov_tipo', 'ENTRADA')->delete();
            $this->titulosFiado($venda)->delete();
            $this->cancelamento->reabrirPedidosEMesas($venda);

            $venda->update(['venda_status' => 'INICIADA', 'venda_datahora_finalizada' => null]);

            $this->atualizarCaixa($venda->venda_sessao_caixa_id);

            return $alteracao;
        });
    }

    /**
     * Restaura a venda como estava antes da reabertura e a finaliza de novo.
     *
     * @throws AlteracaoVendaException
     */
    public function descartar(Venda $venda): void
    {
        DB::transaction(function () use ($venda): void {
            $venda = Venda::whereKey($venda->id)->lockForUpdate()->firstOrFail();
            $alteracao = $venda->alteracaoAberta;

            if ($venda->venda_status !== 'INICIADA' || ! $alteracao) {
                throw new AlteracaoVendaException('Esta venda não está em alteração.');
            }

            $antes = $alteracao->antes;

            if (StonePedido::where('stp_venda_id', $venda->id)->whereIn('stp_status', StonePedidoStatus::pendentes())->exists()) {
                throw new AlteracaoVendaException('Há uma cobrança na maquininha aguardando pagamento. Conclua ou cancele antes de descartar.');
            }

            $idsAntes = collect($antes['pagamentos_vendas'])->pluck('id')->all();
            if ($venda->pagamentos()->where('pg_venda_tipo_integracao', 'integrated')->whereNotIn('id', $idsAntes)->exists()) {
                throw new AlteracaoVendaException('Houve recebimento pela Stone durante a alteração: estorne na maquininha antes de descartar.');
            }

            // Encerra antes de refinalizar para o observer não tratar a finalização como conclusão.
            $alteracao->encerrar(VendaAlteracao::DESCARTADA);

            $this->restaurarLinhas($venda, $antes);

            $fiado = $antes['fiado'][0] ?? null;
            ['sessoes' => $sessoes, 'pedidos' => $pedidos] = $this->pedidosDaVenda($venda->id);

            try {
                $this->finalizacao->finalizar(
                    $venda->fresh(),
                    null,
                    $sessoes,
                    $pedidos,
                    $fiado !== null,
                    $fiado ? Carbon::parse($fiado['vencimento']) : null,
                );
            } catch (VendaNaoFinalizavelException $e) {
                throw new AlteracaoVendaException('Não foi possível restaurar a venda: '.$e->getMessage());
            }

            // finalizar() carimba "agora": devolve o horário original.
            DB::table('vendas')->where('id', $venda->id)->update(['venda_datahora_finalizada' => $antes['venda']['venda_datahora_finalizada']]);

            $this->atualizarCaixa($venda->venda_sessao_caixa_id);
        });
    }

    /** Chamado pelo VendaObserver quando a venda reaberta é finalizada de novo. */
    public function registrarConclusao(Venda $venda): void
    {
        $alteracao = $venda->alteracaoAberta;

        if (! $alteracao) {
            return;
        }

        $totalAntes = (float) ($alteracao->antes['venda']['venda_valor_total'] ?? 0);

        $alteracao->encerrar(VendaAlteracao::CONCLUIDA, [
            'total_antes' => $totalAntes,
            'total_depois' => (float) $venda->venda_valor_total,
            'diferenca' => round((float) $venda->venda_valor_total - $totalAntes, 2),
            'itens' => $venda->itensVenda()->where('item_venda_status', 'INSERIDO')->count(),
            'pagamentos' => $venda->pagamentos()->count(),
            'cliente_id' => $venda->venda_cliente_id,
        ]);

        $this->atualizarCaixa($venda->venda_sessao_caixa_id);
    }

    /** Chamado pelo VendaObserver quando a venda em alteração é cancelada. */
    public function registrarCancelamento(Venda $venda): void
    {
        $venda->alteracaoAberta?->encerrar(VendaAlteracao::CANCELADA);
    }

    /** @return array<string, mixed> */
    private function retrato(Venda $venda): array
    {
        $linhas = fn ($consulta): array => $consulta->get()->map(fn ($linha): array => (array) $linha)->all();

        $itens = $linhas(DB::table('itens_vendas')->where('item_venda_venda_id', $venda->id));

        return [
            'venda' => (array) DB::table('vendas')->where('id', $venda->id)->first(),
            'itens_vendas' => $itens,
            'adicionais_item_vendas' => $linhas(DB::table('adicionais_item_vendas')->whereIn('aiv_item_venda_id', array_column($itens, 'id'))),
            'pagamentos_vendas' => $linhas(DB::table('pagamentos_vendas')->where('pg_venda_venda_id', $venda->id)),
            'vinculos_pedido' => $linhas(DB::table('itens_pedidos')->where('item_pedido_venda_id', $venda->id)->select('id', 'item_pedido_venda_id', 'item_pedido_item_venda_id')),
            'fiado' => $linhas(DB::table('lancamentos')->where('venda_id', $venda->id)->where('tipo', TipoLancamento::Receber->value)->whereNull('recebivel_id')),
        ];
    }

    /** @param  array<string, mixed>  $antes */
    private function restaurarLinhas(Venda $venda, array $antes): void
    {
        // Solta os vínculos e apaga o que está na venda agora; regrava o retrato com os ids originais.
        $idsVinculados = array_column($antes['vinculos_pedido'], 'id');
        ItensPedido::where('item_pedido_venda_id', $venda->id)->orWhereIn('id', $idsVinculados)
            ->update(['item_pedido_venda_id' => null, 'item_pedido_item_venda_id' => null]);

        $itensAtuais = DB::table('itens_vendas')->where('item_venda_venda_id', $venda->id)->pluck('id');
        DB::table('adicionais_item_vendas')->whereIn('aiv_item_venda_id', $itensAtuais)->delete();
        DB::table('itens_vendas')->where('item_venda_venda_id', $venda->id)->delete();
        DB::table('pagamentos_vendas')->where('pg_venda_venda_id', $venda->id)->delete();

        foreach (['itens_vendas', 'adicionais_item_vendas', 'pagamentos_vendas'] as $tabela) {
            if ($antes[$tabela] !== []) {
                DB::table($tabela)->insert($antes[$tabela]);
            }
        }

        foreach ($antes['vinculos_pedido'] as $vinculo) {
            DB::table('itens_pedidos')->where('id', $vinculo['id'])->update([
                'item_pedido_venda_id' => $vinculo['item_pedido_venda_id'],
                'item_pedido_item_venda_id' => $vinculo['item_pedido_item_venda_id'],
            ]);
        }

        $linhaVenda = $antes['venda'];
        unset($linhaVenda['id'], $linhaVenda['venda_status'], $linhaVenda['venda_datahora_finalizada'], $linhaVenda['created_at']);
        DB::table('vendas')->where('id', $venda->id)->update($linhaVenda);
    }

    /**
     * Sessões de mesa e pedidos avulsos ligados à venda, como na finalização do PDV.
     *
     * @return array{sessoes: array<int, int>, pedidos: array<int, int>}
     */
    private function pedidosDaVenda(int $vendaId): array
    {
        $pedidoIds = ItensPedido::where('item_pedido_venda_id', $vendaId)->whereNotNull('item_pedido_pedido_id')->distinct()->pluck('item_pedido_pedido_id');
        $pedidos = Pedido::whereIn('id', $pedidoIds)->get();

        return [
            'sessoes' => $pedidos->pluck('pedido_sessao_mesa_id')->filter()->unique()->values()->all(),
            'pedidos' => $pedidos->whereNull('pedido_sessao_mesa_id')->pluck('id')->all(),
        ];
    }

    /** Título fiado da venda (o que a finalização cria): a receber, sem vínculo de recebível. */
    private function titulosFiado(Venda $venda)
    {
        return Lancamento::query()->where('venda_id', $venda->id)->where('tipo', TipoLancamento::Receber)->whereNull('recebivel_id');
    }

    /** Recalcula o saldo da sessão original e o rascunho do fechamento, se houver. */
    private function atualizarCaixa(?int $sessaoId): void
    {
        $sessao = $sessaoId ? SessaoCaixa::find($sessaoId) : null;

        if (! $sessao) {
            return;
        }

        $this->caixa->recalcularSaldoFinal($sessao);

        $fechamento = FechamentoCaixa::query()->where('sessao_caixa_id', $sessao->id)->first();

        if ($fechamento) {
            $this->fechamentos->criarOuAtualizarRascunho($sessao, $fechamento);
        }
    }
}
