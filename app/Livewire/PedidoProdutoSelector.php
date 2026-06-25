<?php

namespace App\Livewire;

use App\Enums\ProdutoTipoEnum;
use App\Models\AdicionaisItemPedido;
use App\Models\Categoria;
use App\Models\ItensPedido;
use App\Models\Produto;
use Livewire\Attributes\Computed;
use Livewire\Component;

class PedidoProdutoSelector extends Component
{
    public ?int $pedidoId = null;
    public array $itensIniciais = [];

    public array $itens = [];

    public string $busca = '';
    public ?int $categoriaId = null;

    // Modal produto simples
    public bool $modalAberta = false;
    public ?int $produtoSelecionadoId = null;
    public ?array $produtoSelecionado = null;
    public float $quantidade = 1;
    public string $observacao = '';
    public array $adicionaisDisponiveis = [];
    public array $adicionaisSelecionados = [];

    // Modal de edição de item existente
    public bool $editModalAberta = false;
    public ?string $editItemId = null;
    public string $editObservacao = '';
    public array $editAdicionaisDisponiveis = [];
    public array $editAdicionaisSelecionados = [];

    public string $saveButtonLabel = 'Salvar Pedido';

    // Clientes da sessão de mesa (apenas quando vindo da view pedido_mesa)
    public array $sessaoMesaClientes = [];
    public ?int $clienteSelecionadoId = null;
    public ?int $editClienteId = null;

    // Modal sabores (meia a meia / terços)
    public bool $saboresModalAberta = false;
    public string $saboresCategoriaNome = '';
    public int $saboresModo = 1;
    public int $saboresMaxModo = 2;
    public array $saboresProdutos = [];
    public array $saboresSelecionados = [];

    public function mount(?int $pedidoId = null, array $itensIniciais = []): void
    {
        $this->pedidoId = $pedidoId;

        if ($pedidoId) {
            $this->carregarItensDB();
        } else {
            $this->itens = $itensIniciais;
        }
    }

    public function carregarItensDB(): void
    {
        $this->itens = ItensPedido::where('item_pedido_pedido_id', $this->pedidoId)
            ->where('item_pedido_status', 'INSERIDO')
            ->with(['produto.categoria', 'adicionaisItemPedido.adicional'])
            ->get()
            ->map(fn($item) => [
                'id'              => $item->id,
                'produto_id'      => $item->item_pedido_produto_id,
                'produto_nome'    => $item->produto?->produto_descricao ?? '—',
                'categoria_nome'  => $item->produto?->categoria?->categoria_nome ?? '',
                'produto_foto'    => $item->produto?->getImagemUrl(),
                'cliente_id'      => $item->item_pedido_cliente_id,
                'cliente_nome'    => $item->item_pedido_cliente_id
                    ? (collect($this->sessaoMesaClientes)->firstWhere('id', $item->item_pedido_cliente_id)['nome'] ?? null)
                    : null,
                'quantidade'      => (float) $item->item_pedido_quantidade,
                'valor_unitario'  => (float) $item->item_pedido_valor_unitario,
                'desconto_unit'   => $item->item_pedido_quantidade > 0
                    ? round((float) $item->item_pedido_desconto / (float) $item->item_pedido_quantidade, 4)
                    : 0,
                'valor'           => (float) $item->item_pedido_valor,
                'desconto'        => (float) $item->item_pedido_desconto,
                'adicionais_valor'=> (float) $item->item_pedido_valor_adicionais,
                'observacao'      => $item->item_pedido_observacao ?? '',
                'adicionais'      => $item->adicionaisItemPedido->map(fn($aip) => [
                    'id'    => $aip->aip_adicional_id,
                    'nome'  => $aip->adicional?->adicional_nome ?? '—',
                    'valor' => (float) $aip->aip_valor_unitario,
                ])->toArray(),
            ])
            ->toArray();
    }

    #[Computed]
    public function categorias()
    {
        $tiposVenda = [ProdutoTipoEnum::PRODUZIDO->value, ProdutoTipoEnum::REVENDA->value];

        return Categoria::with(['produtos' => fn ($q) => $q->whereIn('produto_tipo', $tiposVenda)->whereNotNull('produto_foto')])
            ->whereHas('produtos', fn ($q) => $q->whereIn('produto_tipo', $tiposVenda))
            ->orderBy('categoria_ordem')
            ->orderBy('categoria_nome')
            ->get();
    }

    #[Computed]
    public function produtos()
    {
        $tiposVenda = [ProdutoTipoEnum::PRODUZIDO->value, ProdutoTipoEnum::REVENDA->value];

        return Produto::with('categoria')
            ->whereIn('produto_tipo', $tiposVenda)
            ->when($this->busca, fn ($q) => $q->where('produto_descricao', 'like', "%{$this->busca}%"))
            ->when($this->categoriaId, fn ($q) => $q->where('produto_categoria_id', $this->categoriaId))
            ->orderBy('produto_ordem')
            ->orderBy('produto_descricao')
            ->limit(60)
            ->get();
    }

    public function selecionarProduto(int $produtoId): void
    {
        $produto = Produto::with(['ap_produto_id.adicional', 'categoria'])->find($produtoId);
        if (! $produto) {
            return;
        }

        // Categoria com seleção de sabores
        if ($produto->categoria?->categoria_permite_sabores) {
            $this->abrirSaboresModal($produtoId, $produto->categoria);
            return;
        }

        $precoVenda   = (float) $produto->produto_preco_venda;
        $precoPromo   = (float) $produto->produto_preco_promocional;
        $precoBase    = ($precoPromo > 0 && $precoPromo > $precoVenda) ? $precoPromo : $precoVenda;
        $descontoUnit = ($precoPromo > 0 && $precoPromo < $precoVenda) ? ($precoVenda - $precoPromo) : 0;

        $this->produtoSelecionadoId   = $produtoId;
        $this->produtoSelecionado     = [
            'id'            => $produto->id,
            'nome'          => $produto->produto_descricao,
            'categoria_nome'=> $produto->categoria?->categoria_nome ?? '',
            'foto'          => $produto->getImagemUrl(),
            'preco_venda'   => $precoVenda,
            'preco_base'    => $precoBase,
            'desconto_unit' => $descontoUnit,
        ];

        $this->quantidade             = 1;
        $this->observacao             = '';
        $this->adicionaisSelecionados = [];
        $this->adicionaisDisponiveis  = $produto->ap_produto_id
            ->filter(fn($ap) => $ap->adicional !== null)
            ->map(fn($ap) => [
                'id'    => $ap->adicional->id,
                'nome'  => $ap->adicional->adicional_nome,
                'valor' => (float) $ap->adicional->adicional_valor,
            ])
            ->toArray();

        $this->modalAberta = true;
    }

    // ── Quantidade modal simples ─────────────────────────────────────────────

    public function incrementarQuantidadeModal(): void
    {
        $this->quantidade++;
    }

    public function decrementarQuantidadeModal(): void
    {
        // Só decrementa em passo inteiro quando há mais de 1 unidade.
        // Frações (⅓/½) são definidas pelos botões dedicados.
        if ($this->quantidade > 1) {
            $this->quantidade = $this->quantidade - 1;
        }
    }

    public function setQuantidadeModal(float $valor): void
    {
        // Permite frações (⅓ = 0,3333, ½ = 0,5) sem forçar mínimo de 0,5.
        $this->quantidade = $valor > 0 ? round($valor, 4) : 1;
    }

    // ── Modal de sabores ─────────────────────────────────────────────────────

    protected function abrirSaboresModal(int $produtoId, Categoria $categoria): void
    {
        $tiposVenda = [ProdutoTipoEnum::PRODUZIDO->value, ProdutoTipoEnum::REVENDA->value];

        $produtos = Produto::where('produto_categoria_id', $categoria->id)
            ->whereIn('produto_tipo', $tiposVenda)
            ->orderBy('produto_descricao')
            ->get()
            ->map(fn($p) => [
                'id'            => $p->id,
                'nome'          => $p->produto_descricao,
                'codimentacao'  => $p->produto_codimentacao ?? null,
                'foto'          => $p->getImagemUrl(),
                'preco'         => $p->produto_preco_promocional > 0
                    ? (float) $p->produto_preco_promocional
                    : (float) $p->produto_preco_venda,
                'precoOriginal' => (float) $p->produto_preco_venda,
            ])
            ->toArray();

        $presel = collect($produtos)->firstWhere('id', $produtoId);

        $this->saboresCategoriaNome = $categoria->categoria_nome;
        $this->saboresMaxModo       = $categoria->categoria_max_sabores ?? 2;
        $this->saboresModo          = 1;
        $this->saboresProdutos      = $produtos;
        $this->saboresSelecionados  = $presel ? [$presel] : [];
        $this->saboresModalAberta   = true;

        if ($presel) {
            $this->js("setTimeout(() => document.getElementById('sabor-item-{$produtoId}')?.scrollIntoView({ behavior: 'smooth', block: 'center' }), 320)");
        }
    }

    public function setModoSabores(int $modo): void
    {
        $this->saboresModo         = $modo;
        $this->saboresSelecionados = [];
    }

    public function toggleSabor(int $produtoId): void
    {
        $produto = collect($this->saboresProdutos)->firstWhere('id', $produtoId);
        if (! $produto) {
            return;
        }

        $jaSelected = collect($this->saboresSelecionados)->contains('id', $produtoId);

        if ($jaSelected) {
            $this->saboresSelecionados = array_values(
                array_filter($this->saboresSelecionados, fn($s) => $s['id'] !== $produtoId)
            );
        } elseif ($this->saboresModo === 1) {
            $this->saboresSelecionados = [$produto];
        } elseif (count($this->saboresSelecionados) < $this->saboresModo) {
            $this->saboresSelecionados[] = $produto;
        }
    }

    public function confirmarSabores(): void
    {
        $sel = $this->saboresSelecionados;
        if (count($sel) !== $this->saboresModo) {
            return;
        }

        $numSabores = count($sel);
        // Quantidade distribuída em centésimos para somar exatamente 1 inteiro
        // (ex.: 1/3 → 0,33 + 0,33 + 0,34). O resto vai para os últimos sabores.
        $qtdPorItem = intdiv(100, $numSabores);
        $qtdExtra   = 100 % $numSabores;
        // Cada fração de sabor já é exibida como item próprio (com o nome do sabor),
        // então não gravamos o combo na observação — o campo fica livre para nota real.

        $clienteNome = $this->clienteSelecionadoId
            ? (collect($this->sessaoMesaClientes)->firstWhere('id', $this->clienteSelecionadoId)['nome'] ?? null)
            : null;

        foreach ($sel as $idx => $sabor) {
            // Quantidade da fração: resto distribuído nos últimos sabores (último = 0,34)
            $qtdFracao      = ($qtdPorItem + ($idx >= $numSabores - $qtdExtra ? 1 : 0)) / 100;

            $sPrecoOrig     = (float) $sabor['precoOriginal'];
            $sPreco         = (float) $sabor['preco'];
            // Preço base é sempre o maior entre original e promocional
            $sPrecoBase     = max($sPrecoOrig, $sPreco);
            $sDescUnit      = max(0.0, $sPrecoOrig - $sPreco);

            // Integer-cents distribution: garante que a soma das frações = preço/desconto exato
            $totalCentavos  = (int) round($sPrecoBase * 100);
            $centsPorItem   = intdiv($totalCentavos, $numSabores);
            $centsExtra     = $totalCentavos % $numSabores;
            $valorFracao    = ($centsPorItem + ($idx < $centsExtra ? 1 : 0)) / 100;

            $descCentavos   = (int) round($sDescUnit * 100);
            $descPorItem    = intdiv($descCentavos, $numSabores);
            $descExtra      = $descCentavos % $numSabores;
            $descontoFracao = ($descPorItem + ($idx < $descExtra ? 1 : 0)) / 100;

            // Valor líquido da fração: bruto da fração − desconto da fração (sabores não têm adicionais)
            $valorFracaoLiquido = round($valorFracao - $descontoFracao, 2);

            if ($this->pedidoId) {
                $itemModel = ItensPedido::create([
                    'item_pedido_pedido_id'        => $this->pedidoId,
                    'item_pedido_produto_id'       => $sabor['id'],
                    'item_pedido_cliente_id'       => $this->clienteSelecionadoId ?: null,
                    'item_pedido_quantidade'       => $qtdFracao,
                    'item_pedido_valor_unitario'   => $sPrecoBase,
                    'item_pedido_valor'            => $valorFracaoLiquido,
                    'item_pedido_desconto'         => $descontoFracao,
                    'item_pedido_valor_adicionais' => 0,
                    'item_pedido_observacao'       => null,
                    'item_pedido_status'           => 'INSERIDO',
                ]);
                $itemId = $itemModel->id;
            } else {
                $itemId = uniqid('tmp_');
            }

            $this->itens[] = [
                'id'              => $itemId,
                'produto_id'      => $sabor['id'],
                'produto_nome'    => $sabor['nome'],
                'categoria_nome'  => $this->saboresCategoriaNome,
                'produto_foto'    => $sabor['foto'] ?? null,
                'cliente_id'      => $this->clienteSelecionadoId ?: null,
                'cliente_nome'    => $clienteNome,
                'quantidade'      => $qtdFracao,
                'valor_unitario'  => $sPrecoBase,
                'desconto_unit'   => $sDescUnit,
                'valor'           => $valorFracaoLiquido,
                'desconto'        => $descontoFracao,
                'adicionais_valor'=> 0,
                'adicionais'      => [],
                'observacao'      => '',
            ];
        }

        $this->fecharSaboresModal();
        $this->notificarPai();
    }

    public function fecharSaboresModal(): void
    {
        $this->saboresModalAberta   = false;
        $this->saboresSelecionados  = [];
        $this->saboresProdutos      = [];
        $this->clienteSelecionadoId = null;
    }

    // ── Adicionais ───────────────────────────────────────────────────────────

    public function toggleAdicional(int $adicionalId): void
    {
        if (in_array($adicionalId, $this->adicionaisSelecionados)) {
            $this->adicionaisSelecionados = array_values(
                array_filter($this->adicionaisSelecionados, fn($id) => $id !== $adicionalId)
            );
        } else {
            $this->adicionaisSelecionados[] = $adicionalId;
        }
    }

    public function confirmarItem(): void
    {
        if (! $this->produtoSelecionado) {
            return;
        }

        $precoBase    = $this->produtoSelecionado['preco_base'];
        $descontoUnit = $this->produtoSelecionado['desconto_unit'];

        $adicionaisValor = 0;
        $adicionaisList  = [];
        foreach ($this->adicionaisDisponiveis as $adicional) {
            if (in_array($adicional['id'], $this->adicionaisSelecionados)) {
                $adicionaisValor += $adicional['valor'];
                $adicionaisList[] = $adicional;
            }
        }

        // Regra de negócio centralizada no model: valor líquido = (qtd × unit) − desconto + adicionais
        $linha         = ItensPedido::calcularLinha($this->quantidade, $precoBase, $descontoUnit, $adicionaisValor);
        $valorItem     = $linha['valor'];
        $descontoTotal = $linha['desconto'];

        $clienteNome = $this->clienteSelecionadoId
            ? (collect($this->sessaoMesaClientes)->firstWhere('id', $this->clienteSelecionadoId)['nome'] ?? null)
            : null;

        if ($this->pedidoId) {
            $itemModel = ItensPedido::create([
                'item_pedido_pedido_id'        => $this->pedidoId,
                'item_pedido_produto_id'       => $this->produtoSelecionadoId,
                'item_pedido_cliente_id'       => $this->clienteSelecionadoId ?: null,
                'item_pedido_quantidade'       => $this->quantidade,
                'item_pedido_valor_unitario'   => $precoBase,
                'item_pedido_valor'            => $valorItem,
                'item_pedido_desconto'         => $descontoTotal,
                'item_pedido_valor_adicionais' => $adicionaisValor,
                'item_pedido_observacao'       => $this->observacao ?: null,
                'item_pedido_status'           => 'INSERIDO',
            ]);

            foreach ($adicionaisList as $adicional) {
                AdicionaisItemPedido::create([
                    'aip_item_pedido_id' => $itemModel->id,
                    'aip_adicional_id'   => $adicional['id'],
                    'aip_quantidade'     => 1,
                    'aip_valor_unitario' => $adicional['valor'],
                    'aip_valor_total'    => $adicional['valor'],
                ]);
            }

            $itemId = $itemModel->id;
        } else {
            $itemId = uniqid('tmp_');
        }

        $this->itens[] = [
            'id'              => $itemId,
            'produto_id'      => $this->produtoSelecionadoId,
            'produto_nome'    => $this->produtoSelecionado['nome'],
            'categoria_nome'  => $this->produtoSelecionado['categoria_nome'] ?? '',
            'produto_foto'    => $this->produtoSelecionado['foto'],
            'cliente_id'      => $this->clienteSelecionadoId ?: null,
            'cliente_nome'    => $clienteNome,
            'quantidade'      => $this->quantidade,
            'valor_unitario'  => $precoBase,
            'desconto_unit'   => $descontoUnit,
            'valor'           => $valorItem,
            'desconto'        => $descontoTotal,
            'adicionais_valor'=> $adicionaisValor,
            'adicionais'      => $adicionaisList,
            'observacao'      => $this->observacao,
        ];

        $this->fecharModal();
        $this->notificarPai();
    }

    public function fecharModal(): void
    {
        $this->modalAberta            = false;
        $this->produtoSelecionado     = null;
        $this->produtoSelecionadoId   = null;
        $this->adicionaisDisponiveis  = [];
        $this->adicionaisSelecionados = [];
        $this->clienteSelecionadoId   = null;
    }

    // ── Quantidade itens na lista ────────────────────────────────────────────

    public function incrementarQtd(string $itemId): void
    {
        $this->ajustarQtd($itemId, +1);
    }

    public function decrementarQtd(string $itemId): void
    {
        $this->ajustarQtd($itemId, -1);
    }

    protected function ajustarQtd(string $itemId, float $delta): void
    {
        foreach ($this->itens as &$item) {
            if ((string) $item['id'] !== (string) $itemId) {
                continue;
            }

            // Incrementa/decrementa na grade da fração: inteiro (1), meia (½) ou
            // terço (⅓). Trabalha em "unidades da fração" para evitar resíduo
            // (ex.: ⅓ → 0,3333 → 0,6667 → 1,0), sem corromper a precisão.
            $qtdAtual = (float) $item['quantidade'];
            $fracPart = $qtdAtual - floor($qtdAtual);
            if ($fracPart < 0.01) {
                $denom = 1;          // item inteiro
            } elseif (abs($fracPart - 0.5) < 0.02) {
                $denom = 2;          // meia
            } else {
                $denom = 3;          // terço
            }

            $unidades = max(1, (int) round($qtdAtual * $denom) + (int) $delta);
            $novaQtd  = $denom === 1 ? (float) $unidades : round($unidades / $denom, 4);

            $item['quantidade'] = $novaQtd;
            $item['desconto']   = round(($item['desconto_unit'] ?? 0) * $novaQtd, 2);
            $item['valor']      = round(($item['valor_unitario'] * $novaQtd) - $item['desconto'] + $item['adicionais_valor'], 2);

            if ($this->pedidoId && is_numeric($itemId)) {
                ItensPedido::find($itemId)?->update([
                    'item_pedido_quantidade' => $novaQtd,
                    'item_pedido_valor'      => $item['valor'],
                    'item_pedido_desconto'   => $item['desconto'],
                ]);
            }
            break;
        }
        unset($item);

        $this->notificarPai();
    }

    public function removerItem(string $itemId): void
    {
        if ($this->pedidoId && is_numeric($itemId)) {
            ItensPedido::find($itemId)?->delete();
        }

        $this->itens = array_values(
            array_filter($this->itens, fn($i) => (string) $i['id'] !== (string) $itemId)
        );

        $this->notificarPai();
    }

    // ── Edição de item existente ─────────────────────────────────────────────

    public function abrirEditModal(string $itemId): void
    {
        $item = collect($this->itens)->firstWhere('id', $itemId);
        if (! $item) {
            return;
        }

        $this->editItemId                 = $itemId;
        $this->editObservacao             = $item['observacao'] ?? '';
        $this->editAdicionaisSelecionados = array_column($item['adicionais'] ?? [], 'id');
        $this->editClienteId              = $item['cliente_id'] ?? null;

        $produto = Produto::with('ap_produto_id.adicional')->find($item['produto_id']);
        $this->editAdicionaisDisponiveis = $produto?->ap_produto_id
            ->filter(fn($ap) => $ap->adicional !== null)
            ->map(fn($ap) => [
                'id'    => $ap->adicional->id,
                'nome'  => $ap->adicional->adicional_nome,
                'valor' => (float) $ap->adicional->adicional_valor,
            ])
            ->toArray() ?? [];

        $this->editModalAberta = true;
    }

    public function toggleEditAdicional(int $adicionalId): void
    {
        if (in_array($adicionalId, $this->editAdicionaisSelecionados)) {
            $this->editAdicionaisSelecionados = array_values(
                array_filter($this->editAdicionaisSelecionados, fn($id) => $id !== $adicionalId)
            );
        } else {
            $this->editAdicionaisSelecionados[] = $adicionalId;
        }
    }

    public function salvarEdicaoItem(): void
    {
        $itemId = $this->editItemId;
        if (! $itemId) {
            return;
        }

        $adicionaisList  = [];
        $adicionaisValor = 0;
        foreach ($this->editAdicionaisDisponiveis as $adicional) {
            if (in_array($adicional['id'], $this->editAdicionaisSelecionados)) {
                $adicionaisValor += $adicional['valor'];
                $adicionaisList[] = $adicional;
            }
        }

        $editClienteNome = $this->editClienteId
            ? (collect($this->sessaoMesaClientes)->firstWhere('id', $this->editClienteId)['nome'] ?? null)
            : null;

        foreach ($this->itens as &$item) {
            if ((string) $item['id'] !== (string) $itemId) {
                continue;
            }

            // Preserva o valor base (já distribuído em centavos nas frações de
            // sabor) e apenas troca os adicionais — evita re-arredondar a fração.
            $baseSemAdic             = round((float) $item['valor'] - (float) ($item['adicionais_valor'] ?? 0), 2);
            $item['observacao']      = $this->editObservacao;
            $item['adicionais']      = $adicionaisList;
            $item['adicionais_valor']= $adicionaisValor;
            $item['valor']           = round($baseSemAdic + $adicionaisValor, 2);
            $item['cliente_id']      = $this->editClienteId ?: null;
            $item['cliente_nome']    = $editClienteNome;
            break;
        }
        unset($item);

        if ($this->pedidoId && is_numeric($itemId)) {
            $itemModel = ItensPedido::find($itemId);
            if ($itemModel) {
                // Mantém o valor base distribuído (líquido sem adicionais) e
                // apenas soma os novos adicionais — preserva a fração de sabor.
                $baseSemAdic = round((float) $itemModel->item_pedido_valor - (float) $itemModel->item_pedido_valor_adicionais, 2);
                $novoValor   = round($baseSemAdic + $adicionaisValor, 2);
                $itemModel->update([
                    'item_pedido_observacao'       => $this->editObservacao ?: null,
                    'item_pedido_cliente_id'       => $this->editClienteId ?: null,
                    'item_pedido_valor_adicionais' => $adicionaisValor,
                    'item_pedido_valor'            => $novoValor,
                ]);

                AdicionaisItemPedido::where('aip_item_pedido_id', $itemId)->delete();
                foreach ($adicionaisList as $adicional) {
                    AdicionaisItemPedido::create([
                        'aip_item_pedido_id' => $itemId,
                        'aip_adicional_id'   => $adicional['id'],
                        'aip_quantidade'     => 1,
                        'aip_valor_unitario' => $adicional['valor'],
                        'aip_valor_total'    => $adicional['valor'],
                    ]);
                }
            }
        }

        $this->fecharEditModal();
        $this->notificarPai();
    }

    public function fecharEditModal(): void
    {
        $this->editModalAberta            = false;
        $this->editItemId                 = null;
        $this->editObservacao             = '';
        $this->editAdicionaisDisponiveis  = [];
        $this->editAdicionaisSelecionados = [];
        $this->editClienteId              = null;
    }

    protected function notificarPai(): void
    {
        $this->dispatch('itens-pedido-atualizados', itens: $this->itens);
    }

    public function render()
    {
        return view('livewire.pedido-produto-selector');
    }
}
