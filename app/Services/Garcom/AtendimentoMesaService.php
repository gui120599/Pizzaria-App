<?php

namespace App\Services\Garcom;

use App\Enums\AcaoAutorizadaEnum;
use App\Enums\MotivoCancelamentoEnum;
use App\Enums\PedidoOrigemEnum;
use App\Enums\StatusPedidoEnum;
use App\Exceptions\AutorizacaoNegadaException;
use App\Models\ItensPedido;
use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\SessaoMesa;
use App\Models\User;
use App\Services\PedidoStatusService;
use App\Services\PromocaoAdicionalService;
use App\Services\PromocaoRelampagoService;
use App\Services\SessaoMesaService;
use App\Support\TotaisPedido;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Operações do Painel do Garçom sobre uma conta de mesa/comanda.
 *
 * Cada envio para a cozinha ("rodada") é um Pedido próprio: o garçom monta um
 * rascunho INICIADO (só dele, nesta sessão) e o envio o confirma para ABERTO.
 * Como o rascunho já está gravado, reenviar depois de uma queda de rede é
 * inofensivo — a segunda confirmação encontra o pedido já ABERTO e vira no-op.
 */
class AtendimentoMesaService
{
    public function __construct(
        private PedidoStatusService $status,
        private SessaoMesaService $sessoes,
        private AutorizacaoGerenteService $autorizacoes,
    ) {}

    /** Rascunho da próxima rodada deste garçom nesta sessão (cria se não houver). */
    public function rascunho(SessaoMesa $sessao, User $garcom): Pedido
    {
        $this->garantirSessaoAberta($sessao);

        return Pedido::query()
            ->where('pedido_sessao_mesa_id', $sessao->id)
            ->where('pedido_status', StatusPedidoEnum::INICIADO->value)
            ->where('pedido_usuario_garcom_id', $garcom->id)
            ->latest('id')
            ->first()
            ?? Pedido::create([
                'pedido_status' => StatusPedidoEnum::INICIADO->value,
                'pedido_origem' => PedidoOrigemEnum::MESA,
                'pedido_sessao_mesa_id' => $sessao->id,
                'pedido_usuario_garcom_id' => $garcom->id,
            ]);
    }

    /**
     * Envia a rodada para a cozinha (INICIADO → ABERTO), gravando os totais.
     *
     * @return bool false quando a rodada já tinha sido enviada (reenvio)
     *
     * @throws RuntimeException quando não há itens ou a sessão não está aberta
     */
    public function enviarRodada(Pedido $rascunho): bool
    {
        return DB::transaction(function () use ($rascunho) {
            $pedido = Pedido::whereKey($rascunho->getKey())->lockForUpdate()->firstOrFail();

            if ($pedido->pedido_status !== StatusPedidoEnum::INICIADO->value) {
                return false;
            }

            $this->garantirSessaoAberta($pedido->sessaoMesa);

            $itens = $pedido->item_pedido_pedido_id()->where('item_pedido_status', 'INSERIDO')->get();

            if ($itens->isEmpty()) {
                throw new RuntimeException('Adicione pelo menos um item antes de enviar.');
            }

            $totais = TotaisPedido::paraItens($itens, null);

            $pedido->fill([
                'pedido_valor_itens' => $totais['itens'],
                'pedido_valor_desconto' => $totais['desconto'],
                'pedido_valor_frete' => 0,
                'pedido_valor_total' => $totais['total'],
                'pedido_datahora_abertura' => Carbon::now(),
            ])->save();

            $this->status->confirmar($pedido);

            return true;
        });
    }

    /** Rodada pronta entregue na mesa (PRONTO → ENTREGUE). */
    public function marcarEntregue(Pedido $pedido, User $garcom): Pedido
    {
        return $this->status->marcarEntregue($pedido, $garcom);
    }

    /**
     * Cancela um item de rodada já enviada, com autorização (permissão ou PIN
     * de gerente). Último item restante cancela a rodada inteira.
     *
     * @throws RuntimeException|AutorizacaoNegadaException
     */
    public function cancelarItem(
        ItensPedido $item,
        User $solicitante,
        ?int $autorizadorId,
        ?string $pin,
        string $motivo,
    ): void {
        DB::transaction(function () use ($item, $solicitante, $autorizadorId, $pin, $motivo) {
            $item = ItensPedido::whereKey($item->getKey())->lockForUpdate()->firstOrFail();
            $pedido = Pedido::whereKey($item->item_pedido_pedido_id)->lockForUpdate()->firstOrFail();

            if ($item->item_pedido_status !== 'INSERIDO') {
                throw new RuntimeException('Este item já foi cancelado.');
            }

            if ($item->item_pedido_venda_id !== null) {
                throw new RuntimeException('Este item já foi lançado no caixa e não pode ser cancelado aqui.');
            }

            if ($pedido->sessaoMesa?->sessao_mesa_status !== 'ABERTA') {
                throw new RuntimeException('A conta desta mesa não está mais aberta.');
            }

            $this->autorizacoes->autorizar(
                AcaoAutorizadaEnum::CANCELAR_ITEM,
                $solicitante,
                $item,
                $autorizadorId,
                $pin,
                $motivo,
                [
                    'pedido_id' => $pedido->id,
                    'produto' => $item->nomeProduto(),
                    'quantidade' => (float) $item->item_pedido_quantidade,
                    'valor' => (float) $item->item_pedido_valor,
                ],
            );

            $removidos = [$item->id];

            if ($item->item_pedido_promocao_id) {
                app(PromocaoRelampagoService::class)->estornarItem($item);
            }

            if (! $item->item_pedido_origem_id) {
                $removidos = [...$removidos, ...app(PromocaoAdicionalService::class)->estornarItensDoGatilho($item)];
            } elseif ($item->item_pedido_promocao_adicional_regra_id) {
                app(PromocaoAdicionalService::class)->estornarItemOferta($item);
            }

            ItensPedido::whereIn('id', $removidos)->get()->each(fn (ItensPedido $removido) => $removido->update([
                'item_pedido_status' => 'REMOVIDO',
                'item_pedido_usuario_removeu' => $solicitante->id,
            ]));

            $restantes = $pedido->item_pedido_pedido_id()->where('item_pedido_status', 'INSERIDO')->get();

            if ($restantes->isEmpty()) {
                $this->status->cancelar($pedido, null, MotivoCancelamentoEnum::OUTRO);

                return;
            }

            $totais = TotaisPedido::paraItens($restantes, null);

            $pedido->update([
                'pedido_valor_itens' => $totais['itens'],
                'pedido_valor_desconto' => $totais['desconto'],
                'pedido_valor_total' => $totais['total'],
            ]);
        });
    }

    /** Marca que a mesa pediu a conta (ou desfaz). */
    public function solicitarConta(SessaoMesa $sessao, bool $solicitada = true): SessaoMesa
    {
        $this->garantirSessaoAberta($sessao);

        $sessao->update(['sessao_mesa_conta_solicitada_em' => $solicitada ? Carbon::now() : null]);

        return $sessao;
    }

    /**
     * Atualiza o número de pessoas com lock otimista: a versão que o garçom
     * tinha na tela precisa ser a atual.
     *
     * @throws RuntimeException em conflito de versão
     */
    public function alterarPessoas(SessaoMesa $sessao, int $pessoas, int $versaoEsperada): SessaoMesa
    {
        return $this->atualizarComVersao($sessao, $versaoEsperada, ['sessao_mesa_pessoas' => max(1, $pessoas)]);
    }

    /**
     * Remove a taxa de serviço da conta, com autorização.
     *
     * @throws RuntimeException|AutorizacaoNegadaException
     */
    public function removerTaxaServico(
        SessaoMesa $sessao,
        int $versaoEsperada,
        User $solicitante,
        ?int $autorizadorId,
        ?string $pin,
        ?string $motivo,
    ): SessaoMesa {
        return DB::transaction(function () use ($sessao, $versaoEsperada, $solicitante, $autorizadorId, $pin, $motivo) {
            $percentual = (float) $sessao->sessao_mesa_taxa_servico_percentual;

            $atualizada = $this->atualizarComVersao($sessao, $versaoEsperada, ['sessao_mesa_taxa_servico_percentual' => 0]);

            $this->autorizacoes->autorizar(
                AcaoAutorizadaEnum::REMOVER_TAXA_SERVICO,
                $solicitante,
                $sessao,
                $autorizadorId,
                $pin,
                $motivo,
                ['percentual_anterior' => $percentual],
            );

            return $atualizada;
        });
    }

    /** Volta a taxa de serviço padrão — não precisa de autorização. */
    public function restaurarTaxaServico(SessaoMesa $sessao, int $versaoEsperada): SessaoMesa
    {
        return $this->atualizarComVersao($sessao, $versaoEsperada, [
            'sessao_mesa_taxa_servico_percentual' => (float) config('pizzaria.salao.taxa_servico_percentual'),
        ]);
    }

    /**
     * Transfere a conta inteira para outra mesa/comanda livre, com autorização.
     *
     * @throws RuntimeException|AutorizacaoNegadaException
     */
    public function transferirMesa(
        SessaoMesa $sessao,
        int $novaMesaId,
        User $solicitante,
        ?int $autorizadorId,
        ?string $pin,
        ?string $motivo,
    ): SessaoMesa {
        return DB::transaction(function () use ($sessao, $novaMesaId, $solicitante, $autorizadorId, $pin, $motivo) {
            $this->garantirSessaoAberta($sessao);

            $mesaAnterior = $sessao->mesa;
            $novaMesa = Mesa::whereKey($novaMesaId)->lockForUpdate()->firstOrFail();

            if ($novaMesa->mesa_status !== 'LIBERADA') {
                throw new RuntimeException("{$novaMesa->mesa_nome} não está livre.");
            }

            $this->autorizacoes->autorizar(
                AcaoAutorizadaEnum::TRANSFERIR_MESA,
                $solicitante,
                $sessao,
                $autorizadorId,
                $pin,
                $motivo,
                [
                    'mesa_anterior' => $mesaAnterior?->mesa_nome,
                    'mesa_nova' => $novaMesa->mesa_nome,
                ],
            );

            return $this->sessoes->trocarMesa($sessao, $novaMesa->id);
        });
    }

    /**
     * @param  array<string, mixed>  $dados
     *
     * @throws RuntimeException em conflito de versão
     */
    private function atualizarComVersao(SessaoMesa $sessao, int $versaoEsperada, array $dados): SessaoMesa
    {
        $this->garantirSessaoAberta($sessao);

        $afetadas = SessaoMesa::whereKey($sessao->getKey())
            ->where('sessao_mesa_versao', $versaoEsperada)
            ->update($dados + ['sessao_mesa_versao' => $versaoEsperada + 1, 'updated_at' => Carbon::now()]);

        if ($afetadas === 0) {
            throw new RuntimeException('Outro garçom alterou esta conta agora há pouco. Confira os dados e tente de novo.');
        }

        return $sessao->refresh();
    }

    /** @throws RuntimeException */
    private function garantirSessaoAberta(?SessaoMesa $sessao): void
    {
        if (! $sessao || $sessao->fresh()?->sessao_mesa_status !== 'ABERTA') {
            throw new RuntimeException('A conta desta mesa não está mais aberta.');
        }
    }
}
