<?php

namespace App\Filament\Pages;

use App\Enums\FormaPagamento;
use App\Enums\OperadoraMaquininha;
use App\Enums\StonePedidoModo;
use App\Enums\StonePedidoStatus;
use App\Exceptions\EstoqueInsuficienteException;
use App\Exceptions\NfeIoException;
use App\Exceptions\StoneConnectException;
use App\Exceptions\VendaNaoFinalizavelException;
use App\Models\AdicionaisItemPedido;
use App\Models\AdicionaisItemVenda;
use App\Models\CartoesPagamento;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\ItensPedido;
use App\Models\ItensVenda;
use App\Models\Lancamento;
use App\Models\Maquininha;
use App\Models\Mesa;
use App\Models\OpcoesPagamento;
use App\Models\PagamentosVenda;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\SessaoCaixa;
use App\Models\SessaoMesa;
use App\Models\StonePedido;
use App\Models\Venda;
use App\Services\EstoqueService;
use App\Services\FinalizacaoVendaService;
use App\Services\NfeIoService;
use App\Services\Stone\StoneRecebimentoService;
use App\Services\VendaService;
use App\Support\RateioCentavos;
use BackedEnum;
use Carbon\Carbon;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use UnitEnum;

/**
 * Tela operacional do PDV (balcão de vendas), substituindo as views blade
 * app.venda.index/edit. Ao contrário do VendaResource (CRUD administrativo
 * simples), esta página persiste cada ação direto no banco — mesmo padrão
 * já usado em App\Livewire\PedidoProdutoSelector — em vez de acumular
 * estado num form do Filament até o submit.
 */
class OperarVenda extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static ?string $navigationLabel = 'Nova Venda';

    protected static UnitEnum|string|null $navigationGroup = 'Comercial';

    protected static ?int $navigationSort = 9;

    protected static ?string $title = 'Venda';

    protected static ?string $slug = 'vendas/operar/{venda?}';

    protected string $view = 'filament.pages.operar-venda';

    /** Sessão de caixa aberta do usuário logado; obrigatória para operar o PDV. */
    public ?int $sessaoCaixaId = null;

    /** Null até o primeiro lançamento (criação lazy) ou venda INICIADA retomada via rota. */
    public ?int $vendaId = null;

    /** @var 'pedidos'|'mesas'|'produtos'|'pendentes' */
    public string $abaAtiva = 'produtos';

    public string $buscaMesa = '';

    public string $buscaPedido = '';

    public string $buscaPendentes = '';

    /**
     * Padrão inicial de expandido/recolhido dos cards de mesa/pedido —
     * persistido em sessão, mesmo mecanismo que o Filament usa em
     * ProdutosTable::persistFiltersInSession(). Cada card ainda pode ser
     * expandido/recolhido individualmente (estado local do Alpine, sem
     * round-trip); isso só afeta o estado com que cada card nasce.
     */
    public bool $abrirCardsPorPadrao = false;

    private const SESSION_KEY_CARDS_ABERTOS = 'operar_venda_cards_abertos';

    /** Aba que carrega ao abrir a página — persistida em sessão, mesmo mecanismo de SESSION_KEY_CARDS_ABERTOS. */
    private const SESSION_KEY_ABA_PADRAO = 'operar_venda_aba_padrao';

    // ── Aba Produtos (catálogo é só apresentação; a persistência mora aqui) ───
    /** Estoque insuficiente (modo BLOQUEAR) impede o lançamento do item. */
    public ?string $erroEstoque = null;

    /** Estoque baixo (modo AVISAR) apenas informa; o item é lançado normalmente. */
    public ?string $avisoEstoque = null;

    // ── Modal Cliente ────────────────────────────────────────────────────────
    public bool $modalClienteAberta = false;

    public string $buscaCliente = '';

    public ?string $clienteAdHocNome = null;

    public ?string $clienteAdHocCpf = null;

    public ?string $clienteAdHocCnpj = null;

    public ?string $clienteAdHocTelefone = null;

    public ?string $clienteAdHocEmail = null;

    // ── Modal Pagamento ──────────────────────────────────────────────────────
    public bool $modalPagamentoAberta = false;

    public ?int $opcaoPagamentoSelecionadaId = null;

    public ?int $cartaoId = null;

    public ?string $numeroAutorizacaoCartao = null;

    public float $valorPagamento = 0;

    public float $valorPagoPeloCliente = 0;

    /** Setado quando o modal está editando um pagamento já lançado (em vez de criar um novo). */
    public ?int $pagamentoEmEdicaoId = null;

    // ── Modal Cobrança na maquininha Stone ───────────────────────────────────
    public bool $modalStoneAberta = false;

    /** @var 'aguardando'|'pago'|'erro'|'cancelado'|null */
    public ?string $stoneStatusModal = null;

    public ?string $stoneErroModal = null;

    public ?int $stonePedidoId = null;

    /** Maquininha escolhida no modal de pagamento para formas integradas à Stone. */
    public ?int $stoneMaquininhaId = null;

    // ── Modal "Lançar pedido total na maquininha" (modelo Listado) ────────────
    public bool $modalStoneTotalAberta = false;

    public ?int $stoneTotalMaquininhaId = null;

    // ── Modal Dividir Conta ──────────────────────────────────────────────────
    public bool $modalDividirContaAberta = false;

    public int $dividirContaQtdPessoas = 2;

    public ?int $dividirContaOpcaoPagamentoId = null;

    // ── Cancelamento ─────────────────────────────────────────────────────────
    public bool $modalCancelarAberta = false;

    public ?string $motivoCancelamento = null;

    // ── Emissão de NFC-e (NFe.io) ────────────────────────────────────────────
    public bool $emitirNfeAoFinalizar = false;

    public bool $modalNfeAberta = false;

    /** @var 'processando'|'erro'|'emitido'|null */
    public ?string $nfeStatusModal = null;

    public ?string $nfeErroModal = null;

    public ?string $nfeInvoiceId = null;

    // ── Venda fiado / a prazo ────────────────────────────────────────────────
    public bool $vendaFiado = false;

    public ?string $fiadoVencimento = null;

    // ── Modal Registrar recebimento (aba Pendentes) ──────────────────────────
    public bool $modalRecebimentoAberto = false;

    public ?int $lancamentoRecebimentoId = null;

    public float $valorRecebimento = 0;

    public ?string $formaRecebimento = null;

    /** Bandeira/autorização do cartão — só conciliação interna, sem efeito na NF (ver Lancamento::registrarPagamento()). */
    public ?int $cartaoRecebimentoId = null;

    public ?string $numeroAutorizacaoRecebimento = null;

    public static function canAccess(): bool
    {
        return Auth::user()?->can('operar:venda') ?? false;
    }

    public function mount(?Venda $venda = null): void
    {
        $this->abrirCardsPorPadrao = (bool) session(self::SESSION_KEY_CARDS_ABERTOS, false);
        $this->abaAtiva = session(self::SESSION_KEY_ABA_PADRAO, 'produtos');
        $this->fiadoVencimento = now()->addDays(7)->toDateString();

        $user = Auth::user();

        $sessaoCaixa = SessaoCaixa::where('sessaocaixa_status', 'ABERTA')
            ->where('sessaocaixa_user_id', $user->id)
            ->first();

        if (! $sessaoCaixa) {
            session()->flash('error', 'Nenhuma sessão caixa está aberta para o usuário: '.$user->name_first.'!');
            $this->redirect(route('sessao_caixa'));

            return;
        }

        $this->sessaoCaixaId = $sessaoCaixa->id;

        // Rota com {venda?} ausente: o binding implícito do Livewire resolve
        // uma instância vazia (->exists === false), não null — por isso a
        // checagem é ->exists, não a truthiness do objeto.
        if ($venda?->exists) {
            $this->vendaId = $venda->id;
        }

        // Sem parâmetro: $this->vendaId fica null. A Venda só é criada no
        // primeiro lançamento real (ver iniciarVendaSeNecessario()) — igual
        // ao legado. Criar antecipadamente aqui queimaria o autoincrement
        // sequencial (usado como número da NF-e perante a SEFAZ) toda vez
        // que a página é aberta/recarregada sem nenhum item lançado.
    }

    /**
     * Cria a Venda no primeiro lançamento real (produto, item de mesa/pedido,
     * ou vínculo de cliente), preservando a sequência numérica da NF-e —
     * chamar sempre antes de qualquer persistência que dependa de vendaId.
     */
    private function iniciarVendaSeNecessario(): void
    {
        if ($this->vendaId !== null) {
            return;
        }

        $novaVenda = Venda::create([
            'venda_status' => 'INICIADA',
            'venda_sessao_caixa_id' => $this->sessaoCaixaId,
            'venda_datahora_iniciada' => now(),
        ]);

        $this->vendaId = $novaVenda->id;
    }

    /**
     * Reaproveita o cliente do pedido/mesa recém-lançado: só preenche
     * venda_cliente_id se a venda ainda não tiver cliente (o primeiro
     * pedido/mesa lançado "ganha" — lançamentos seguintes com cliente
     * diferente não sobrescrevem nem avisam, o operador troca manualmente
     * pelo modal de Cliente se precisar).
     */
    private function preencherClienteSeVazio(?int $clienteId): void
    {
        if ($clienteId === null) {
            return;
        }

        $venda = Venda::find($this->vendaId);
        if (! $venda || $venda->venda_cliente_id !== null) {
            return;
        }

        $venda->update(['venda_cliente_id' => $clienteId]);
    }

    #[On('carrinho-atualizado')]
    public function onCarrinhoAtualizado(): void
    {
        // Método vazio: a mera invocação via #[On] já força o re-render
        // desta Page, que relê o carrinho/totais do banco (Computed).
    }

    // ── Aba Produtos ─────────────────────────────────────────────────────────

    /**
     * Lança 1 unidade do produto na venda. Chamado pelo evento
     * `produto-selecionado`, disparado por App\Livewire\VendaProdutoSelector
     * (componente puramente de catálogo — não persiste nada por conta própria,
     * já que ele pode ser clicado antes de existir uma Venda). Mescla na linha
     * existente quando o produto já está no carrinho sem adicionais e sem
     * fração de meia unidade (mesmas condições do antigo
     * ItensVendaController::adicionarProduto), senão cria uma nova linha.
     */
    #[On('produto-selecionado')]
    public function adicionarProdutoAvulso(int $produtoId): void
    {
        $produto = Produto::find($produtoId);
        if (! $produto) {
            return;
        }

        $this->erroEstoque = null;
        $this->avisoEstoque = null;

        try {
            $avisos = app(EstoqueService::class)->validarDisponibilidade($produto, 1.0);
            if ($avisos !== []) {
                $this->avisoEstoque = implode(' | ', $avisos);
            }
        } catch (EstoqueInsuficienteException $e) {
            $this->erroEstoque = $e->getMessage();

            return;
        }

        $this->iniciarVendaSeNecessario();

        DB::transaction(function () use ($produto) {
            [$precoEfetivo, $descontoUnitario] = $this->precoEfetivoProduto($produto);

            $itemVenda = ItensVenda::where('item_venda_produto_id', $produto->id)
                ->where('item_venda_venda_id', $this->vendaId)
                ->where('item_venda_valor_adicionais', 0)
                ->where('item_venda_quantidade', '<>', 0.5)
                ->first();

            if ($itemVenda) {
                $itemVenda->item_venda_quantidade += 1;
                $itemVenda->item_venda_desconto += $descontoUnitario;
                $itemVenda->item_venda_valor += $precoEfetivo;
                $itemVenda->item_venda_quantidade_tributavel += 1;
                $this->somarTributosItemVenda($itemVenda, $produto, $precoEfetivo);
                $itemVenda->save();
            } else {
                ItensVenda::create($this->novoItemProdutoAvulsoPayload($produto, $precoEfetivo, $descontoUnitario));
            }

            $produto->increment('produto_qtd_vendas');
        });

        app(VendaService::class)->atualizarValoresdaVenda($this->vendaId);
        $this->limparCachesDoCarrinho();
    }

    public function fecharToastEstoque(): void
    {
        $this->erroEstoque = null;
        $this->avisoEstoque = null;
    }

    /**
     * @return array{0: float, 1: float} [preço efetivo, desconto unitário]
     */
    private function precoEfetivoProduto(Produto $produto): array
    {
        $precoVenda = (float) $produto->produto_preco_venda;
        $precoPromo = (float) ($produto->produto_preco_promocional ?? 0);
        $precoEfetivo = $precoPromo > 0 ? $precoPromo : $precoVenda;
        $descontoUnitario = $precoEfetivo < $precoVenda ? ($precoVenda - $precoEfetivo) : 0;

        return [$precoEfetivo, $descontoUnitario];
    }

    /**
     * @return array<string, mixed>
     */
    private function novoItemProdutoAvulsoPayload(Produto $produto, float $precoEfetivo, float $descontoUnitario): array
    {
        $nextItemNumber = (ItensVenda::where('item_venda_venda_id', $this->vendaId)->max('item_numero') ?? 0) + 1;

        return [
            'item_numero' => $nextItemNumber,
            'item_venda_venda_id' => $this->vendaId,
            'item_venda_produto_id' => $produto->id,
            'item_venda_quantidade' => 1,
            'item_venda_valor_unitario' => $precoEfetivo,
            'item_venda_desconto' => $descontoUnitario,
            'item_venda_valor' => $precoEfetivo,
            'item_venda_status' => 'INSERIDO',
            'item_venda_quantidade_tributavel' => 1,
            'item_venda_valor_unitario_tributavel' => $precoEfetivo,
            'item_venda_valor_base_calculo' => $precoEfetivo,
            'item_venda_valor_icms' => ($precoEfetivo * $produto->produto_valor_percentual_icms) / 100,
            'item_venda_valor_pis' => ($precoEfetivo * $produto->produto_valor_percentual_pis) / 100,
            'item_venda_valor_cofins' => ($precoEfetivo * $produto->produto_valor_percentual_cofins) / 100,
            'item_venda_valor_total_tributos' => ($precoEfetivo * ($produto->produto_valor_percentual_icms + $produto->produto_valor_percentual_pis + $produto->produto_valor_percentual_cofins)) / 100,
        ];
    }

    #[Computed]
    public function venda(): ?Venda
    {
        return Venda::find($this->vendaId);
    }

    #[Computed]
    public function itensCarrinho()
    {
        // Sem venda ainda (criação lazy): where('...', null) do Eloquent vira
        // whereNull, o que traria registros órfãos de outras vendas em vez de
        // uma lista vazia — corta o caso fora antes da query.
        if ($this->vendaId === null) {
            return collect();
        }

        return ItensVenda::with(['produto.categoria', 'adicionaisItemVenda.adicional'])
            ->where('item_venda_venda_id', $this->vendaId)
            ->where('item_venda_status', 'INSERIDO')
            ->orderBy('item_numero')
            ->get();
    }

    public function atualizarQtdItem(int $itemVendaId, float $novaQtd): void
    {
        $itemVenda = ItensVenda::find($itemVendaId);
        if (! $itemVenda || $novaQtd <= 0) {
            return;
        }

        $precoBase = $this->precoBaseParaDesconto($itemVenda);
        // Desconto escala com a quantidade: preserva o desconto POR UNIDADE
        // (não o valor total fixo) para que dobrar a quantidade dobre o
        // desconto correspondente, em vez de diluí-lo.
        $qtdAnterior = (float) $itemVenda->item_venda_quantidade;
        $descontoPorUnidade = $qtdAnterior > 0 ? ((float) $itemVenda->item_venda_desconto / $qtdAnterior) : 0.0;
        $desconto = round($descontoPorUnidade * $novaQtd, 2);
        // Adicional é cobrado pela própria quantidade (1 porção), não pela
        // quantidade do produto — soma cheio, sem escalar pela nova quantidade.
        $adicionais = (float) $itemVenda->item_venda_valor_adicionais;
        $valorBase = round(($precoBase * $novaQtd) + $adicionais - $desconto, 2);

        $itemVenda->item_venda_quantidade = $novaQtd;
        $itemVenda->item_venda_quantidade_tributavel = $novaQtd;
        $itemVenda->item_venda_desconto = $desconto;
        $itemVenda->item_venda_valor_base_calculo = $valorBase;
        $itemVenda->item_venda_valor = $valorBase;
        $itemVenda->item_venda_valor_icms = round($valorBase * $itemVenda->produto->produto_valor_percentual_icms / 100, 4);
        $itemVenda->item_venda_valor_pis = round($valorBase * $itemVenda->produto->produto_valor_percentual_pis / 100, 4);
        $itemVenda->item_venda_valor_cofins = round($valorBase * $itemVenda->produto->produto_valor_percentual_cofins / 100, 4);
        $itemVenda->save();

        app(VendaService::class)->atualizarValoresdaVenda($this->vendaId);
        unset($this->itensCarrinho, $this->venda);
    }

    public function atualizarDescontoItem(int $itemVendaId, float $novoDesconto): void
    {
        $itemVenda = ItensVenda::find($itemVendaId);
        if (! $itemVenda || $novoDesconto < 0) {
            return;
        }

        $this->aplicarDescontoNoItem($itemVenda, $novoDesconto);

        app(VendaService::class)->atualizarValoresdaVenda($this->vendaId);
        unset($this->itensCarrinho, $this->venda);
    }

    public function removerItemCarrinho(int $itemVendaId): void
    {
        $itemVenda = ItensVenda::find($itemVendaId);
        if (! $itemVenda) {
            return;
        }

        $itemVenda->adicionaisItemVenda()->delete();
        $itemVenda->delete();

        app(VendaService::class)->atualizarValoresdaVenda($this->vendaId);
        unset($this->itensCarrinho, $this->venda);
    }

    /**
     * Aplica o novo valor de desconto (R$) a um item de venda, recalculando
     * a base de cálculo, o valor líquido e os tributos proporcionalmente.
     * Espelha ItensVendaController::aplicarDescontoNoItem (legado).
     */
    private function aplicarDescontoNoItem(ItensVenda $itemVenda, float $novoDesconto): void
    {
        $precoBaseDesconto = $this->precoBaseParaDesconto($itemVenda);

        $adicionaisDesconto = (float) $itemVenda->item_venda_valor_adicionais;
        $itemVenda->item_venda_valor_base_calculo = ($precoBaseDesconto * $itemVenda->item_venda_quantidade) + $adicionaisDesconto - $novoDesconto;
        $itemVenda->item_venda_desconto = $novoDesconto;
        $itemVenda->item_venda_valor = $itemVenda->item_venda_valor_base_calculo;
        $itemVenda->item_venda_valor_icms = ($itemVenda->item_venda_valor_base_calculo * $itemVenda->produto->produto_valor_percentual_icms) / 100;
        $itemVenda->item_venda_valor_pis = ($itemVenda->item_venda_valor_base_calculo * $itemVenda->produto->produto_valor_percentual_pis) / 100;
        $itemVenda->item_venda_valor_cofins = ($itemVenda->item_venda_valor_base_calculo * $itemVenda->produto->produto_valor_percentual_cofins) / 100;

        $itemVenda->save();
    }

    private function precoBaseParaDesconto(ItensVenda $itemVenda): float
    {
        $produto = $itemVenda->produto;

        return ($produto->produto_preco_promocional > 0 && $produto->produto_preco_promocional > $produto->produto_preco_venda)
            ? (float) $produto->produto_preco_promocional
            : (float) $produto->produto_preco_venda;
    }

    // ── Desconto percentual da venda + frete ────────────────────────────────

    /**
     * Aplica um desconto percentual sobre o valor bruto de todos os itens
     * ativos da venda, distribuindo o total proporcionalmente entre eles
     * (sem perder centavos) e somando ao desconto que cada item já tiver.
     * Só pode ser aplicado uma vez por venda — para reaplicar é preciso
     * primeiro desfazer o desconto já lançado. Espelha
     * ItensVendaController::aplicarDescontoPercentualVenda (legado).
     */
    public function aplicarDescontoPercentual(float $percentual): void
    {
        if ($percentual <= 0 || $percentual > 100) {
            return;
        }

        $venda = Venda::find($this->vendaId);
        if (! $venda || ! is_null($venda->venda_desconto_percentual)) {
            return;
        }

        DB::transaction(function () use ($venda, $percentual) {
            $itens = ItensVenda::with('produto')
                ->where('item_venda_venda_id', $venda->id)
                ->where('item_venda_status', 'INSERIDO')
                ->lockForUpdate()
                ->get();

            if ($itens->isEmpty()) {
                return;
            }

            $pesosCents = [];
            foreach ($itens as $item) {
                $precoBase = $this->precoBaseParaDesconto($item);
                $valorBruto = ($precoBase * $item->item_venda_quantidade) + (float) $item->item_venda_valor_adicionais;
                $pesosCents[$item->id] = (int) round($valorBruto * 100);
            }

            $totalCents = array_sum($pesosCents);
            if ($totalCents <= 0) {
                return;
            }

            $descontoTotalCents = (int) round($totalCents * $percentual / 100);
            $rateio = RateioCentavos::ratearProporcional($descontoTotalCents, $pesosCents);

            foreach ($itens as $item) {
                $descontoAdicional = ($rateio[$item->id] ?? 0) / 100;
                $novoDesconto = (float) $item->item_venda_desconto + $descontoAdicional;
                $item->item_venda_desconto_percentual_aplicado = $descontoAdicional;
                $this->aplicarDescontoNoItem($item, $novoDesconto);
            }

            $venda->venda_desconto_percentual = $percentual;
            $venda->save();
        });

        app(VendaService::class)->atualizarValoresdaVenda($this->vendaId);
        $this->limparCachesDoCarrinho();
    }

    /**
     * Desfaz a última aplicação de desconto percentual da venda: subtrai de
     * cada item exatamente o valor que aquela aplicação somou (preservando
     * qualquer desconto anterior, seja do pedido ou manual) e libera a trava
     * para uma nova aplicação percentual. Espelha
     * ItensVendaController::desfazerDescontoPercentualVenda (legado).
     */
    public function desfazerDescontoPercentual(): void
    {
        $venda = Venda::find($this->vendaId);
        if (! $venda || is_null($venda->venda_desconto_percentual)) {
            return;
        }

        DB::transaction(function () use ($venda) {
            $itens = ItensVenda::with('produto')
                ->where('item_venda_venda_id', $venda->id)
                ->where('item_venda_status', 'INSERIDO')
                ->lockForUpdate()
                ->get();

            foreach ($itens as $item) {
                if ((float) $item->item_venda_desconto_percentual_aplicado <= 0) {
                    continue;
                }

                $novoDesconto = max(0, (float) $item->item_venda_desconto - (float) $item->item_venda_desconto_percentual_aplicado);
                $item->item_venda_desconto_percentual_aplicado = 0;
                $this->aplicarDescontoNoItem($item, $novoDesconto);
            }

            $venda->venda_desconto_percentual = null;
            $venda->save();
        });

        app(VendaService::class)->atualizarValoresdaVenda($this->vendaId);
        $this->limparCachesDoCarrinho();
    }

    public function atualizarFrete(float $valor): void
    {
        $venda = Venda::find($this->vendaId);
        if (! $venda || $valor < 0) {
            return;
        }

        $venda->venda_valor_frete = $valor;
        $venda->venda_valor_total = $venda->venda_valor_itens == 0
            ? $valor
            : $venda->venda_valor_itens + $valor - $venda->venda_valor_desconto;
        $venda->save();

        $this->limparCachesDoCarrinho();
    }

    /**
     * Alterna o padrão de expandido/recolhido dos cards de mesa/pedido e
     * grava em sessão (sobrevive a reload, mesmo mecanismo do Filament em
     * ProdutosTable::persistFiltersInSession() — não é por usuário entre
     * dispositivos, só pela sessão HTTP atual).
     */
    public function alternarPadraoCards(): void
    {
        $this->abrirCardsPorPadrao = ! $this->abrirCardsPorPadrao;
        session([self::SESSION_KEY_CARDS_ABERTOS => $this->abrirCardsPorPadrao]);
    }

    /** Fixa a aba atualmente ativa como a que carrega da próxima vez que a página abrir. */
    public function definirAbaPadrao(): void
    {
        session([self::SESSION_KEY_ABA_PADRAO => $this->abaAtiva]);
    }

    public function abaPadraoAtual(): string
    {
        return session(self::SESSION_KEY_ABA_PADRAO, 'produtos');
    }

    // ── Aba Mesas ────────────────────────────────────────────────────────────

    #[Computed]
    public function mesas()
    {
        return SessaoMesa::whereIn('sessao_mesa_status', ['ABERTA', 'FECHADA'])
            ->when($this->buscaMesa, function ($query) {
                $termo = $this->buscaMesa;
                $query->where(function ($q) use ($termo) {
                    $q->whereHas('mesa', fn ($q2) => $q2->where('mesa_nome', 'like', "%{$termo}%"))
                        ->orWhereHas('cliente', fn ($q2) => $q2->where('cliente_nome', 'like', "%{$termo}%"));
                });
            })
            ->with([
                'mesa',
                'cliente',
                'pedidos' => function ($query) {
                    $query->whereNotIn('pedido_status', ['INICIADO', 'CANCELADO', 'FINALIZADO'])
                        ->with([
                            'cliente',
                            'item_pedido_pedido_id.produto.categoria',
                            'item_pedido_pedido_id.adicionaisItemPedido.adicional',
                            'item_pedido_pedido_id' => function ($query) {
                                $query->where('item_pedido_status', 'INSERIDO');
                            },
                        ]);
                },
            ])
            ->get();
    }

    public function lancarItensDaMesa(int $sessaoMesaId): void
    {
        $this->iniciarVendaSeNecessario();

        // Coluna crua (não a relação cliente(), que tem withDefault e nunca
        // retorna null de verdade) — só preenche se a venda ainda não tiver
        // cliente (ver preencherClienteSeVazio()).
        $this->preencherClienteSeVazio(
            SessaoMesa::where('id', $sessaoMesaId)->value('sessao_mesa_cliente_id')
        );

        $pedidos = Pedido::where('pedido_sessao_mesa_id', $sessaoMesaId)
            ->whereNotIn('pedido_status', ['CANCELADO', 'FINALIZADO'])
            ->get();

        foreach ($pedidos as $pedido) {
            // whereNull('item_pedido_venda_id') é o que garante idempotência:
            // reclicar "lançar" num pedido já lançado não soma de novo (bug
            // corrigido — antes o filtro só checava um campo morto em Pedido,
            // nunca escrito por este fluxo, então repetia a soma sempre).
            $itensPedido = ItensPedido::where('item_pedido_pedido_id', $pedido->id)
                ->where('item_pedido_status', 'INSERIDO')
                ->whereNull('item_pedido_venda_id')
                ->with('adicionaisItemPedido', 'produto')
                ->get();

            if ($itensPedido->isEmpty()) {
                continue;
            }

            $this->adicionarItensPedidoNaVenda($itensPedido);

            ItensPedido::whereIn('id', $itensPedido->pluck('id'))
                ->update(['item_pedido_venda_id' => $this->vendaId]);
        }

        app(VendaService::class)->atualizarValoresdaVenda($this->vendaId);
        $this->finalizarSessaoSeCompleta($sessaoMesaId);
        $this->limparCachesDoCarrinho();
    }

    /**
     * @param  int|'sem_cliente'  $clienteId
     */
    public function lancarItensDoClienteDaMesa(int $sessaoMesaId, $clienteId): void
    {
        $this->iniciarVendaSeNecessario();

        $this->preencherClienteSeVazio($clienteId === 'sem_cliente' ? null : $clienteId);

        $pedidos = Pedido::where('pedido_sessao_mesa_id', $sessaoMesaId)
            ->whereNotIn('pedido_status', ['CANCELADO', 'FINALIZADO'])
            ->get();

        foreach ($pedidos as $pedido) {
            $query = ItensPedido::where('item_pedido_pedido_id', $pedido->id)
                ->where('item_pedido_status', 'INSERIDO')
                ->whereNull('item_pedido_venda_id')
                ->with('adicionaisItemPedido', 'produto');

            if ($clienteId === 'sem_cliente') {
                $query->whereNull('item_pedido_cliente_id');
            } else {
                $query->where('item_pedido_cliente_id', $clienteId);
            }

            $itensPedido = $query->get();

            if ($itensPedido->isEmpty()) {
                continue;
            }

            $this->adicionarItensPedidoNaVenda($itensPedido);

            // Correção de um bug do legado (adicionarItensSessaoMesaPorCliente
            // nunca marcava item_pedido_venda_id): sem isso, os itens ficam
            // sujeitos a lançamento duplicado e a sessão nunca finaliza sozinha.
            ItensPedido::whereIn('id', $itensPedido->pluck('id'))
                ->update(['item_pedido_venda_id' => $this->vendaId]);
        }

        app(VendaService::class)->atualizarValoresdaVenda($this->vendaId);
        $this->finalizarSessaoSeCompleta($sessaoMesaId);
        $this->limparCachesDoCarrinho();
    }

    public function removerItensDaMesa(int $sessaoMesaId): void
    {
        if ($this->vendaId === null) {
            return;
        }

        $pedidos = Pedido::where('pedido_sessao_mesa_id', $sessaoMesaId)->get();

        foreach ($pedidos as $pedido) {
            // where('item_pedido_venda_id', $this->vendaId) é o que evita a
            // remoção cruzada (bug corrigido — antes filtrava só por status,
            // então "Remover" de um pedido nunca lançado ainda assim abatia a
            // quantidade da linha mesclada de OUTRO pedido com o mesmo produto).
            $itensPedido = ItensPedido::where('item_pedido_pedido_id', $pedido->id)
                ->where('item_pedido_status', 'INSERIDO')
                ->where('item_pedido_venda_id', $this->vendaId)
                ->with('produto')
                ->get();

            foreach ($itensPedido as $item) {
                $this->removerItemPedidoDaVenda($item);
            }

            ItensPedido::whereIn('id', $itensPedido->pluck('id'))
                ->update(['item_pedido_venda_id' => null]);
        }

        app(VendaService::class)->atualizarValoresdaVenda($this->vendaId);
        $this->limparCachesDoCarrinho();
    }

    /**
     * Indica se todos os itens ativos (INSERIDO) de um pedido/mesa já estão
     * lançados na venda atual — usado para refletir o estado real do
     * checkbox "Lançado" na UI, sempre a partir do banco (nunca otimista).
     */
    public function itensEstaoLancadosNestaVenda($itensPedido): bool
    {
        // Sem venda ainda (vendaId null): "null === null" bateria pra
        // QUALQUER pedido nunca lançado (item_pedido_venda_id também null),
        // marcando o checkbox indevidamente — não existe venda pra estar
        // "lançado nela" antes dela existir.
        if ($this->vendaId === null || $itensPedido->isEmpty()) {
            return false;
        }

        return $itensPedido->every(fn ($item) => $item->item_pedido_venda_id === $this->vendaId);
    }

    // ── Aba Pedidos avulsos ─────────────────────────────────────────────────

    #[Computed]
    public function pedidosAvulsos()
    {
        return Pedido::whereNotIn('pedido_status', ['INICIADO', 'CANCELADO', 'FINALIZADO'])
            ->when($this->buscaPedido, function ($query) {
                $termo = $this->buscaPedido;
                $query->where(function ($q) use ($termo) {
                    $q->where('id', 'like', "%{$termo}%")
                        ->orWhereHas('cliente', fn ($q2) => $q2->where('cliente_nome', 'like', "%{$termo}%"));
                });
            })
            ->with([
                'cliente',
                'item_pedido_pedido_id.produto.categoria',
                'item_pedido_pedido_id.adicionaisItemPedido.adicional',
                'item_pedido_pedido_id' => function ($query) {
                    $query->where('item_pedido_status', 'INSERIDO');
                },
            ])
            // Só some da lista quando algum item já foi vendido em OUTRA
            // venda — enquanto estiver ligado à venda atual, continua
            // aparecendo (marcado) pra o operador poder desmarcar.
            ->whereDoesntHave('item_pedido_pedido_id', function ($query) {
                $query->where('item_pedido_status', 'INSERIDO')
                    ->where('item_pedido_venda_id', '!=', $this->vendaId);
            })
            ->orderByDesc('id')
            ->get();
    }

    public function lancarPedidoAvulso(int $pedidoId): void
    {
        $this->iniciarVendaSeNecessario();

        $this->preencherClienteSeVazio(
            Pedido::where('id', $pedidoId)->value('pedido_cliente_id')
        );

        $itensPedido = ItensPedido::where('item_pedido_pedido_id', $pedidoId)
            ->where('item_pedido_status', 'INSERIDO')
            ->whereNull('item_pedido_venda_id')
            ->with('adicionaisItemPedido', 'produto')
            ->get();

        if ($itensPedido->isEmpty()) {
            return;
        }

        $this->adicionarItensPedidoNaVenda($itensPedido);

        ItensPedido::whereIn('id', $itensPedido->pluck('id'))
            ->update(['item_pedido_venda_id' => $this->vendaId]);

        app(VendaService::class)->atualizarValoresdaVenda($this->vendaId);
        $this->limparCachesDoCarrinho();
    }

    public function removerPedidoAvulso(int $pedidoId): void
    {
        if ($this->vendaId === null) {
            return;
        }

        $itensPedido = ItensPedido::where('item_pedido_pedido_id', $pedidoId)
            ->where('item_pedido_status', 'INSERIDO')
            ->where('item_pedido_venda_id', $this->vendaId)
            ->with('produto')
            ->get();

        foreach ($itensPedido as $item) {
            $this->removerItemPedidoDaVenda($item);
        }

        ItensPedido::whereIn('id', $itensPedido->pluck('id'))
            ->update(['item_pedido_venda_id' => null]);

        app(VendaService::class)->atualizarValoresdaVenda($this->vendaId);
        $this->limparCachesDoCarrinho();
    }

    // ── Helpers compartilhados (Mesas + Pedidos avulsos) ────────────────────

    /**
     * Lança os itens de um Pedido (mesa ou avulso) na venda atual, mesclando
     * na linha existente quando possível. Espelha
     * ItensVendaController::adicionarItensPedidoNaVenda (legado).
     */
    private function adicionarItensPedidoNaVenda($itensPedido): void
    {
        foreach ($itensPedido as $item) {
            $itemAtualTemAdicionais = ($item->adicionaisItemPedido !== null && ! $item->adicionaisItemPedido->isEmpty())
                || ($item->item_pedido_valor_adicionais > 0);

            $itemVenda = ItensVenda::where('item_venda_produto_id', $item->item_pedido_produto_id)
                ->where('item_venda_venda_id', $this->vendaId)
                ->where('item_venda_valor_adicionais', 0)
                ->first();

            if ($itemVenda && ! $itemAtualTemAdicionais) {
                $itemVenda->item_venda_quantidade += $item->item_pedido_quantidade;
                $itemVenda->item_venda_desconto += $item->item_pedido_desconto;
                $itemVenda->item_venda_valor += $item->item_pedido_valor;
                $itemVenda->item_venda_quantidade_tributavel += $item->item_pedido_quantidade;
                $this->somarTributosItemVenda($itemVenda, $item->produto, $item->item_pedido_valor);
                $itemVenda->save();
            } else {
                $nextItemNumber = (ItensVenda::where('item_venda_venda_id', $this->vendaId)->max('item_numero') ?? 0) + 1;

                $valorEfetivo = $item->item_pedido_valor;
                $itemVenda = ItensVenda::create([
                    'item_numero' => $nextItemNumber,
                    'item_venda_venda_id' => $this->vendaId,
                    'item_venda_produto_id' => $item->item_pedido_produto_id,
                    'item_venda_quantidade' => $item->item_pedido_quantidade,
                    'item_venda_valor_unitario' => $item->item_pedido_valor_unitario,
                    'item_venda_valor_adicionais' => $item->item_pedido_valor_adicionais,
                    'item_venda_desconto' => $item->item_pedido_desconto,
                    'item_venda_valor' => $valorEfetivo,
                    'item_venda_status' => 'INSERIDO',
                    'item_venda_quantidade_tributavel' => $item->item_pedido_quantidade,
                    'item_venda_valor_unitario_tributavel' => $item->item_pedido_valor_unitario,
                    'item_venda_valor_base_calculo' => $valorEfetivo,
                    'item_venda_valor_icms' => ($valorEfetivo * $item->produto->produto_valor_percentual_icms) / 100,
                    'item_venda_valor_pis' => ($valorEfetivo * $item->produto->produto_valor_percentual_pis) / 100,
                    'item_venda_valor_cofins' => ($valorEfetivo * $item->produto->produto_valor_percentual_cofins) / 100,
                    'item_venda_valor_total_tributos' => ($valorEfetivo * ($item->produto->produto_valor_percentual_icms + $item->produto->produto_valor_percentual_pis + $item->produto->produto_valor_percentual_cofins)) / 100,
                ]);
            }

            $this->adicionarOuAtualizarAdicionaisDoItem($item, $itemVenda);
        }
    }

    private function somarTributosItemVenda(ItensVenda $itemVenda, $produto, float $valorTotal): void
    {
        $itemVenda->item_venda_valor_base_calculo += $valorTotal;
        $itemVenda->item_venda_valor_icms += ($valorTotal * $produto->produto_valor_percentual_icms) / 100;
        $itemVenda->item_venda_valor_pis += ($valorTotal * $produto->produto_valor_percentual_pis) / 100;
        $itemVenda->item_venda_valor_cofins += ($valorTotal * $produto->produto_valor_percentual_cofins) / 100;
        $itemVenda->item_venda_valor_total_tributos += ($valorTotal * ($produto->produto_valor_percentual_icms + $produto->produto_valor_percentual_pis + $produto->produto_valor_percentual_cofins)) / 100;
    }

    private function adicionarOuAtualizarAdicionaisDoItem(ItensPedido $item, ItensVenda $itemVenda): void
    {
        $adicionaisPedido = AdicionaisItemPedido::where('aip_item_pedido_id', $item->id)->get();

        foreach ($adicionaisPedido as $adicionalPedido) {
            $adicionalVenda = AdicionaisItemVenda::where('aiv_item_venda_id', $itemVenda->id)
                ->where('aiv_adicional_id', $adicionalPedido->aip_adicional_id)
                ->first();

            if ($adicionalVenda) {
                $adicionalVenda->aiv_quantidade += $adicionalPedido->aip_quantidade;
                $adicionalVenda->aiv_valor_total += $adicionalPedido->aip_valor_total;
                $adicionalVenda->save();
            } else {
                AdicionaisItemVenda::create([
                    'aiv_adicional_id' => $adicionalPedido->aip_adicional_id,
                    'aiv_item_venda_id' => $itemVenda->id,
                    'aiv_valor_unitario' => $adicionalPedido->aip_valor_unitario,
                    'aiv_quantidade' => $adicionalPedido->aip_quantidade,
                    'aiv_valor_total' => $adicionalPedido->aip_valor_total,
                ]);
            }
        }
    }

    /**
     * Reverte um ItensPedido lançado na venda: abate a linha correspondente
     * em ItensVenda (e seus adicionais), removendo-a se a quantidade zerar.
     * Espelha o trecho comum de removerItensSessaoMesa/removerItensPedido (legado).
     */
    private function removerItemPedidoDaVenda(ItensPedido $item): void
    {
        $itemVenda = ItensVenda::where('item_venda_produto_id', $item->item_pedido_produto_id)
            ->where('item_venda_venda_id', $this->vendaId)
            ->first();

        if (! $itemVenda) {
            return;
        }

        $itemVenda->item_venda_quantidade -= $item->item_pedido_quantidade;
        $itemVenda->item_venda_desconto -= $item->item_pedido_desconto;
        $itemVenda->item_venda_valor -= $item->item_pedido_valor;
        $itemVenda->item_venda_quantidade_tributavel -= $item->item_pedido_quantidade;
        $itemVenda->item_venda_valor_base_calculo -= $item->item_pedido_valor;
        $itemVenda->item_venda_valor_icms -= ($item->item_pedido_valor * $item->produto->produto_valor_percentual_icms) / 100;
        $itemVenda->item_venda_valor_pis -= ($item->item_pedido_valor * $item->produto->produto_valor_percentual_pis) / 100;
        $itemVenda->item_venda_valor_cofins -= ($item->item_pedido_valor * $item->produto->produto_valor_percentual_cofins) / 100;

        if ($itemVenda->item_venda_quantidade <= 0) {
            AdicionaisItemVenda::where('aiv_item_venda_id', $itemVenda->id)->delete();
            $itemVenda->delete();

            return;
        }

        $adicionaisPedido = AdicionaisItemPedido::where('aip_item_pedido_id', $item->id)->get();
        foreach ($adicionaisPedido as $adicionalPedido) {
            $adicionalVenda = AdicionaisItemVenda::where('aiv_item_venda_id', $itemVenda->id)
                ->where('aiv_adicional_id', $adicionalPedido->aip_adicional_id)
                ->first();

            if (! $adicionalVenda) {
                continue;
            }

            $adicionalVenda->aiv_quantidade -= $adicionalPedido->aip_quantidade;
            $adicionalVenda->aiv_valor_total -= $adicionalPedido->aip_valor_total;

            if ($adicionalVenda->aiv_quantidade <= 0) {
                $adicionalVenda->delete();
            } else {
                $adicionalVenda->save();
            }
        }

        $itemVenda->save();
    }

    /**
     * Finaliza a sessão de mesa quando todos os itens dos seus pedidos foram
     * recebidos (lançados) na venda, liberando a mesa se ela ainda estiver
     * ocupada por esta sessão. Espelha
     * ItensVendaController::finalizarSessaoSeCompleta (legado).
     */
    private function finalizarSessaoSeCompleta(int $sessaoMesaId): bool
    {
        $sessao = SessaoMesa::find($sessaoMesaId);
        if (! $sessao || ! in_array($sessao->sessao_mesa_status, ['ABERTA', 'FECHADA'])) {
            return false;
        }

        $temPedidosAtivos = Pedido::where('pedido_sessao_mesa_id', $sessaoMesaId)
            ->whereNotIn('pedido_status', ['CANCELADO', 'FINALIZADO'])
            ->exists();

        if (! $temPedidosAtivos) {
            return false;
        }

        $pendentes = ItensPedido::whereHas('pedido', function ($q) use ($sessaoMesaId) {
            $q->where('pedido_sessao_mesa_id', $sessaoMesaId)
                ->whereNotIn('pedido_status', ['CANCELADO', 'FINALIZADO']);
        })
            ->where('item_pedido_status', 'INSERIDO')
            ->whereNull('item_pedido_venda_id')
            ->count();

        if ($pendentes > 0) {
            return false;
        }

        $sessao->update(['sessao_mesa_status' => 'FINALIZADA']);

        $mesa = Mesa::find($sessao->sessao_mesa_mesa_id);
        if ($mesa
            && $mesa->mesa_status === 'OCUPADA'
            && (int) $mesa->mesa_sessao_atual_id === (int) $sessao->id) {
            $mesa->update([
                'mesa_status' => 'LIBERADA',
                'mesa_sessao_atual_id' => null,
            ]);
        }

        return true;
    }

    private function limparCachesDoCarrinho(): void
    {
        unset($this->itensCarrinho, $this->venda, $this->mesas, $this->pedidosAvulsos);
    }

    // ── Modal Cliente ────────────────────────────────────────────────────────

    public function abrirModalCliente(): void
    {
        $this->modalClienteAberta = true;
    }

    public function fecharModalCliente(): void
    {
        $this->modalClienteAberta = false;
        $this->buscaCliente = '';
        $this->clienteAdHocNome = null;
        $this->clienteAdHocCpf = null;
        $this->clienteAdHocCnpj = null;
        $this->clienteAdHocTelefone = null;
        $this->clienteAdHocEmail = null;
        $this->resetErrorBag();
    }

    #[Computed]
    public function clientesEncontrados()
    {
        if (trim($this->buscaCliente) === '') {
            return collect();
        }

        // Telefone é comparado só pelos dígitos (a maioria dos cadastros vem
        // do celular do cliente, geralmente digitado com formatação diferente
        // do que está salvo).
        $somenteDigitos = preg_replace('/\D/', '', $this->buscaCliente);

        return Cliente::where('cliente_nome', 'like', "%{$this->buscaCliente}%")
            ->orWhere('cliente_cpf', 'like', "%{$this->buscaCliente}%")
            ->orWhere('cliente_cnpj', 'like', "%{$this->buscaCliente}%")
            ->when($somenteDigitos !== '', fn ($q) => $q->orWhere('cliente_celular', 'like', "%{$somenteDigitos}%"))
            ->orderBy('cliente_nome')
            ->limit(20)
            ->get();
    }

    public function selecionarCliente(int $clienteId): void
    {
        $this->iniciarVendaSeNecessario();

        Venda::find($this->vendaId)?->update(['venda_cliente_id' => $clienteId]);
        $this->fecharModalCliente();
    }

    public function removerClienteDaVenda(): void
    {
        $venda = Venda::find($this->vendaId);
        if (! $venda) {
            return;
        }

        $venda->update(['venda_cliente_id' => null]);
        unset($this->venda);
    }

    public function salvarClienteAdHoc(): void
    {
        try {
            $cliente = app(FinalizacaoVendaService::class)->resolverClienteAdHoc([
                'nome' => $this->clienteAdHocNome,
                'cpf' => $this->clienteAdHocCpf,
                'cnpj' => $this->clienteAdHocCnpj,
                'telefone' => $this->clienteAdHocTelefone,
                'email' => $this->clienteAdHocEmail,
            ]);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->getMessageBag());

            return;
        }

        $this->iniciarVendaSeNecessario();

        Venda::find($this->vendaId)?->update(['venda_cliente_id' => $cliente->id]);
        $this->fecharModalCliente();
    }

    // ── Modal Pagamento ──────────────────────────────────────────────────────

    #[Computed]
    public function opcoesPagamento()
    {
        return OpcoesPagamento::all();
    }

    #[Computed]
    public function cartoes()
    {
        return CartoesPagamento::all();
    }

    /** Maquininhas Stone com número de série — opções do modal de cobrança integrada. */
    #[Computed]
    public function maquininhasStone()
    {
        return Maquininha::where('operadora', OperadoraMaquininha::Stone->value)
            ->whereNotNull('numero_serie')
            ->orderBy('nome')
            ->pluck('nome', 'id');
    }

    /** Existe ao menos uma forma de pagamento integrada à Stone cadastrada. */
    #[Computed]
    public function temFormaStone(): bool
    {
        return OpcoesPagamento::where('opcaopag_stone_integrada', true)->exists();
    }

    #[Computed]
    public function pagamentosLancados()
    {
        // Sem venda ainda (criação lazy): where('...', null) do Eloquent vira
        // whereNull, o que traria pagamentos órfãos (pg_venda_venda_id nulo,
        // existentes na base) em vez de uma lista vazia.
        if ($this->vendaId === null) {
            return collect();
        }

        return PagamentosVenda::with(['opcaoPagamento', 'cartao'])
            ->where('pg_venda_venda_id', $this->vendaId)
            ->get();
    }

    /** Quanto ainda falta para cobrir o total da venda (nunca negativo). */
    #[Computed]
    public function valorRestante(): float
    {
        if (! $this->venda) {
            return 0.0;
        }

        return max(0, round((float) $this->venda->venda_valor_total - (float) $this->venda->venda_valor_pago, 2));
    }

    /** Pedidos cujos itens já estão lançados nesta venda (via item_pedido_venda_id). */
    #[Computed]
    public function pedidosLancadosNestaVenda()
    {
        if ($this->vendaId === null) {
            return collect();
        }

        return Pedido::whereHas('item_pedido_pedido_id', function ($query) {
            $query->where('item_pedido_venda_id', $this->vendaId);
        })->get();
    }

    /**
     * Sugestão (não vinculante) de forma de pagamento a partir do texto livre
     * do(s) pedido(s) lançados — o Pedido não tem valor por forma de
     * pagamento (só pedido_descricao_pagamento/pedido_observacao_pagamento em
     * texto), então isso nunca cria um PagamentosVenda sozinho, só pré-marca
     * a opção no modal quando o texto bate com exatamente uma OpcoesPagamento
     * cadastrada, e mostra a observação como lembrete pro operador.
     */
    #[Computed]
    public function sugestaoPagamentoPedido(): ?array
    {
        $descricoes = $this->pedidosLancadosNestaVenda
            ->pluck('pedido_descricao_pagamento')
            ->filter()
            ->map(fn ($d) => trim($d))
            ->filter()
            ->unique();

        $observacoes = $this->pedidosLancadosNestaVenda
            ->pluck('pedido_observacao_pagamento')
            ->filter()
            ->map(fn ($o) => trim($o))
            ->filter()
            ->unique()
            ->values();

        $opcaoSugerida = null;
        if ($descricoes->count() === 1) {
            $opcaoSugerida = OpcoesPagamento::whereRaw('LOWER(opcaopag_nome) = ?', [mb_strtolower($descricoes->first())])->first();
        }

        if ($opcaoSugerida === null && $observacoes->isEmpty()) {
            return null;
        }

        return [
            'opcao_id' => $opcaoSugerida?->id,
            'descricao' => $descricoes->count() === 1 ? $descricoes->first() : null,
            'observacoes' => $observacoes,
        ];
    }

    public function abrirModalPagamento(): void
    {
        // Pré-preenche com o restante a pagar — mesmo comportamento do
        // modal de pagamento do blade legado (preencherRecebidoComRestante()).
        $this->valorPagamento = $this->valorRestante;
        $this->modalPagamentoAberta = true;
        $this->resetErrorBag('valorPagamento');

        $sugestao = $this->sugestaoPagamentoPedido;
        if ($sugestao && $sugestao['opcao_id'] && $this->opcaoPagamentoSelecionadaId === null) {
            $this->opcaoPagamentoSelecionadaId = $sugestao['opcao_id'];
        }
    }

    public function fecharModalPagamento(): void
    {
        $this->modalPagamentoAberta = false;
        $this->opcaoPagamentoSelecionadaId = null;
        $this->cartaoId = null;
        $this->numeroAutorizacaoCartao = null;
        $this->valorPagamento = 0;
        $this->valorPagoPeloCliente = 0;
        $this->pagamentoEmEdicaoId = null;
        $this->stoneMaquininhaId = null;
    }

    // ── Cobrança na maquininha Stone ─────────────────────────────────────────

    /**
     * Manda a cobrança da venda para uma maquininha Stone (modelo Listado) e
     * abre o modal de acompanhamento. O PagamentosVenda só é criado quando o
     * webhook charge.paid chega (ver StoneRecebimentoService).
     */
    public function iniciarCobrancaStone(OpcoesPagamento $opcao, Venda $venda): void
    {
        $this->resetErrorBag('valorPagamento');

        $valor = round((float) $this->valorPagamento, 2);

        if ($valor <= 0 || $valor > $this->valorRestante + 0.005) {
            $this->addError('valorPagamento', 'Informe um valor entre R$ 0,01 e o restante da venda.');

            return;
        }

        $maquininha = $this->stoneMaquininhaId ? Maquininha::find($this->stoneMaquininhaId) : null;
        if (! $maquininha) {
            $this->addError('valorPagamento', 'Selecione a maquininha que vai receber a cobrança.');

            return;
        }

        try {
            $pedido = app(StoneRecebimentoService::class)
                ->iniciarCobranca($venda, $opcao, $maquininha, $valor, StonePedidoModo::Direto);
        } catch (StoneConnectException $e) {
            $this->stonePedidoId = null;
            $this->stoneStatusModal = 'erro';
            $this->stoneErroModal = $e->getMessage();
            $this->modalStoneAberta = true;
            $this->fecharModalPagamento();

            return;
        }

        $this->stonePedidoId = $pedido->id;
        $this->stoneStatusModal = 'aguardando';
        $this->stoneErroModal = null;
        $this->modalStoneAberta = true;
        $this->fecharModalPagamento();
    }

    /**
     * Lança UM pedido Listado com o valor restante da venda — o pedido entra na
     * lista do POS para o operador/entregador selecionar e o cliente pagar
     * (crédito, débito ou PIX escolhidos na maquininha). A forma de pagamento do
     * lançamento é resolvida quando o webhook charge.paid chega.
     */
    public function abrirModalStoneTotal(): void
    {
        $this->stoneTotalMaquininhaId = null;
        $this->resetErrorBag('stoneTotal');
        $this->modalStoneTotalAberta = true;
    }

    public function fecharModalStoneTotal(): void
    {
        $this->modalStoneTotalAberta = false;
        $this->stoneTotalMaquininhaId = null;
        $this->resetErrorBag('stoneTotal');
    }

    public function lancarPedidoTotalStone(): void
    {
        $this->resetErrorBag('stoneTotal');

        $venda = Venda::find($this->vendaId);
        if (! $venda) {
            return;
        }

        $valor = $this->valorRestante;
        if ($valor <= 0) {
            $this->addError('stoneTotal', 'Não há saldo restante para cobrar na maquininha.');

            return;
        }

        $maquininha = $this->stoneTotalMaquininhaId ? Maquininha::find($this->stoneTotalMaquininhaId) : null;
        if (! $maquininha) {
            $this->addError('stoneTotal', 'Selecione a maquininha que vai receber o pedido.');

            return;
        }

        try {
            $pedido = app(StoneRecebimentoService::class)
                ->iniciarCobranca($venda, null, $maquininha, $valor, StonePedidoModo::Listado);
        } catch (StoneConnectException $e) {
            $this->fecharModalStoneTotal();
            $this->stonePedidoId = null;
            $this->stoneStatusModal = 'erro';
            $this->stoneErroModal = $e->getMessage();
            $this->modalStoneAberta = true;

            return;
        }

        $this->fecharModalStoneTotal();
        $this->stonePedidoId = $pedido->id;
        $this->stoneStatusModal = 'aguardando';
        $this->stoneErroModal = null;
        $this->modalStoneAberta = true;
    }

    /** Chamado via wire:poll enquanto o modal está 'aguardando'. */
    public function verificarStatusStone(): void
    {
        $pedido = $this->stonePedidoId ? StonePedido::find($this->stonePedidoId) : null;
        if (! $pedido) {
            return;
        }

        if ($pedido->stp_status === StonePedidoStatus::Pago) {
            $this->stoneStatusModal = 'pago';
            $this->recarregarPagamentos();

            return;
        }

        if ($pedido->stp_status === StonePedidoStatus::Falha) {
            $this->definirErroStone('Não foi possível enviar a cobrança para a maquininha.');

            return;
        }

        if (in_array($pedido->stp_status, [StonePedidoStatus::Cancelado, StonePedidoStatus::Estornado], true)) {
            $this->stoneStatusModal = 'cancelado';

            return;
        }

        if ($pedido->stp_status === StonePedidoStatus::PagoParcial) {
            // Listado permite mais de um cartão — segue aguardando o restante.
            $this->recarregarPagamentos();
        }
    }

    public function cancelarCobrancaStone(): void
    {
        $pedido = $this->stonePedidoId ? StonePedido::find($this->stonePedidoId) : null;
        if (! $pedido) {
            return;
        }

        try {
            app(StoneRecebimentoService::class)->cancelarCobranca($pedido);
            $this->stoneStatusModal = 'cancelado';
        } catch (StoneConnectException $e) {
            $this->definirErroStone($e->getMessage());
        }
    }

    public function fecharModalStone(): void
    {
        $pago = $this->stoneStatusModal === 'pago';

        $this->modalStoneAberta = false;
        $this->stoneStatusModal = null;
        $this->stoneErroModal = null;
        $this->stonePedidoId = null;

        $this->recarregarPagamentos();

        if ($pago) {
            $this->redirect(static::getUrl());
        }
    }

    private function definirErroStone(string $mensagem): void
    {
        $this->stoneStatusModal = 'erro';
        $this->stoneErroModal = $mensagem;
    }

    private function recarregarPagamentos(): void
    {
        unset($this->pagamentosLancados, $this->venda);
    }

    /**
     * Abre o modal de pagamento pré-preenchido com os dados de um pagamento
     * já lançado, pra correção (ex.: caixa errou a forma de pagamento ou o
     * valor). `registrarPagamento()` detecta `pagamentoEmEdicaoId` e troca o
     * registro antigo pelo novo em vez de simplesmente somar mais um.
     */
    public function editarPagamento(int $pagamentoId): void
    {
        $pagamento = PagamentosVenda::find($pagamentoId);
        if (! $pagamento) {
            return;
        }

        $this->pagamentoEmEdicaoId = $pagamento->id;
        $this->opcaoPagamentoSelecionadaId = $pagamento->pg_venda_opcaopagamento_id;
        $this->cartaoId = $pagamento->pg_venda_cartao_id;
        $this->numeroAutorizacaoCartao = $pagamento->pg_venda_numero_autorizacao_cartao;
        $this->valorPagamento = (float) $pagamento->pg_venda_valor_pagamento;
        $this->valorPagoPeloCliente = (float) $pagamento->pg_venda_valor_pago_pelo_cliente;
        $this->modalPagamentoAberta = true;
        $this->resetErrorBag('valorPagamento');
    }

    /**
     * Registra um pagamento (parcial ou total) da venda. Espelha
     * PagamentosVendaController::store (legado) — mantém a mesma mutação
     * manual dos totais da venda (não delega a VendaService::atualizarValoresdaVenda,
     * que não soma acréscimo/desconto de pagamento ao total; unificar os dois
     * cálculos afetaria o valor total cobrado/faturado e fica fora do escopo
     * desta migração de UI). Em modo de edição, primeiro estorna o pagamento
     * antigo (mesmo caminho de removerPagamento) antes de aplicar o novo —
     * evita duplicar a matemática de acréscimo/desconto/troco.
     */
    public function registrarPagamento(): void
    {
        $this->resetErrorBag('valorPagamento');

        $opcaoPagamento = OpcoesPagamento::find($this->opcaoPagamentoSelecionadaId);
        if (! $opcaoPagamento) {
            return;
        }

        $venda = Venda::find($this->vendaId);
        if (! $venda) {
            return;
        }

        // Forma integrada à maquininha Stone: em vez de gravar o pagamento
        // aqui, manda a cobrança para o POS e espera o webhook charge.paid.
        if ($opcaoPagamento->ehIntegracaoStone()) {
            $this->iniciarCobrancaStone($opcaoPagamento, $venda);

            return;
        }

        $valorRecebido = (float) $this->valorPagamento;
        $valorPagoPeloCliente = $this->valorPagoPeloCliente > 0 ? (float) $this->valorPagoPeloCliente : $valorRecebido;

        if ($valorRecebido > (float) $venda->venda_valor_total) {
            $this->addError('valorPagamento', 'O valor recebido não pode ser maior que o valor total da venda.');

            return;
        }

        if ($valorRecebido > $valorPagoPeloCliente) {
            $this->addError('valorPagamento', 'O valor recebido não pode ser maior que o valor pago pelo cliente.');

            return;
        }

        if ($this->pagamentoEmEdicaoId) {
            $pagamentoAntigo = PagamentosVenda::find($this->pagamentoEmEdicaoId);
            $pagamentoAntigo?->delete();
            app(VendaService::class)->atualizarValoresdaVenda($this->vendaId);
            $venda->refresh();
        }

        $valorAcrescimo = 0.0;
        $valorDesconto = 0.0;
        $valorTroco = max(0, round($valorPagoPeloCliente - $valorRecebido, 2));

        $tipoTaxa = $opcaoPagamento->opcaopag_tipo_taxa;
        if ($tipoTaxa === 'ACRESCENTAR') {
            $valorAcrescimo = round($valorRecebido * (float) $opcaoPagamento->opcaopag_valor_percentual_taxa / 100, 2);
        } elseif ($tipoTaxa === 'DESCONTAR') {
            $valorDesconto = round($valorRecebido * (float) $opcaoPagamento->opcaopag_valor_percentual_taxa / 100, 2);
        }

        PagamentosVenda::create([
            'pg_venda_venda_id' => $venda->id,
            'pg_venda_opcaopagamento_id' => $opcaoPagamento->id,
            'pg_venda_cartao_id' => $this->cartaoId ?: null,
            'pg_venda_numero_autorizacao_cartao' => $this->numeroAutorizacaoCartao ?: null,
            'pg_venda_valor_pagamento' => $valorRecebido,
            'pg_venda_valor_recebido' => $valorRecebido,
            'pg_venda_valor_pago_pelo_cliente' => $valorPagoPeloCliente,
            'pg_venda_valor_troco' => $valorTroco,
            'pg_venda_valor_acrescimo' => $valorAcrescimo,
            'pg_venda_valor_desconto' => $valorDesconto,
        ]);

        $venda->venda_valor_acrescimo += $valorAcrescimo;
        $venda->venda_valor_desconto += $valorDesconto;
        $venda->venda_valor_pago += $valorRecebido + $valorAcrescimo - $valorDesconto;
        $venda->venda_valor_total += $valorAcrescimo - $valorDesconto;
        $venda->venda_valor_troco += $valorTroco;
        $venda->save();

        $this->fecharModalPagamento();
        unset($this->pagamentosLancados, $this->venda);
    }

    public function removerPagamento(int $pagamentoId): void
    {
        $pagamento = PagamentosVenda::find($pagamentoId);
        if (! $pagamento) {
            return;
        }

        $vendaId = $pagamento->pg_venda_venda_id;
        $pagamento->delete();

        app(VendaService::class)->atualizarValoresdaVenda($vendaId);
        unset($this->pagamentosLancados, $this->venda);
    }

    // ── Dividir conta ────────────────────────────────────────────────────────

    public function abrirModalDividirConta(): void
    {
        $this->dividirContaQtdPessoas = 2;
        $this->dividirContaOpcaoPagamentoId = null;
        $this->modalDividirContaAberta = true;
    }

    public function fecharModalDividirConta(): void
    {
        $this->modalDividirContaAberta = false;
    }

    /**
     * Fatia o valor restante da venda em N pagamentos iguais, sem perder
     * centavo (RateioCentavos::fatiaCentavos), usando uma forma de pagamento
     * padrão pra todas as parcelas — sem acréscimo/desconto automático. O
     * caixa ajusta cada parcela individualmente (forma real, autorização de
     * cartão, troco em dinheiro) depois via editarPagamento().
     */
    public function dividirConta(int $qtdPessoas, int $opcaoPagamentoId): void
    {
        if ($qtdPessoas < 1) {
            return;
        }

        $opcaoPagamento = OpcoesPagamento::find($opcaoPagamentoId);
        $venda = Venda::find($this->vendaId);
        $restante = $this->valorRestante;

        if (! $opcaoPagamento || ! $venda || $restante <= 0) {
            return;
        }

        DB::transaction(function () use ($qtdPessoas, $opcaoPagamento, $venda, $restante) {
            $somaPago = 0.0;

            for ($i = 0; $i < $qtdPessoas; $i++) {
                $fatia = RateioCentavos::fatiaCentavos($restante, $qtdPessoas, $i) / 100;

                PagamentosVenda::create([
                    'pg_venda_venda_id' => $venda->id,
                    'pg_venda_opcaopagamento_id' => $opcaoPagamento->id,
                    'pg_venda_valor_pagamento' => $fatia,
                    'pg_venda_valor_recebido' => $fatia,
                    'pg_venda_valor_pago_pelo_cliente' => $fatia,
                    'pg_venda_valor_troco' => 0,
                    'pg_venda_valor_acrescimo' => 0,
                    'pg_venda_valor_desconto' => 0,
                ]);

                $somaPago += $fatia;
            }

            $venda->venda_valor_pago += $somaPago;
            $venda->save();
        });

        $this->fecharModalDividirConta();
        unset($this->pagamentosLancados, $this->venda);
    }

    // ── Finalizar / cancelar venda ───────────────────────────────────────────

    #[Computed]
    public function nfeIoDisponivel(): bool
    {
        // Mesma permission já usada pelo botão "Gerar NFC-E" da tela legada
        // (resources/views/app/sessao_caixa/vendas.blade.php) — cancel:venda
        // e emitir:nfe ficam restritos a quem também fecha caixa.
        return Auth::user()?->can('emitir:nfe') && Empresa::first()?->nfeIoConfigurado();
    }

    /**
     * Finaliza a venda via FinalizacaoVendaService. Diferente do legado (que
     * recebia checkboxes id_sessao_mesa[]/id_pedido[] do form), deriva quais
     * mesas/pedidos finalizar a partir do que já está de fato vinculado à
     * venda (item_pedido_venda_id), já que o lançamento acontece no clique,
     * não no fim.
     *
     * Quando "emitir NFC-e ao finalizar" está marcado, o redirect pra
     * próxima venda só acontece depois que o modal de emissão for fechado
     * (ver fecharModalNfe()) — a finalização em si (status, estoque,
     * pagamento) sempre acontece aqui embaixo de qualquer forma, porque o
     * payload da NFC-e depende de venda_datahora_finalizada, que só existe
     * depois que a venda já foi finalizada de verdade.
     */
    public function finalizarVenda(): void
    {
        $venda = $this->executarFinalizacao();
        if (! $venda) {
            return;
        }

        if ($this->emitirNfeAoFinalizar && $this->nfeIoDisponivel) {
            $this->iniciarEmissaoNfe($venda);

            return;
        }

        // Redireciona pra própria página (não pra lista de vendas do blade
        // legado): o caixa emenda direto pra próxima venda, sem sair do
        // Filament. Nenhuma Venda nova é criada aqui — só no primeiro
        // lançamento real da próxima operação (ver iniciarVendaSeNecessario()).
        $this->redirect(static::getUrl());
    }

    private function executarFinalizacao(): ?Venda
    {
        $venda = Venda::find($this->vendaId);
        if (! $venda) {
            return null;
        }

        $temCobrancaStonePendente = StonePedido::where('stp_venda_id', $this->vendaId)
            ->whereIn('stp_status', [StonePedidoStatus::Aguardando, StonePedidoStatus::PagoParcial])
            ->exists();

        if ($temCobrancaStonePendente) {
            $this->addError('finalizar', 'Há uma cobrança na maquininha aguardando pagamento. Conclua ou cancele antes de finalizar a venda.');

            return null;
        }

        $pedidoIds = ItensPedido::where('item_pedido_venda_id', $this->vendaId)
            ->whereNotNull('item_pedido_pedido_id')
            ->distinct()
            ->pluck('item_pedido_pedido_id');

        $pedidosVinculados = Pedido::whereIn('id', $pedidoIds)->get();
        $idSessaoMesa = $pedidosVinculados->pluck('pedido_sessao_mesa_id')->filter()->unique()->values()->all();
        $idPedido = $pedidosVinculados->whereNull('pedido_sessao_mesa_id')->pluck('id')->all();

        $fiadoVencimento = $this->vendaFiado && $this->fiadoVencimento
            ? Carbon::parse($this->fiadoVencimento)
            : null;

        try {
            app(FinalizacaoVendaService::class)->finalizar(
                $venda,
                null,
                $idSessaoMesa,
                $idPedido,
                $this->vendaFiado,
                $fiadoVencimento,
            );
        } catch (VendaNaoFinalizavelException $e) {
            $this->addError('finalizar', $e->getMessage());

            return null;
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->getMessageBag());

            return null;
        }

        return $venda;
    }

    /** Crédito ainda disponível do cliente vinculado à venda atual. Null = sem limite configurado (fiado bloqueado). */
    #[Computed]
    public function limiteCreditoDisponivel(): ?float
    {
        $clienteId = $this->venda?->venda_cliente_id;
        if ($clienteId === null) {
            return null;
        }

        return Cliente::find($clienteId)?->limiteCreditoDisponivel();
    }

    public function iniciarEmissaoNfe(?Venda $venda = null): void
    {
        $venda ??= Venda::find($this->vendaId);
        if (! $venda) {
            return;
        }

        $this->modalNfeAberta = true;
        $this->nfeStatusModal = 'processando';
        $this->nfeErroModal = null;

        try {
            app(NfeIoService::class)->emitir($venda);
            $this->nfeInvoiceId = $venda->fresh()->venda_id_nfe;
        } catch (NfeIoException $e) {
            $this->nfeStatusModal = 'erro';
            $this->nfeErroModal = $e->getMessage();
        }
    }

    /** Chamado via wire:poll enquanto o modal estiver 'processando' — a emissão é assíncrona na NFe.io. */
    public function verificarStatusNfe(): void
    {
        $venda = Venda::find($this->vendaId);
        if (! $venda || blank($venda->venda_id_nfe)) {
            return;
        }

        try {
            $status = app(NfeIoService::class)->sincronizarStatusLocal($venda);
        } catch (NfeIoException $e) {
            $this->nfeStatusModal = 'erro';
            $this->nfeErroModal = $e->getMessage();

            return;
        }

        if ($status === 'Issued') {
            $this->nfeStatusModal = 'emitido';
        } elseif (in_array($status, ['Error', 'Failed', 'Cancelled'], true)) {
            $this->nfeStatusModal = 'erro';
            $this->nfeErroModal = "A NFe.io retornou o status '{$status}' para esta nota.";
        }
        // qualquer outro status (ex. 'Processing'): mantém 'processando', o poll continua.
    }

    /** Único ponto que redireciona pra próxima venda quando o checkbox de NFC-e está marcado. */
    public function fecharModalNfe(): void
    {
        $this->modalNfeAberta = false;
        $this->nfeStatusModal = null;
        $this->nfeErroModal = null;
        $this->nfeInvoiceId = null;

        $this->redirect(static::getUrl());
    }

    // ── Aba Pendentes (vendas fiado/parcial em aberto) ──────────────────────

    /** Títulos a receber (Pendente/Parcial) originados de vendas do PDV — atalho de cobrança no balcão. */
    #[Computed]
    public function pendentes()
    {
        return Lancamento::receber()
            ->pendentes()
            ->whereNotNull('venda_id')
            ->when($this->buscaPendentes, function ($query) {
                $termo = $this->buscaPendentes;
                $query->where(function ($q) use ($termo) {
                    $q->whereHas('cliente', fn ($q2) => $q2->where('cliente_nome', 'like', "%{$termo}%"))
                        ->orWhere('venda_id', 'like', "%{$termo}%");
                });
            })
            ->with(['venda', 'cliente'])
            ->withSum('pagamentos', 'valor')
            ->orderBy('vencimento')
            ->get();
    }

    /** Pagamentos já registrados do título em recebimento no modal — histórico exibido ao caixa. */
    #[Computed]
    public function pagamentosDoLancamentoEmRecebimento()
    {
        if ($this->lancamentoRecebimentoId === null) {
            return collect();
        }

        return Lancamento::find($this->lancamentoRecebimentoId)?->pagamentos ?? collect();
    }

    public function abrirModalRecebimento(int $lancamentoId): void
    {
        $lancamento = Lancamento::find($lancamentoId);
        if (! $lancamento) {
            return;
        }

        $this->lancamentoRecebimentoId = $lancamento->id;
        $this->valorRecebimento = (float) $lancamento->valor_restante;
        $this->formaRecebimento = null;
        $this->cartaoRecebimentoId = null;
        $this->numeroAutorizacaoRecebimento = null;
        $this->modalRecebimentoAberto = true;
    }

    public function fecharModalRecebimento(): void
    {
        $this->modalRecebimentoAberto = false;
        $this->lancamentoRecebimentoId = null;
        $this->valorRecebimento = 0;
        $this->formaRecebimento = null;
        $this->cartaoRecebimentoId = null;
        $this->numeroAutorizacaoRecebimento = null;
    }

    /** Registra um recebimento (parcial ou total) de um título fiado, reaproveitando Lancamento::registrarPagamento(). */
    public function confirmarRecebimento(): void
    {
        $lancamento = Lancamento::find($this->lancamentoRecebimentoId);
        if (! $lancamento || $this->valorRecebimento <= 0) {
            return;
        }

        $forma = $this->formaRecebimento ? FormaPagamento::tryFrom($this->formaRecebimento) : null;
        $ehCartao = in_array($forma, [FormaPagamento::CartaoCredito, FormaPagamento::CartaoDebito], true);

        $lancamento->registrarPagamento(
            valor: $this->valorRecebimento,
            forma: $forma,
            sessaoCaixaId: $this->sessaoCaixaId,
            cartaoId: $ehCartao ? $this->cartaoRecebimentoId : null,
            numeroAutorizacaoCartao: $ehCartao ? $this->numeroAutorizacaoRecebimento : null,
        );

        $this->fecharModalRecebimento();
        unset($this->pendentes);
    }

    public function abrirModalCancelar(): void
    {
        $this->modalCancelarAberta = true;
    }

    public function fecharModalCancelar(): void
    {
        $this->modalCancelarAberta = false;
        $this->motivoCancelamento = null;
    }

    public function confirmarCancelamento(): void
    {
        $venda = Venda::find($this->vendaId);
        if (! $venda || ! $this->motivoCancelamento) {
            return;
        }

        app(FinalizacaoVendaService::class)->cancelar($venda, $this->motivoCancelamento);

        $this->redirect(static::getUrl());
    }
}
