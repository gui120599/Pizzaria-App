<?php

namespace App\Services\Cozinha;

use App\Enums\Cozinha\AcaoProducao;
use App\Enums\Cozinha\MotivoCancelamentoProducao;
use App\Enums\Cozinha\PrioridadeProducao;
use App\Enums\Cozinha\StatusItemProducao;
use App\Enums\TipoAtendimentoEnum;
use App\Models\Categoria;
use App\Models\FichaTecnica;
use App\Models\ItensPedido;
use App\Models\Pedido;
use App\Models\ProducaoEvento;
use App\Models\ProducaoItem;
use App\Models\Produto;
use App\Models\RotaProducao;
use App\Models\SetorProducao;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Traduz itens do pedido em itens de produção: resolve a rota (produto →
 * categoria e ancestrais → setor padrão), explode unidades conforme o setor de
 * cada etapa, monta o snapshot e mantém tudo em dia quando o pedido muda
 * (item novo, removido, quantidade, conteúdo). Idempotente: rodar duas vezes
 * não duplica nada (unique item+etapa+unidade+geração).
 */
class RoteadorProducao
{
    /** Linha criada há mais que isso conta como "pedido já na cozinha": item novo ganha o selo ADICIONAL. */
    private const SEGUNDOS_ITEM_ADICIONAL = 60;

    public function __construct(
        private ProducaoService $producao,
        private SincronizadorPedidoProducao $sincronizador,
        private PedidoStatusGateway $gateway,
        private CozinhaTempoReal $tempoReal,
    ) {}

    /** Gera (ou completa) a produção de todos os itens do pedido. */
    public function gerarParaPedido(Pedido $pedido): Collection
    {
        return DB::transaction(function () use ($pedido): Collection {
            $pedido = Pedido::whereKey($pedido->id)->lockForUpdate()->firstOrFail();

            if (! $this->gateway->liberaProducao($pedido)) {
                return collect();
            }

            $itens = ItensPedido::with(['produto.categoria', 'adicionaisItemPedido.adicional'])
                ->where('item_pedido_pedido_id', $pedido->id)
                ->where('item_pedido_status', 'INSERIDO')
                ->orderBy('id')
                ->get();

            $esperas = $this->esperasPorTempoDePreparo($itens);
            $setores = [];

            foreach ($itens as $item) {
                $setores = [...$setores, ...$this->sincronizarNoLock($pedido, $item, $esperas[$item->id] ?? 0)];
            }

            $this->sincronizador->recalcular($pedido);
            $this->tempoReal->tocar($setores, $pedido->id);

            return ProducaoItem::where('pedido_id', $pedido->id)->get();
        });
    }

    /** Reflete na produção uma mudança num item do pedido (observer do item e dos adicionais). */
    public function sincronizarItem(ItensPedido $item): void
    {
        // Atalho sem lock: rascunho do garçom (INICIADO) mexe em item o tempo todo.
        $pedido = Pedido::find($item->item_pedido_pedido_id);

        if ($pedido === null || (! $this->gateway->liberaProducao($pedido) && ! ProducaoItem::where('pedido_id', $pedido->id)->exists())) {
            return;
        }

        DB::transaction(function () use ($item): void {
            $pedido = Pedido::whereKey($item->item_pedido_pedido_id)->lockForUpdate()->first();

            if ($pedido === null) {
                return;
            }

            $temProducao = ProducaoItem::where('pedido_id', $pedido->id)->exists();

            if (! $this->gateway->liberaProducao($pedido) && ! $temProducao) {
                return;
            }

            $item = ItensPedido::with(['produto.categoria', 'adicionaisItemPedido.adicional'])->find($item->id);

            if ($item === null) {
                return;
            }

            $setores = $this->sincronizarNoLock($pedido, $item);

            if ($setores !== []) {
                $this->sincronizador->recalcular($pedido);
                $this->tempoReal->tocar($setores, $pedido->id);
            }
        });
    }

    /** Pedido cancelado: cancela tudo (os já iniciados exigem ciência no tablet). */
    public function cancelarPedido(Pedido $pedido, string $origem = 'painel_pedidos'): void
    {
        DB::transaction(function () use ($pedido, $origem): void {
            $pedido = Pedido::whereKey($pedido->id)->lockForUpdate()->firstOrFail();
            $setores = [];

            foreach (ProducaoItem::where('pedido_id', $pedido->id)->ativos()->get() as $linha) {
                $this->producao->cancelar($linha, MotivoCancelamentoProducao::PedidoCancelado, $origem, recalcular: false);
                $setores[] = $linha->setor_producao_id;
            }

            if ($setores !== []) {
                $this->sincronizador->recalcular($pedido);
                $this->tempoReal->tocar($setores, $pedido->id);
            }
        });
    }

    /**
     * Etapas da rota do item: [etapa, setor, tempo previsto]. Pizza de vários
     * sabores usa a rota do 1º sabor (o produto da linha); sabor com rota
     * diferente vai para o log (backlog: rota por sabor).
     *
     * @return list<array{etapa: int, setor: SetorProducao, tempo_previsto_min: ?int}>
     */
    public function resolverRota(ItensPedido $item): array
    {
        $produto = $item->produto;

        if ($produto === null || $produto->produto_dispensa_producao) {
            return [];
        }

        $rota = $this->rotaDoProduto($produto);

        if ($item->ehMultiSabor()) {
            foreach (array_slice($item->sabores(), 1) as $sabor) {
                $outro = Produto::with('categoria')->find($sabor['produto_id']);

                if ($outro !== null && $this->assinatura($this->rotaDoProduto($outro)) !== $this->assinatura($rota)) {
                    Log::warning('Cozinha: sabores com rotas de produção diferentes; usada a do 1º sabor.', [
                        'item_pedido_id' => $item->id,
                        'produto_id' => $outro->id,
                    ]);
                }
            }
        }

        return $rota;
    }

    /** @return array<string, mixed> */
    public function montarSnapshot(ItensPedido $item): array
    {
        $produto = $item->produto;
        $categoria = $produto?->categoria?->categoria_nome;
        $fichas = $this->fichasAtivas([$produto?->id, ...array_column($item->sabores(), 'produto_id')]);
        $observacao = filled($item->item_pedido_observacao) ? trim((string) $item->item_pedido_observacao) : null;

        $sabores = array_map(fn (array $sabor): array => [
            'nome' => $sabor['nome'],
            'fracao' => ItensPedido::rotuloFracao((float) $sabor['percentual'], ascii: true),
            'rotulo' => $sabor['rotulo'] ?? ItensPedido::fracaoPorExtenso((float) $sabor['percentual']),
            'percentual' => (float) $sabor['percentual'],
            'produto_id' => (int) $sabor['produto_id'],
            'ficha_tecnica_id' => $fichas->get((int) $sabor['produto_id'])?->id,
        ], $item->ehMultiSabor() ? $item->sabores() : []);

        $alergenos = $fichas->flatMap(fn (FichaTecnica $f) => (array) $f->alergenos)->unique()->values()->all();

        return [
            'produto' => $item->ehMultiSabor() ? ($categoria ?? $item->nomeProduto()) : ($produto?->produto_descricao ?? '—'),
            'produto_id' => $produto?->id,
            'categoria' => $categoria,
            'tamanho' => $categoria,
            'sabores' => $sabores,
            'respostas' => $item->linhasRespostas(),
            'borda' => $this->borda($item),
            'adicionais' => $item->adicionaisItemPedido->map(fn ($aip): array => [
                'adicional_id' => $aip->aip_adicional_id,
                'nome' => $aip->adicional?->adicional_nome ?? 'Adicional',
                'qtd' => (float) $aip->aip_quantidade,
                'aplica_em' => 'inteira',
            ])->values()->all(),
            'remocoes' => array_map(fn (string $ref): array => [
                'nome' => $ref,
                'opcional_ref' => $ref,
                'aplica_em' => 'inteira',
            ], self::remocoesDaObservacao($observacao)),
            'observacao' => $observacao,
            'alergenos' => $alergenos,
        ];
    }

    /**
     * "Sem cebola e sem azeitona, bem passada" → ['cebola', 'azeitona'].
     * Termos normalizados (minúsculas, sem acento) para casar com
     * opcional_ref / nome do insumo na ficha.
     *
     * @return list<string>
     */
    public static function remocoesDaObservacao(?string $observacao): array
    {
        if (blank($observacao)) {
            return [];
        }

        $texto = Str::lower(Str::ascii($observacao));
        preg_match_all('/\bsem\s+([a-z][a-z ]*?)(?=\s*(?:,|;|\.|\n|\be\b|\bsem\b|\bcom\b|$))/u', $texto, $achados);

        return array_values(array_unique(array_filter(array_map('trim', $achados[1]))));
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Sincroniza as linhas de um item já com o pedido travado. Devolve os
     * setores tocados (para o tempo real).
     *
     * @return list<int>
     */
    private function sincronizarNoLock(Pedido $pedido, ItensPedido $item, int $esperaSegundos = 0): array
    {
        $linhas = ProducaoItem::where('item_pedido_id', $item->id)->get();

        if ($this->gateway->estaCancelado($pedido)) {
            return $this->cancelarLinhas($linhas, MotivoCancelamentoProducao::PedidoCancelado);
        }

        if ($item->item_pedido_status !== 'INSERIDO' || (float) $item->item_pedido_quantidade <= 0) {
            return $this->cancelarLinhas($linhas, MotivoCancelamentoProducao::ItemRemovido);
        }

        $rota = $this->resolverRota($item);

        if ($rota === []) {
            return [];
        }

        $snapshot = $this->montarSnapshot($item);
        $snapshot['adicional'] = $linhas->isEmpty()
            ? $this->pedidoJaEstaNaCozinha($pedido)
            : (bool) ($linhas->first()->snapshot['adicional'] ?? false);

        $ficha = FichaTecnica::ativas()->where('produto_id', $item->item_pedido_produto_id)->first();
        $setores = [];

        foreach ($rota as $passo) {
            $setores = [...$setores, ...$this->sincronizarEtapa($pedido, $item, $passo, count($rota), $snapshot, $ficha, $linhas, $esperaSegundos)];
        }

        return $setores;
    }

    /**
     * @param  array{etapa: int, setor: SetorProducao, tempo_previsto_min: ?int}  $passo
     * @param  array<string, mixed>  $snapshot
     * @param  Collection<int, ProducaoItem>  $linhasDoItem
     * @return list<int>
     */
    private function sincronizarEtapa(
        Pedido $pedido,
        ItensPedido $item,
        array $passo,
        int $etapaTotal,
        array $snapshot,
        ?FichaTecnica $ficha,
        Collection $linhasDoItem,
        int $esperaSegundos,
    ): array {
        $setor = $passo['setor'];
        $etapa = $passo['etapa'];
        $unidades = self::unidades((float) $item->item_pedido_quantidade, $setor->explode_unidades);
        $linhas = $linhasDoItem->where('etapa', $etapa);
        $vivas = $linhas->reject(fn (ProducaoItem $l) => $l->status === StatusItemProducao::Cancelado);
        $tocados = [];

        // Uma linha "viva" por unidade: a da maior geração (refazer cria outra geração da mesma unidade).
        $porUnidade = $vivas->groupBy('unidade_seq')->map(fn (Collection $g) => $g->sortByDesc('geracao')->first())->sortKeys();

        // Excedentes: cancela primeiro as que ainda não começaram.
        $excedentes = $porUnidade->count() - count($unidades);

        if ($excedentes > 0) {
            $ordem = [StatusItemProducao::Aguardando, StatusItemProducao::NaFila, StatusItemProducao::EmPreparo, StatusItemProducao::Pronto];
            $cancelar = $porUnidade
                ->reject(fn (ProducaoItem $l) => $l->status === StatusItemProducao::Expedido)
                ->sortBy([fn (ProducaoItem $a, ProducaoItem $b) => array_search($a->status, $ordem, true) <=> array_search($b->status, $ordem, true), fn ($a, $b) => $b->unidade_seq <=> $a->unidade_seq])
                ->take($excedentes);

            foreach ($cancelar as $linha) {
                $this->producao->cancelar($linha, MotivoCancelamentoProducao::QuantidadeReduzida, 'painel_pedidos', recalcular: false);
                $tocados[] = $setor->id;
                $porUnidade->forget($linha->unidade_seq);
            }
        }

        $existentes = $porUnidade->values();
        // Unidade nova nunca reaproveita o número de uma cancelada.
        $proximoSeq = ((int) $linhas->max('unidade_seq')) + 1;

        foreach ($unidades as $indice => $quantidade) {
            $linha = $existentes->get($indice);

            if ($linha !== null) {
                if ($this->atualizarLinha($linha, $snapshot, $quantidade)) {
                    $tocados[] = $linha->setor_producao_id;
                }

                continue;
            }

            $this->criarLinha($pedido, $item, $setor, $etapa, $etapaTotal, $proximoSeq++, $quantidade, $snapshot, $ficha, $passo['tempo_previsto_min'], $esperaSegundos);
            $tocados[] = $setor->id;
        }

        return $tocados;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function criarLinha(
        Pedido $pedido,
        ItensPedido $item,
        SetorProducao $setor,
        int $etapa,
        int $etapaTotal,
        int $seq,
        float $quantidade,
        array $snapshot,
        ?FichaTecnica $ficha,
        ?int $tempoPrevisto,
        int $esperaSegundos,
    ): ProducaoItem {
        $segura = $etapa === 1 && $esperaSegundos > 0;
        $status = $etapa > 1 || $segura ? StatusItemProducao::Aguardando : StatusItemProducao::NaFila;

        $linha = ProducaoItem::create([
            'pedido_id' => $pedido->id,
            'item_pedido_id' => $item->id,
            'setor_producao_id' => $setor->id,
            'etapa' => $etapa,
            'etapa_total' => $etapaTotal,
            'unidade_seq' => $seq,
            'geracao' => 1,
            'quantidade' => $quantidade,
            'status' => $status,
            'prioridade' => $pedido->pedido_prioridade_producao ?? PrioridadeProducao::Normal,
            'snapshot' => $snapshot,
            'origem' => $pedido->pedido_origem,
            'referencia' => $this->referencia($pedido),
            'ficha_tecnica_id' => $ficha?->id,
            'ficha_versao' => $ficha?->versao,
            'tempo_previsto_min' => $tempoPrevisto,
            'liberar_em' => $segura ? now()->addSeconds($esperaSegundos) : null,
            'liberado_em' => $status === StatusItemProducao::NaFila ? now() : null,
        ]);

        ProducaoEvento::create([
            'producao_item_id' => $linha->id,
            'pedido_id' => $pedido->id,
            'setor_producao_id' => $setor->id,
            'acao' => AcaoProducao::Gerado,
            'para_status' => $status,
            'user_id' => auth()->id(),
            'dados' => $snapshot['adicional'] ? ['adicional' => true] : null,
        ]);

        return $linha;
    }

    /**
     * Conteúdo mudou (sabor, observação, adicional) ou a quantidade da linha
     * (lote/fração). Antes de iniciar: atualiza em silêncio. Depois: marca
     * ALTERADO, guarda o antes e exige ciência. Expedido não muda.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function atualizarLinha(ProducaoItem $linha, array $snapshot, float $quantidade): bool
    {
        $antes = $linha->snapshot;
        unset($antes['antes']);
        $snapshot['adicional'] = (bool) ($antes['adicional'] ?? false);
        $mudouConteudo = $this->conteudo($antes) !== $this->conteudo($snapshot);
        $mudouQuantidade = abs((float) $linha->quantidade - $quantidade) > 0.0001;

        if ((! $mudouConteudo && ! $mudouQuantidade) || $linha->status->ehTerminal()) {
            return false;
        }

        $jaIniciado = $linha->iniciado_em !== null || $linha->status === StatusItemProducao::Pronto;

        $this->producao->registrarAlteracao($linha, $jaIniciado ? $snapshot + ['antes' => $antes] : $snapshot, $quantidade, $jaIniciado);

        return true;
    }

    /**
     * @param  Collection<int, ProducaoItem>  $linhas
     * @return list<int>
     */
    private function cancelarLinhas(Collection $linhas, MotivoCancelamentoProducao $motivo): array
    {
        $tocados = [];

        foreach ($linhas as $linha) {
            if (! $linha->status->ehTerminal()) {
                $this->producao->cancelar($linha, $motivo, 'painel_pedidos', recalcular: false);
                $tocados[] = $linha->setor_producao_id;
            }
        }

        return $tocados;
    }

    /**
     * Quantidades por linha: setor que explode → uma linha por unidade (a
     * fração legada de 0,5 vira uma linha a mais); senão uma linha com tudo.
     *
     * @return list<float>
     */
    public static function unidades(float $quantidade, bool $explode): array
    {
        if ($quantidade <= 0) {
            return [];
        }

        if (! $explode || $quantidade < 1) {
            return [round($quantidade, 3)];
        }

        $inteiras = (int) floor($quantidade + 0.0001);
        $resto = round($quantidade - $inteiras, 3);

        return [...array_fill(0, $inteiras, 1.0), ...($resto > 0.0001 ? [$resto] : [])];
    }

    /**
     * Disparo por tempo de preparo: no pedido, o item mais rápido espera a
     * diferença para o mais demorado. Desligado por padrão.
     *
     * @param  Collection<int, ItensPedido>  $itens
     * @return array<int, int> item_pedido_id => segundos de espera
     */
    private function esperasPorTempoDePreparo(Collection $itens): array
    {
        if (! config('cozinha.disparo_por_tempo_preparo')) {
            return [];
        }

        $tempos = [];

        foreach ($itens as $item) {
            $rota = $this->resolverRota($item);

            if ($rota !== [] && ! ProducaoItem::where('item_pedido_id', $item->id)->exists()) {
                $tempos[$item->id] = (int) array_sum(array_map(fn (array $p) => (int) ($p['tempo_previsto_min'] ?? 0), $rota));
            }
        }

        if ($tempos === []) {
            return [];
        }

        $maior = max($tempos);

        return array_map(fn (int $tempo): int => ($maior - $tempo) * 60, $tempos);
    }

    /** @return list<array{etapa: int, setor: SetorProducao, tempo_previsto_min: ?int}> */
    private function rotaDoProduto(Produto $produto): array
    {
        $rotas = RotaProducao::with('setor')->ativas()->where('produto_id', $produto->id)->orderBy('etapa')->get();

        $categoria = $produto->categoria;
        $visitadas = [];

        while ($rotas->isEmpty() && $categoria !== null && ! in_array($categoria->id, $visitadas, true)) {
            $visitadas[] = $categoria->id;
            $rotas = RotaProducao::with('setor')->ativas()->where('categoria_id', $categoria->id)->orderBy('etapa')->get();
            $categoria = $categoria->categoria_pai_id ? Categoria::find($categoria->categoria_pai_id) : null;
        }

        $rotas = $rotas->filter(fn (RotaProducao $r) => $r->setor?->ativo);

        if ($rotas->isEmpty()) {
            $padrao = config('cozinha.setor_padrao')
                ? SetorProducao::ativos()->where('slug', config('cozinha.setor_padrao'))->first()
                : null;

            return $padrao === null ? [] : [['etapa' => 1, 'setor' => $padrao, 'tempo_previsto_min' => null]];
        }

        // Renumera 1..n: uma etapa desativada no meio não deixa buraco.
        return $rotas->values()->map(fn (RotaProducao $r, int $i): array => [
            'etapa' => $i + 1,
            'setor' => $r->setor,
            'tempo_previsto_min' => $r->tempo_previsto_min,
        ])->all();
    }

    /** @param  list<array{etapa: int, setor: SetorProducao, tempo_previsto_min: ?int}>  $rota */
    private function assinatura(array $rota): string
    {
        return implode(',', array_map(fn (array $p) => $p['setor']->id, $rota));
    }

    /** @return Collection<int, FichaTecnica> produto_id => ficha */
    private function fichasAtivas(array $produtoIds): Collection
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $produtoIds))));

        return $ids === [] ? collect() : FichaTecnica::ativas()->whereIn('produto_id', $ids)->get()->keyBy('produto_id');
    }

    private function borda(ItensPedido $item): ?string
    {
        foreach ($item->respostas() as $resposta) {
            if (str_contains(Str::lower(Str::ascii((string) $resposta['pergunta'])), 'borda')) {
                return implode(', ', array_column($resposta['opcoes'], 'nome')) ?: null;
            }
        }

        return null;
    }

    private function pedidoJaEstaNaCozinha(Pedido $pedido): bool
    {
        return ProducaoItem::where('pedido_id', $pedido->id)
            ->where('created_at', '<', now()->subSeconds(self::SEGUNDOS_ITEM_ADICIONAL))
            ->exists();
    }

    /** "Mesa 7", "Delivery #1234 — João", "Retirada #88 — Ana". */
    private function referencia(Pedido $pedido): string
    {
        $pedido->loadMissing(['sessaoMesa.mesa', 'cliente', 'opcaoEntrega']);
        $cliente = $pedido->pedido_cliente_id ? Str::limit((string) $pedido->cliente->cliente_nome, 30, '') : null;

        $mesa = (string) ($pedido->sessaoMesa?->mesa?->mesa_nome ?? '?');

        $referencia = match ($pedido->tipoAtendimento()) {
            // As mesas da casa já se chamam "Mesa 01": não repetir o prefixo.
            TipoAtendimentoEnum::MESA => Str::startsWith(Str::lower($mesa), 'mesa') ? $mesa : 'Mesa '.$mesa,
            TipoAtendimentoEnum::DELIVERY => "Delivery #{$pedido->id}".($cliente ? " — {$cliente}" : ''),
            TipoAtendimentoEnum::RETIRADA => "Retirada #{$pedido->id}".($cliente ? " — {$cliente}" : ''),
        };

        return Str::limit($referencia, 80, '');
    }

    /**
     * O que, se mudar, a cozinha precisa saber (exclui marcas internas).
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function conteudo(array $snapshot): array
    {
        $relevante = array_intersect_key($snapshot, array_flip(['produto_id', 'sabores', 'respostas', 'adicionais', 'observacao']));
        ksort($relevante);

        return json_decode(json_encode($relevante), true);
    }
}
