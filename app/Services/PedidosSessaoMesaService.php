<?php

namespace App\Services;

use App\Enums\StatusPedidoEnum;
use App\Enums\StonePedidoStatus;
use App\Models\Pedido;
use App\Models\SessaoMesa;
use App\Models\SessaoMesaCliente;
use App\Models\StonePedido;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Junta à conta de uma mesa um pedido já existente (balcão, cardápio, entrega
 * ou outra mesa) e tira dela um pedido lançado na mesa errada — o pedido
 * removido fica sem mesa e vai para "Pedidos avulsos" no caixa.
 *
 * Mesmas regras no /admin (Sessão da Mesa), no Painel do Garçom e na tela
 * legada da mesa. Pedido com item já lançado no caixa ou com cobrança Stone
 * aguardando não muda de conta: desalinharia a venda e o valor cobrado.
 */
class PedidosSessaoMesaService
{
    /** Status que não mudam de conta: rascunho, cancelado e já pago. */
    private const STATUS_FIXOS = [
        StatusPedidoEnum::INICIADO->value,
        StatusPedidoEnum::CANCELADO->value,
        StatusPedidoEnum::FINALIZADO->value,
    ];

    /** Pedidos que podem entrar na conta desta sessão, mais recentes primeiro. */
    public function queryElegiveis(SessaoMesa $sessao, ?string $busca = null): Builder
    {
        $busca = trim((string) $busca);

        return $this->queryMovivel()
            ->where(fn (Builder $q) => $q
                ->whereNull('pedido_sessao_mesa_id')
                ->orWhere('pedido_sessao_mesa_id', '!=', $sessao->id))
            ->where(fn (Builder $q) => $q
                ->whereNull('pedido_sessao_mesa_id')
                ->orWhereNotIn('pedido_sessao_mesa_id', $this->sessoesComStoneAguardando()))
            ->when($busca !== '', fn (Builder $q) => $q->where(fn (Builder $qq) => $qq
                ->when(ctype_digit(ltrim($busca, '#')), fn (Builder $id) => $id->orWhere('id', (int) ltrim($busca, '#')))
                ->orWhereHas('cliente', fn (Builder $c) => $c->where('cliente_nome', 'like', "%{$busca}%"))))
            ->with(['cliente:id,cliente_nome', 'garcom:id,name,name_first', 'sessaoMesa.mesa', 'opcaoEntrega'])
            ->latest('id');
    }

    /** @return Collection<int, Pedido> */
    public function elegiveisParaAdicionar(SessaoMesa $sessao, ?string $busca = null): Collection
    {
        return $this->queryElegiveis($sessao, $busca)->limit(50)->get();
    }

    /**
     * Opções do modal "Adicionar pedido existente" (CheckboxList): rótulo e
     * descrição de cada pedido elegível. Com $ator, esconde pedidos de mesas
     * que ele não pode alterar (adicionar() os pularia).
     *
     * @return array{opcoes: array<int, string>, descricoes: array<int, string>}
     */
    public function opcoesParaAdicionar(SessaoMesa $sessao, ?User $ator = null): array
    {
        $pedidos = $this->elegiveisParaAdicionar($sessao)
            ->filter(fn (Pedido $p) => ! $ator || ! $p->pedido_sessao_mesa_id || Gate::forUser($ator)->allows('update', $p->sessaoMesa));

        return [
            'opcoes' => $pedidos->mapWithKeys(fn (Pedido $p) => [$p->id => $this->rotulo($p)])->all(),
            'descricoes' => $pedidos->mapWithKeys(fn (Pedido $p) => [$p->id => $this->descricao($p)])->all(),
        ];
    }

    /** "#123 · R$ 58,00 · João" */
    public function rotulo(Pedido $pedido): string
    {
        return collect([
            '#'.$pedido->id,
            // O que entra na conta da mesa: sem a taxa de entrega.
            'R$ '.number_format((float) $pedido->pedido_valor_total - (float) $pedido->pedido_valor_frete, 2, ',', '.'),
            $pedido->pedido_cliente_id ? $pedido->cliente?->cliente_nome : null,
        ])->filter()->implode(' · ');
    }

    /** "PRONTO · 19:42 · Mesa 4 · Ana", com aviso quando há taxa de entrega. */
    public function descricao(Pedido $pedido): string
    {
        $onde = $pedido->pedido_sessao_mesa_id
            ? ($pedido->sessaoMesa?->mesa?->mesa_nome ?? 'Outra mesa')
            : ($pedido->pedido_opcaoentrega_id ? $pedido->opcaoEntrega?->opcaoentrega_nome : 'Sem mesa');

        $texto = collect([
            $pedido->pedido_status,
            $pedido->pedido_datahora_abertura?->format('d/m H:i'),
            $onde,
            $pedido->pedido_usuario_garcom_id ? ($pedido->garcom?->name_first ?: $pedido->garcom?->name) : null,
        ])->filter()->implode(' · ');

        if ((float) $pedido->pedido_valor_frete > 0) {
            $texto .= ' — taxa de entrega R$ '.number_format((float) $pedido->pedido_valor_frete, 2, ',', '.').' fica fora da conta da mesa';
        }

        return $texto;
    }

    /** Rodadas desta sessão que podem sair da conta. */
    public function queryRemoviveis(SessaoMesa $sessao): Builder
    {
        return $this->queryMovivel()
            ->where('pedido_sessao_mesa_id', $sessao->id)
            ->with(['cliente:id,cliente_nome', 'garcom:id,name,name_first', 'sessaoMesa.mesa', 'opcaoEntrega'])
            ->latest('id');
    }

    /** @return Collection<int, Pedido> */
    public function removiveis(SessaoMesa $sessao): Collection
    {
        return $this->queryRemoviveis($sessao)->get();
    }

    /**
     * Move os pedidos para a conta desta sessão. Pedido que deixou de ser
     * elegível desde que a lista foi aberta é pulado.
     *
     * @param  array<int, int|string>  $pedidoIds
     * @return int pedidos movidos
     *
     * @throws RuntimeException|AuthorizationException
     */
    public function adicionar(SessaoMesa $sessao, array $pedidoIds, User $ator): int
    {
        Gate::forUser($ator)->authorize('update', $sessao);

        $movidos = DB::transaction(function () use ($sessao, $pedidoIds, $ator) {
            $sessao = $this->travarSessaoAberta($sessao);
            $movidos = 0;

            foreach ($this->travarPedidos($pedidoIds) as $pedido) {
                if ($pedido->pedido_sessao_mesa_id === $sessao->id || ! $this->movivel($pedido)) {
                    continue;
                }

                $origem = $pedido->pedido_sessao_mesa_id ? SessaoMesa::find($pedido->pedido_sessao_mesa_id) : null;

                if ($origem && (Gate::forUser($ator)->denies('update', $origem) || $this->temStoneAguardando($origem))) {
                    continue;
                }

                $pedido->update(['pedido_sessao_mesa_id' => $sessao->id]);
                $this->incluirPessoasDosItens($sessao, $pedido);
                $movidos++;
            }

            return $movidos;
        });

        if ($movidos === 0) {
            throw new RuntimeException('Nenhum dos pedidos selecionados pode mais ser adicionado a esta mesa.');
        }

        return $movidos;
    }

    /**
     * Tira os pedidos da conta desta sessão — ficam sem mesa, em "Pedidos
     * avulsos" no caixa.
     *
     * @param  array<int, int|string>  $pedidoIds
     * @return int pedidos removidos
     *
     * @throws RuntimeException|AuthorizationException
     */
    public function remover(SessaoMesa $sessao, array $pedidoIds, User $ator): int
    {
        Gate::forUser($ator)->authorize('update', $sessao);

        $removidos = DB::transaction(function () use ($sessao, $pedidoIds) {
            $sessao = $this->travarSessaoAberta($sessao);
            $removidos = 0;

            foreach ($this->travarPedidos($pedidoIds) as $pedido) {
                if ($pedido->pedido_sessao_mesa_id !== $sessao->id || ! $this->movivel($pedido)) {
                    continue;
                }

                $pedido->update(['pedido_sessao_mesa_id' => null]);
                $removidos++;
            }

            return $removidos;
        });

        if ($removidos === 0) {
            throw new RuntimeException('Nenhum dos pedidos selecionados pode mais ser removido desta mesa.');
        }

        return $removidos;
    }

    /** Status ativo, nenhum item já lançado no caixa e sem cobrança Stone aguardando. */
    private function queryMovivel(): Builder
    {
        return Pedido::query()
            ->whereNotIn('pedido_status', self::STATUS_FIXOS)
            ->whereDoesntHave('item_pedido_pedido_id', fn (Builder $q) => $q
                ->where('item_pedido_status', 'INSERIDO')
                ->whereNotNull('item_pedido_venda_id'))
            ->whereNotIn('id', StonePedido::query()
                ->where('stp_status', StonePedidoStatus::Aguardando)
                ->whereNotNull('stp_pedido_id')
                ->select('stp_pedido_id'));
    }

    private function movivel(Pedido $pedido): bool
    {
        return $this->queryMovivel()->whereKey($pedido->getKey())->exists();
    }

    /** Subquery das sessões com cobrança na maquininha em andamento. */
    private function sessoesComStoneAguardando(): QueryBuilder
    {
        return StonePedido::query()
            ->where('stp_status', StonePedidoStatus::Aguardando)
            ->whereNotNull('stp_sessao_mesa_id')
            ->select('stp_sessao_mesa_id')
            ->toBase();
    }

    private function temStoneAguardando(SessaoMesa $sessao): bool
    {
        return StonePedido::where('stp_sessao_mesa_id', $sessao->id)
            ->where('stp_status', StonePedidoStatus::Aguardando)
            ->exists();
    }

    /** @throws RuntimeException */
    private function travarSessaoAberta(SessaoMesa $sessao): SessaoMesa
    {
        $sessao = SessaoMesa::whereKey($sessao->getKey())->lockForUpdate()->firstOrFail();

        if ($sessao->sessao_mesa_status !== 'ABERTA') {
            throw new RuntimeException('A conta desta mesa não está mais aberta.');
        }

        // O valor já foi enviado à maquininha — mudar a conta agora cobraria errado.
        if ($this->temStoneAguardando($sessao)) {
            throw new RuntimeException('Há uma cobrança na maquininha aguardando pagamento nesta mesa. Conclua ou cancele antes.');
        }

        return $sessao;
    }

    /**
     * @param  array<int, int|string>  $pedidoIds
     * @return Collection<int, Pedido>
     */
    private function travarPedidos(array $pedidoIds): Collection
    {
        $ids = collect($pedidoIds)->map(fn ($id) => (int) $id)->filter()->unique()->all();

        return Pedido::whereKey($ids)->orderBy('id')->lockForUpdate()->get();
    }

    /** Pessoas dos itens viram pessoas da mesa, para a conta por cliente fechar. */
    private function incluirPessoasDosItens(SessaoMesa $sessao, Pedido $pedido): void
    {
        $pedido->item_pedido_pedido_id()
            ->where('item_pedido_status', 'INSERIDO')
            ->whereNotNull('item_pedido_cliente_id')
            ->distinct()
            ->pluck('item_pedido_cliente_id')
            ->each(fn (int $clienteId) => SessaoMesaCliente::firstOrCreate([
                'smc_sessao_mesa_id' => $sessao->id,
                'smc_cliente_id' => $clienteId,
            ]));
    }
}
