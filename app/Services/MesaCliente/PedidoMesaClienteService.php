<?php

namespace App\Services\MesaCliente;

use App\Enums\CanalLancamentoEnum;
use App\Enums\PedidoOrigemEnum;
use App\Enums\StatusAprovacaoPedidoEnum;
use App\Enums\StatusPedidoEnum;
use App\Exceptions\ComboSaboresInvalidoException;
use App\Exceptions\EstoqueInsuficienteException;
use App\Exceptions\ItemIndisponivelException;
use App\Exceptions\PerguntaNaoRespondidaException;
use App\Exceptions\PromocaoIndisponivelException;
use App\Models\MesaParticipante;
use App\Models\Pedido;
use App\Models\SessaoMesa;
use App\Services\Garcom\AtendimentoMesaService;
use App\Services\ItemSolicitado;
use App\Services\LancamentoItemPedidoService;
use App\Services\LinhaPrecificada;
use App\Support\TotaisPedido;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Rodada enviada pelo cliente no QR da mesa. Vira um Pedido da conta, como a
 * rodada do garçom: sem motivo de aprovação vai direto para a cozinha
 * (INICIADO → ABERTO, mesmo caminho do garçom); com motivo fica INICIADO +
 * aprovação PENDENTE — fora da conta, da cozinha e do caixa até o garçom decidir.
 *
 * O celular manda uma chave (uuid) por rodada: reenviar depois de uma queda de
 * rede devolve o mesmo pedido em vez de gravar outro.
 */
class PedidoMesaClienteService
{
    public function __construct(
        private LancamentoItemPedidoService $lancamento,
        private RegraAprovacaoMesa $regra,
        private ParticipanteMesaService $participantes,
        private AtendimentoMesaService $atendimento,
    ) {}

    /**
     * @param  list<ItemSolicitado>  $itens
     *
     * @throws RuntimeException|ItemIndisponivelException|ComboSaboresInvalidoException|PerguntaNaoRespondidaException|EstoqueInsuficienteException|PromocaoIndisponivelException
     */
    public function enviar(MesaParticipante $participante, array $itens, string $chave): Pedido
    {
        if ($existente = $this->pedidoDaChave($participante, $chave)) {
            return $existente;
        }

        $this->garantirQuePodePedir($participante, $itens);

        // O item é sempre lançado para o cliente identificado no celular.
        $linhas = array_map(
            fn (ItemSolicitado $item) => $this->lancamento->precificar($item->paraCliente($participante->mp_cliente_id), CanalLancamentoEnum::MESA_QR),
            $itens,
        );

        $this->lancamento->validarEstoque($linhas);
        $this->lancamento->validarLimitesPromocao($linhas);
        $this->lancamento->validarLimitesOferta($linhas);

        try {
            return DB::transaction(fn () => $this->gravar($participante, $linhas, $chave));
        } catch (UniqueConstraintViolationException $e) {
            // A mesma chave chegou duas vezes ao mesmo tempo: a outra gravou.
            return $this->pedidoDaChave($participante, $chave) ?? throw $e;
        }
    }

    /**
     * @param  list<LinhaPrecificada>  $linhas
     */
    private function gravar(MesaParticipante $participante, array $linhas, string $chave): Pedido
    {
        // Trava a conta: serializa a decisão de "primeiro pedido" entre
        // celulares da mesma mesa e garante que ela segue aberta.
        $sessao = SessaoMesa::whereKey($participante->mp_sessao_mesa_id)->lockForUpdate()->firstOrFail();

        if ($sessao->sessao_mesa_status !== 'ABERTA') {
            throw new RuntimeException('A conta desta mesa foi encerrada.');
        }

        $motivos = $this->regra->motivos($participante, $linhas);

        $pedido = Pedido::create([
            'pedido_status' => StatusPedidoEnum::INICIADO->value,
            'pedido_origem' => PedidoOrigemEnum::MESA_QR,
            'pedido_sessao_mesa_id' => $sessao->id,
            'pedido_mesa_participante_id' => $participante->id,
            'pedido_chave_idempotencia' => $chave,
            'pedido_cliente_id' => $participante->mp_cliente_id,
            // O garçom da mesa recebe o aviso de "pronto" desta rodada.
            'pedido_usuario_garcom_id' => $sessao->sessao_mesa_usuario_id,
            'pedido_aprovacao_status' => $motivos !== [] ? StatusAprovacaoPedidoEnum::PENDENTE : null,
            'pedido_aprovacao_motivos' => $motivos !== [] ? $motivos : null,
            'pedido_datahora_abertura' => Carbon::now(),
        ]);

        foreach ($linhas as $linha) {
            $item = $this->lancamento->gravar($pedido->id, $linha);
            $this->lancamento->gravarOferta($pedido->id, $item, $linha);
        }

        // Totais já no pendente: o garçom vê o valor do que vai aprovar.
        $totais = TotaisPedido::paraItens($pedido->item_pedido_pedido_id()->where('item_pedido_status', 'INSERIDO')->get(), null);
        $pedido->fill([
            'pedido_valor_itens' => $totais['itens'],
            'pedido_valor_desconto' => $totais['desconto'],
            'pedido_valor_frete' => 0,
            'pedido_valor_total' => $totais['total'],
        ])->save();

        if ($motivos === []) {
            $this->atendimento->enviarRodada($pedido);
        }

        return $pedido->fresh();
    }

    /**
     * @param  list<ItemSolicitado>  $itens
     *
     * @throws RuntimeException
     */
    private function garantirQuePodePedir(MesaParticipante $participante, array $itens): void
    {
        $sessao = $participante->sessaoMesa;

        if (! $sessao || $sessao->sessao_mesa_status !== 'ABERTA') {
            throw new RuntimeException('A conta desta mesa foi encerrada.');
        }

        if (! $this->participantes->pedidoPeloCelularLigado($sessao->mesa)) {
            throw new RuntimeException('O pedido pelo celular está desligado nesta mesa. Chame o garçom.');
        }

        if ($participante->estaBloqueado()) {
            throw new RuntimeException('Este celular não pode mais pedir nesta mesa. Chame o garçom.');
        }

        if ($itens === []) {
            throw new RuntimeException('Adicione pelo menos um item.');
        }

        $maximo = (int) config('pizzaria.mesa_cliente.quantidade_maxima');

        foreach ($itens as $item) {
            if ($item->quantidade <= 0 || ($maximo > 0 && $item->quantidade > $maximo)) {
                throw new RuntimeException("No máximo {$maximo} unidades de cada item por pedido. Para mais, chame o garçom.");
            }
        }

        $limite = (int) config('pizzaria.mesa_cliente.rodadas_por_10min');
        $recentes = Pedido::where('pedido_mesa_participante_id', $participante->id)
            ->where('created_at', '>=', Carbon::now()->subMinutes(10))
            ->count();

        if ($limite > 0 && $recentes >= $limite) {
            throw new RuntimeException('Muitos pedidos em pouco tempo. Aguarde alguns minutos ou chame o garçom.');
        }
    }

    /** @throws RuntimeException chave de outro celular */
    private function pedidoDaChave(MesaParticipante $participante, string $chave): ?Pedido
    {
        $pedido = Pedido::where('pedido_chave_idempotencia', $chave)->first();

        if ($pedido && (int) $pedido->pedido_mesa_participante_id !== (int) $participante->id) {
            throw new RuntimeException('Não foi possível registrar o pedido. Tente de novo.');
        }

        return $pedido;
    }
}
