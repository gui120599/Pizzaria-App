<?php

namespace App\Services\Cozinha;

use App\Enums\Checklist\NaoConformidadeStatus;
use App\Enums\Cozinha\StatusItemProducao;
use App\Enums\Cozinha\StatusProducaoPedido;
use App\Models\ChecklistExecucao;
use App\Models\ChecklistModelo;
use App\Models\ChecklistNaoConformidade;
use App\Models\DispositivoCozinha;
use App\Models\Pedido;
use App\Models\ProducaoItem;
use App\Models\User;
use App\Services\Checklist\ChecklistsDoDiaService;
use App\Support\FormatoQuantidade;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;

/**
 * Leituras das telas do painel cozinha (Estação, Expedição): só consultas,
 * nenhuma transição — essas ficam no ProducaoService.
 */
class PainelCozinhaService
{
    /**
     * Cards da estação em três colunas (spec §6.4).
     *
     * @param  list<int>  $setorIds
     * @return array{fila: Collection<int, ProducaoItem>, preparo: Collection<int, ProducaoItem>, prontos: Collection<int, ProducaoItem>}
     */
    public function estacao(array $setorIds): array
    {
        $base = fn () => ProducaoItem::query()->doSetor($setorIds)->with('setor');

        $fila = $base()->naFila()->get();
        $preparo = $base()->emPreparo()->get();
        $prontos = $base()->prontosRecentes()->limit(30)->get();
        $ciencia = $base()->where('status', StatusItemProducao::Cancelado)->aguardandoCiencia()->orderBy('cancelado_em')->get();

        // Só o cancelado que já tinha começado espera o "Ciente" (scope
        // aguardandoCiencia): ele fica em "Em preparo", onde a pessoa está.
        return [
            'fila' => $fila,
            'preparo' => $preparo->concat($ciencia)->values(),
            'prontos' => $prontos,
        ];
    }

    /**
     * All-day count (spec §5.4): soma do que está na fila e em preparo por
     * "chave de produção". Pizza de sabores soma a fração de cada sabor
     * ("Calabresa · Pizza Grande: 2½") e conta as massas do tamanho.
     *
     * @param  BaseCollection<int, ProducaoItem>  $ativos
     * @return list<array{rotulo: string, quantidade: string, base: bool}>
     */
    public function allDay(BaseCollection $ativos): array
    {
        $contagem = [];
        $massas = [];
        $tamanhosDeSabor = [];

        foreach ($ativos as $item) {
            if (! in_array($item->status, [StatusItemProducao::NaFila, StatusItemProducao::EmPreparo], true)) {
                continue;
            }

            $snapshot = (array) $item->snapshot;
            $tamanho = $snapshot['tamanho'] ?? null;
            $quantidade = (float) $item->quantidade;
            $sabores = (array) ($snapshot['sabores'] ?? []);

            if ($sabores === []) {
                $chave = trim(($snapshot['produto'] ?? 'Item').($tamanho && $tamanho !== ($snapshot['produto'] ?? null) ? ' · '.$tamanho : ''));
                $contagem[$chave] = ($contagem[$chave] ?? 0) + $quantidade;
            } else {
                $tamanhosDeSabor[$tamanho] = true;

                foreach ($sabores as $sabor) {
                    $chave = trim($sabor['nome'].($tamanho ? ' · '.$tamanho : ''));
                    $contagem[$chave] = ($contagem[$chave] ?? 0) + $quantidade * ((float) $sabor['percentual'] / 100);
                }
            }

            if ($tamanho !== null) {
                $massas[$tamanho] = ($massas[$tamanho] ?? 0) + $quantidade;
            }
        }

        arsort($contagem);
        $linhas = [];

        foreach (array_intersect_key($massas, $tamanhosDeSabor) as $tamanho => $total) {
            $linhas[] = ['rotulo' => 'Massas · '.$tamanho, 'quantidade' => FormatoQuantidade::item($total), 'base' => true];
        }

        foreach ($contagem as $rotulo => $total) {
            $linhas[] = ['rotulo' => $rotulo, 'quantidade' => FormatoQuantidade::item($total), 'base' => false];
        }

        return $linhas;
    }

    /**
     * Pedidos com produção ativa para o passe (spec §6.6), do mais antigo
     * para o mais novo.
     *
     * @return Collection<int, Pedido>
     */
    public function expedicao(): Collection
    {
        return $this->pedidosComProducao()
            ->whereIn('pedido_producao_status', [
                StatusProducaoPedido::Aguardando->value,
                StatusProducaoPedido::EmProducao->value,
                StatusProducaoPedido::Pronto->value,
            ])
            // Pela idade do pedido (o mais antigo primeiro): ordenar pelo
            // início da produção poria o pedido ainda na fila (NULL) na frente.
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, Pedido> */
    public function despachados(int $minutos = 30): Collection
    {
        return $this->pedidosComProducao()
            ->whereHas('producaoItens', fn (Builder $q) => $q->where('expedido_em', '>=', now()->subMinutes($minutos)))
            ->where('pedido_producao_status', StatusProducaoPedido::Expedido->value)
            ->orderByDesc('pedido_producao_concluida_em')
            ->orderByDesc('id')
            ->limit(30)
            ->get();
    }

    /**
     * Itens do pedido que não passam pela cozinha (refrigerante): o passe
     * separa junto ("+ 2 Coca lata").
     *
     * @return BaseCollection<int, string>
     */
    public function itensSemProducao(Pedido $pedido): BaseCollection
    {
        $comProducao = $pedido->producaoItens->pluck('item_pedido_id')->unique()->all();

        return $pedido->item_pedido_pedido_id
            ->reject(fn ($item) => in_array($item->id, $comProducao, true))
            ->map(fn ($item) => FormatoQuantidade::item($item->item_pedido_quantidade).' '.($item->produto?->produto_descricao ?? 'Item'))
            ->values();
    }

    /**
     * Faixa de pendência de checklist na Estação (spec §5.10.7): amarela na
     * janela, vermelha faltando cozinha.checklists.aviso_minutos_antes_fim;
     * não conformidade crítica respondida neste tablet hoje também fica
     * vermelha até sair de "aberta".
     *
     * @return list<array{execucao_id: ?int, modelo_id: int, nome: string, cor: string, texto: string}>
     */
    public function pendenciasDeChecklist(User $user, ?DispositivoCozinha $dispositivo): array
    {
        $dia = ChecklistExecucao::dataOperacional();
        $aviso = (int) config('cozinha.checklists.aviso_minutos_antes_fim', 30);
        $cartoes = collect(app(ChecklistsDoDiaService::class)->para($user, ChecklistModelo::PAINEL_COZINHA, $dispositivo)['hoje'])
            ->whereIn('situacao', [ChecklistsDoDiaService::NA_JANELA, ChecklistsDoDiaService::EM_ANDAMENTO]);
        $modelos = ChecklistModelo::whereKey($cartoes->pluck('modelo_id'))->get()->keyBy('id');

        $faixas = $cartoes->map(function (array $cartao) use ($modelos, $dia, $aviso): array {
            $fim = $modelos->get($cartao['modelo_id'])?->fimDaJanela($dia);
            $restam = $fim === null ? null : (int) floor(now()->diffInMinutes($fim, absolute: false));

            return [
                'execucao_id' => $cartao['execucao_id'],
                'modelo_id' => $cartao['modelo_id'],
                'nome' => $cartao['nome'],
                'cor' => $restam !== null && $restam <= $aviso ? 'danger' : 'warning',
                'texto' => match (true) {
                    $restam === null => 'pendente hoje',
                    $restam < 0 => 'janela encerrada',
                    default => "faltam {$restam} min para o fim da janela",
                },
            ];
        })->values()->all();

        if ($dispositivo !== null) {
            $criticas = ChecklistNaoConformidade::query()
                ->where('critica', true)
                ->where('status', NaoConformidadeStatus::Aberta)
                ->whereHas('execucaoItem.execucao', fn (Builder $q) => $q->doDia($dia)->where('dispositivo_cozinha_id', $dispositivo->id))
                ->get();

            foreach ($criticas as $critica) {
                $faixas[] = ['execucao_id' => null, 'modelo_id' => 0, 'nome' => 'Item crítico não conforme', 'cor' => 'danger', 'texto' => $critica->descricao];
            }
        }

        return $faixas;
    }

    private function pedidosComProducao(): Builder
    {
        return Pedido::query()
            ->with([
                'sessaoMesa.mesa:id,mesa_nome',
                'cliente:id,cliente_nome',
                'opcaoEntrega:id,opcaoentrega_nome,opcaoentrega_requer_endereco',
                'producaoItens' => fn ($q) => $q
                    ->where('status', '!=', StatusItemProducao::Cancelado->value)
                    ->with('setor:id,nome,cor,icone')
                    ->orderBy('item_pedido_id')
                    ->orderBy('unidade_seq')
                    ->orderBy('etapa'),
                'item_pedido_pedido_id' => fn ($q) => $q->where('item_pedido_status', 'INSERIDO')->with('produto:id,produto_descricao'),
            ]);
    }
}
