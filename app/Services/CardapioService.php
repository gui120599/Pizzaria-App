<?php

namespace App\Services;

use App\Models\Adicional;
use App\Models\AvaliacaoLink;
use App\Models\BannerCardapio;
use App\Models\Categoria;
use App\Models\HorarioFuncionamento;
use App\Models\OpcoesEntregas;
use App\Models\OpcoesPagamento;
use App\Models\Pergunta;
use App\Models\Produto;
use App\Models\PromocaoAdicionalRegra;
use App\Models\PromocaoRelampago;
use App\Services\PromocaoCombo\PromocaoComboService;
use App\Support\Cardapio\ProdutoCardapio;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Dados do cardápio público: seções, promoções, sabores, perguntas e adicionais.
 * Fonte única do /Cardapio (delivery) e da página da mesa (QR) — as duas telas
 * mostram o mesmo cardápio; o servidor revalida tudo no envio do pedido.
 */
class CardapioService
{
    /**
     * Categoria do produto com as opções de quantidade de sabores (próprias e
     * herdadas do pai) — politicaSaboresCardapio() consulta as duas por card.
     */
    private const CATEGORIA_COM_SABORES = ['categoria.quantidadesSabores', 'categoria.pai.quantidadesSabores'];

    /**
     * Na mesa (QR), promoção relâmpago restrita a delivery/retirada não entra
     * nos preços nem na vitrine (PrecificadorService::noContextoMesa()).
     *
     * @return array<string, mixed> variáveis da view cardapio
     */
    public function dados(bool $mesa = false): array
    {
        return app(PrecificadorService::class)->noContextoMesa(fn (): array => $this->montarDados($mesa), $mesa);
    }

    /** @return array<string, mixed> */
    private function montarDados(bool $mesa): array
    {
        // Só categorias de topo entram como seção própria — uma categoria com
        // categoria_pai_id preenchida aparece aninhada dentro da seção da mãe
        // (ver 'filhas' abaixo), não como seção solta no cardápio.
        $categorias = Categoria::whereNull('categoria_pai_id')
            ->where('categoria_cardapio', true)
            ->with([
                'quantidadesSabores',
                'filhas.quantidadesSabores',
                'filhas.pai.quantidadesSabores',
                'produtos' => function ($query) {
                    $query->visivelCardapio()
                        ->with(self::CATEGORIA_COM_SABORES)
                        ->orderBy('produto_ordem')
                        ->orderBy('produto_descricao');
                },
                // Só carrega filha que tenha produto visível — evita subtítulo vazio.
                'filhas' => function ($query) {
                    $query->where('categoria_cardapio', true)
                        ->whereHas('produtos', fn ($q) => $q->visivelCardapio())
                        ->orderBy('categoria_ordem')
                        ->orderBy('categoria_nome');
                },
                'filhas.produtos' => function ($query) {
                    $query->visivelCardapio()
                        ->with(self::CATEGORIA_COM_SABORES)
                        ->orderBy('produto_ordem')
                        ->orderBy('produto_descricao');
                },
            ])
            ->where(fn ($query) => $query
                ->whereHas('produtos', fn ($q) => $q->visivelCardapio())
                ->orWhereHas('filhas', fn ($q) => $q->where('categoria_cardapio', true)
                    ->whereHas('produtos', fn ($qq) => $qq->visivelCardapio())))
            ->orderBy('categoria_ordem')
            ->orderBy('categoria_nome')
            ->get();

        $top10Ids = Produto::visivelCardapio()
            ->where('produto_destaque_mais_vendidos', true)
            ->where('produto_qtd_vendas', '>', 0)
            ->orderByDesc('produto_qtd_vendas')
            ->limit(10)
            ->pluck('id')
            ->all();

        // Promoções relâmpago: destaque no topo, com contador de escassez.
        // O preço de cada produto é resolvido no card via precoResolvido() (o
        // PrecificadorService dá precedência à promoção relâmpago vigente).
        $promocoesRelampago = PromocaoRelampago::query()
            ->vigente()
            ->comSaldo()
            ->with(['opcoesPagamento', 'promocaoProdutos.produto.categoria.quantidadesSabores', 'promocaoProdutos.produto.categoria.pai.quantidadesSabores'])
            ->orderBy('promocao_ordem')
            ->orderBy('promocao_nome')
            ->get()
            ->reject(fn (PromocaoRelampago $promo) => $mesa && $promo->restringeEntrega())
            ->map(fn (PromocaoRelampago $promo) => [
                'id' => $promo->id,
                'nome' => $promo->promocao_nome,
                'descricao' => $promo->promocao_descricao,
                'exibe_contador' => $promo->deveExibirContador(),
                'saldo' => $promo->saldoDisponivel(),
                'imagem' => $promo->urlImagem(),
                'imagem_mobile' => $promo->urlImagemMobile(),
                // "Pix ou Dinheiro"; vazio = aceita todas as formas.
                'pagamentos' => $promo->textoPagamentos(),
                // Cronômetro do card: contado a partir do carregamento da página
                // (não depende do relógio do celular). Null = sem fim definido.
                'segundos_restantes' => ($fim = $promo->fimDaOcorrencia()) ? max(0, (int) now()->diffInSeconds($fim, false)) : null,
                'produtos' => $promo->promocaoProdutos
                    ->map(fn ($prp) => $prp->produto)
                    ->filter(fn ($p) => $p && $p->visivelNoCardapio())
                    ->values(),
            ])
            ->filter(fn (array $promo) => $promo['produtos']->isNotEmpty())
            ->values();

        // Promoção "leve outro produto por +R$X": payload indexado pelo produto
        // gatilho, para o carrinho perguntar a oferta no momento certo. O preço
        // do gatilho já vem resolvido (respeita o override, se houver) — o
        // checkout ainda revalida tudo no servidor, isto é só para exibição.
        // Uma regra pode ter N ofertas (o cliente escolhe 1 no modal).
        $regrasAdicionaisAtivas = PromocaoAdicionalRegra::query()
            ->whereHas('promocao', fn ($q) => $q->where('promoad_ativa', true))
            // Ordem definida no formulário da campanha: é a dos gatilhos na vitrine.
            ->orderBy('par_ordem')
            ->orderBy('id')
            ->with(['promocao.opcoesPagamento', 'promocao.categoriaExigida', 'ofertas.produtoOferta.categoria', 'produtoGatilho.categoria'])
            ->get()
            // Teto da campanha esgotado: sai do cardápio, como a relâmpago (comSaldo()).
            ->filter(fn (PromocaoAdicionalRegra $regra) => $regra->promocao->vigente() && ! $regra->promocao->esgotada())
            // Na mesa (QR) não entra campanha restrita a delivery/retirada.
            ->reject(fn (PromocaoAdicionalRegra $regra) => $mesa && $regra->promocao->restringeEntrega());

        // Produtos da categoria exigida por campanha (ex.: Pizza Grande): o
        // carrinho só oferece quando há unidade sobrando. O servidor revalida.
        $produtosExigidos = $regrasAdicionaisAtivas
            ->pluck('promocao')
            ->unique('id')
            ->filter(fn ($promocao) => $promocao->promoad_categoria_exigida_id)
            ->mapWithKeys(fn ($promocao) => [$promocao->id => Produto::where('produto_categoria_id', $promocao->promoad_categoria_exigida_id)->pluck('id')->all()]);

        $promocoesAdicionais = $regrasAdicionaisAtivas
            ->mapWithKeys(fn (PromocaoAdicionalRegra $regra) => [
                $regra->par_produto_gatilho_id => [
                    'regraId' => $regra->id,
                    'promocaoId' => $regra->par_promocao_id,
                    // Pizza de sabores ganha a oferta se todos os sabores forem
                    // gatilho desta campanha e ela valer para meia a meia.
                    'aplicaFracionado' => (bool) $regra->promocao->promoad_aplica_fracionado,
                    // null = sem exigência; senão, ids dos produtos da categoria exigida.
                    'produtosExigidos' => $produtosExigidos[$regra->par_promocao_id] ?? null,
                    'categoriaExigida' => $regra->promocao->categoriaExigida?->categoria_nome,
                    'ofertas' => $regra->ofertas
                        ->filter(fn ($oferta) => ! $oferta->esgotada() && $oferta->produtoOferta?->visivelNoCardapio())
                        ->map(fn ($oferta) => [
                            'ofertaId' => $oferta->id,
                            'produtoId' => $oferta->pao_produto_oferta_id,
                            'nome' => $oferta->produtoOferta->produto_descricao,
                            'nomeExibicao' => trim(($oferta->produtoOferta->categoria?->categoria_nome ?? '').': '.$oferta->produtoOferta->produto_descricao, ': '),
                            'foto' => $oferta->produtoOferta->getMiniaturaUrl(56),
                            'valorAdicional' => (float) $oferta->pao_valor_adicional,
                        ])->values(),
                    // [] = sem restrição, todas as formas de pagamento valem.
                    'opcoesPagamentoPermitidas' => $regra->promocao->opcoesPagamento->pluck('id')->all(),
                ],
            ])
            ->filter(fn (array $regra) => $regra['ofertas']->isNotEmpty());

        // Campanhas com ao menos 1 oferta disponível agora — seção informativa
        // (nome + descrição) com os produtos-gatilho que disparam a promoção,
        // nos mesmos moldes da seção de Relâmpago.
        $campanhasAdicionaisAtivas = $regrasAdicionaisAtivas
            ->filter(fn (PromocaoAdicionalRegra $regra) => $promocoesAdicionais->has($regra->par_produto_gatilho_id)
                && $regra->produtoGatilho?->visivelNoCardapio())
            ->groupBy('par_promocao_id')
            ->map(function ($regras) use ($promocoesAdicionais) {
                $promocao = $regras->first()->promocao;

                // Campanha com categoria exigida (ex.: Pizza Grande + refri 2L):
                // a vitrine mostra os produtos da categoria — o carrinho sugere o
                // gatilho ao pôr a pizza na sacola. A descrição explica a regra.
                $exigidos = $promocao->promoad_categoria_exigida_id
                    ? Produto::visivelCardapio()
                        ->where('produto_categoria_id', $promocao->promoad_categoria_exigida_id)
                        ->with(self::CATEGORIA_COM_SABORES)
                        ->orderByDesc('produto_qtd_vendas')
                        ->get()
                        ->map(fn (Produto $produto) => ['produto' => $produto, 'ofertas' => null])
                    : collect();

                return [
                    'promocaoId' => $promocao->id,
                    'nome' => $promocao->promoad_nome,
                    'descricao' => $promocao->promoad_descricao,
                    'categoriaExigida' => $promocao->categoriaExigida?->categoria_nome,
                    // Brindes que a campanha oferece agora (anúncio ao abrir o cardápio).
                    'brindes' => $regras
                        ->flatMap(fn (PromocaoAdicionalRegra $regra) => $promocoesAdicionais[$regra->par_produto_gatilho_id]['ofertas'] ?? [])
                        ->unique('produtoId')
                        ->values(),
                    // Brindes restantes no teto da campanha; null = sem limite (não exibe).
                    'saldo' => $promocao->saldoDisponivel(),
                    'produtos' => $exigidos->isNotEmpty() ? $exigidos->values() : $regras
                        ->map(fn (PromocaoAdicionalRegra $regra) => [
                            'produto' => $regra->produtoGatilho,
                            'ofertas' => $promocoesAdicionais[$regra->par_produto_gatilho_id]['ofertas'],
                        ])
                        ->unique(fn (array $item) => $item['produto']->id)
                        ->values(),
                ];
            })
            ->values();

        // A janela de datas do produto passa a valer: promoção vencida sai do ar
        // sozinha, sem depender de alguém zerar o preço promocional na mão.
        $promocoes = Produto::visivelCardapio()
            ->comPromocaoDeProdutoVigente()
            ->with(self::CATEGORIA_COM_SABORES)
            ->orderByDesc('produto_qtd_vendas')
            ->get();

        $maisVendidos = Produto::visivelCardapio()
            ->where('produto_qtd_vendas', '>', 0)
            ->where('produto_destaque_mais_vendidos', true)
            ->with(self::CATEGORIA_COM_SABORES)
            ->orderByDesc('produto_qtd_vendas')
            ->limit(8)
            ->get();

        $opcoesEntregas = OpcoesEntregas::whereNull('deleted_at')
            ->whereNotIn('opcaoentrega_nome', ['Comer no Local'])
            ->get()
            ->map(fn ($o) => [
                'id' => $o->id,
                'nome' => $o->opcaoentrega_nome,
                'requer_endereco' => (bool) $o->opcaoentrega_requer_endereco,
                'valor_frete' => (float) $o->opcaoentrega_valor_frete,
                'min_frete' => (float) $o->opcaoentrega_min_valor_frete,
            ]);

        $opcoesPagamento = OpcoesPagamento::whereNull('deleted_at')
            ->where('opcaopag_aparece_cardapio', true)
            ->semCarteira()
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'nome' => $p->opcaopag_nome,
                'descricao' => $p->opcaopag_descricao,
                'dinheiro' => str_contains(strtolower($p->opcaopag_nome), 'dinheiro'),
            ]);

        // Pai e filhas juntos: a categoria com sabores habilitados pode ser tanto
        // uma seção de topo quanto uma filha aninhada dentro de outra.
        $categoriasComSabores = $categorias
            ->flatMap(fn ($c) => collect([$c])->merge($c->filhas))
            ->filter(fn ($c) => $c->categoria_permite_sabores)
            ->map(fn ($c) => [
                'id' => $c->id,
                'nome' => $c->categoria_nome,
                'maxSabores' => $c->maxSabores(),
                'regraPreco' => $c->regraPrecoSaboresResolvida()->value,
                // Só muda o desenho da montagem (pizza quadrada é dividida em faixas).
                'formato' => Str::lower($c->formatoPizza()->value),
                // Opções de quantidade (≥ 2): o cliente escolhe primeiro quantos
                // sabores, depois quais — o servidor recusa quantidade sem opção.
                'opcoesSabores' => $c->quantidadesSaboresResolvidas()
                    ->where('quantidade_sabor_quantidade', '>=', 2)
                    ->map(fn ($o) => [
                        'quantidade' => $o->quantidade_sabor_quantidade,
                        'descricao' => $o->quantidade_sabor_descricao,
                    ])->values(),
                // Produto em promoção relâmpago "só inteira" também entra na lista
                // de sabores: como fração ele volta ao preço normal (precoFracao),
                // enquanto inteiro (1 sabor) sai pelo promocional (preco).
                'produtos' => $c->produtos
                    ->filter(fn ($p) => $p->permiteSaboresCardapio())
                    ->map(fn ($p) => [
                        'id' => $p->id,
                        'nome' => $p->produto_descricao,
                        'preco' => $p->precoResolvido()->precoFinal(),
                        'precoFracao' => $p->precoFracaoCardapio(),
                        'precoOriginal' => (float) $p->produto_preco_venda,
                        'foto' => $p->getMiniaturaUrl(56),
                        'ingredientes' => filled($p->produto_codimentacao) ? trim($p->produto_codimentacao) : null,
                    ])->values(),
            ])
            ->values();

        [$perguntasProdutos, $perguntasCombos] = $this->perguntasDoCardapio($categoriasComSabores->pluck('id')->all());

        // Adicionais vinculados a cada produto, para o carrinho oferecer na
        // montagem do item. Na pizza de sabores valem só os vinculados a todos
        // os sabores (o carrinho cruza as listas; o servidor revalida).
        // A foto é resolvida uma vez por adicional (o mesmo adicional se repete
        // em muitos produtos e cada resolução consulta o disco).
        $fotosAdicionais = [];
        $adicionaisProdutos = Produto::visivelCardapio()
            ->whereHas('adicionais')
            ->with('adicionais')
            ->get()
            ->mapWithKeys(fn (Produto $produto) => [$produto->id => $produto->adicionais
                ->map(function (Adicional $adicional) use (&$fotosAdicionais) {
                    $fotosAdicionais[$adicional->id] ??= ['url' => $adicional->getMiniaturaUrl()];

                    return [
                        'id' => $adicional->id,
                        'nome' => $adicional->adicional_nome,
                        'valor' => (float) $adicional->adicional_valor,
                        'foto' => $fotosAdicionais[$adicional->id]['url'],
                    ];
                })
                ->values()
                ->all()]);

        $estaAberto = HorarioFuncionamento::estaAberto();
        $proximoHorario = $estaAberto ? null : HorarioFuncionamento::proximoHorario();

        $horarios = HorarioFuncionamento::where('horario_ativo', true)
            ->get()
            ->map(fn ($h) => [
                'dia' => (int) $h->horario_dia_semana,
                'abertura' => substr($h->horario_abertura, 0, 5),
                'fechamento' => substr($h->horario_fechamento, 0, 5),
            ])
            ->values()
            ->all();

        $avaliacaoLinks = AvaliacaoLink::where('avaliacao_link_ativo', true)
            ->orderBy('avaliacao_link_ordem')
            ->get(['avaliacao_link_nome', 'avaliacao_link_url', 'avaliacao_link_logo_url'])
            ->map(fn ($l) => [
                'avaliacao_link_nome' => $l->avaliacao_link_nome,
                'avaliacao_link_url' => $l->avaliacao_link_url,
                'avaliacao_link_logo_url' => Storage::disk('public')->url($l->avaliacao_link_logo_url),
            ])
            ->toArray();

        // Um apresentador por produto exibido (card, vitrine e sheet de detalhe).
        $produtosTela = ProdutoCardapio::mapa(collect()
            ->merge($categorias->flatMap(fn ($c) => $c->produtos->merge($c->filhas->flatMap->produtos)))
            ->merge($promocoesRelampago->flatMap(fn (array $promo) => $promo['produtos']))
            ->merge($campanhasAdicionaisAtivas->flatMap(fn (array $campanha) => $campanha['produtos']->pluck('produto')))
            // Gatilhos fora da vitrine (campanha com categoria exigida mostra a
            // categoria): o modal de sugestão ainda precisa deles.
            ->merge($regrasAdicionaisAtivas->pluck('produtoGatilho')->filter(fn ($produto) => $produto?->visivelNoCardapio()))
            ->merge($promocoes)
            ->merge($maisVendidos));

        // Campanhas com categoria exigida (ex.: Pizza Grande): ao pôr a pizza na
        // sacola, o carrinho sugere os produtos-gatilho (ex.: refris 2L); com o
        // gatilho antes da pizza, avisa o que falta. Só gatilhos com oferta agora.
        $campanhasExigencia = $regrasAdicionaisAtivas
            ->filter(fn (PromocaoAdicionalRegra $regra) => $regra->promocao->promoad_categoria_exigida_id
                && $promocoesAdicionais->has($regra->par_produto_gatilho_id)
                && isset($produtosTela[$regra->par_produto_gatilho_id]))
            ->groupBy('par_promocao_id')
            ->map(function ($regras, $promocaoId) use ($produtosExigidos, $produtosTela) {
                $promocao = $regras->first()->promocao;

                return [
                    'promocaoId' => (int) $promocaoId,
                    'nome' => $promocao->promoad_nome,
                    'descricao' => $promocao->promoad_descricao,
                    'categoriaExigida' => $promocao->categoriaExigida?->categoria_nome,
                    'produtosExigidos' => $produtosExigidos[$promocaoId] ?? [],
                    'gatilhos' => $regras
                        ->map(fn (PromocaoAdicionalRegra $regra) => $produtosTela[$regra->par_produto_gatilho_id])
                        ->unique('id')
                        ->map(fn ($p) => [
                            'id' => $p->id,
                            'nome' => $p->nome,
                            'preco' => $p->preco,
                            'precoVenda' => $p->precoVenda,
                            'foto' => $p->temFoto() ? $p->foto(48) : '',
                        ])
                        ->values(),
                ];
            })
            ->values();

        // Promoção Combo ("2 grandes por R$ 87,99"): os produtos exibidos que
        // participam, para o convite de completar o combo na sacola.
        $promocoesCombo = app(PromocaoComboService::class)->paraCardapio(
            collect($produtosTela)->map(fn ($p): array => ['categoria_id' => $p->categoriaId, 'relampago' => $p->relampago])->all(),
            $mesa,
        );

        // Carrossel do topo (Cardápio › Banners do cardápio): só os no ar
        // agora, na ordem do painel, com o destino do toque já validado.
        $banners = BannerCardapio::noArPara($mesa)
            ->map(fn (BannerCardapio $banner): array => [
                'id' => $banner->id,
                'titulo' => $banner->banner_titulo,
                'imagem' => $banner->urlImagem(),
                'imagem_mobile' => $banner->urlImagemMobile(),
                'destino' => $banner->destino(),
            ])
            ->all();

        $anunciosPromocoes = $this->anunciosPromocoes($promocoesCombo, $promocoesRelampago, $campanhasAdicionaisAtivas);

        // "Leve também" (Produto::leveTambem): produtoId => ids sugeridos na
        // ordem do cadastro, só os que estão na tela (têm card e preço).
        $leveTambem = Produto::whereIn('id', $produtosTela->keys())
            ->whereHas('leveTambem')
            ->with('leveTambem:produtos.id')
            ->get()
            ->mapWithKeys(fn (Produto $produto): array => [
                $produto->id => $produto->leveTambem->pluck('id')
                    ->filter(fn (int $id): bool => $produtosTela->has($id) && $id !== $produto->id)
                    ->values()
                    ->all(),
            ])
            ->filter(fn (array $ids): bool => $ids !== [])
            ->all();

        return compact('banners', 'anunciosPromocoes', 'leveTambem', 'promocoesCombo', 'campanhasExigencia', 'produtosTela', 'categorias', 'promocoesRelampago', 'promocoesAdicionais', 'campanhasAdicionaisAtivas', 'promocoes', 'maisVendidos', 'top10Ids', 'opcoesEntregas', 'opcoesPagamento', 'categoriasComSabores', 'perguntasProdutos', 'perguntasCombos', 'adicionaisProdutos', 'estaAberto', 'proximoHorario', 'horarios', 'avaliacaoLinks');
    }

    /**
     * Aviso ao abrir o cardápio: uma entrada por promoção em destaque, na ordem
     * em que aparecem na página (combo por prioridade, relâmpago, "leve outro
     * produto"). `secao` é o id da seção para o botão "Ver" rolar até ela.
     *
     * @param  list<array<string, mixed>>  $combos
     * @param  Collection<int, array<string, mixed>>  $relampagos
     * @param  Collection<int, array<string, mixed>>  $campanhas
     * @return list<array{chave: string, tipo: string, nome: string, descricao: ?string, destaque: string, nota: ?string, imagem: ?string, imagem_mobile: ?string, saldo: float|int|null, secao: string, segundos_restantes?: ?int}>
     */
    private function anunciosPromocoes(array $combos, Collection $relampagos, Collection $campanhas): array
    {
        $dinheiro = fn (float $valor): string => 'R$ '.number_format($valor, 2, ',', '.');
        $anuncios = [];

        foreach (array_values($combos) as $indice => $combo) {
            $anuncios[] = [
                'chave' => 'combo-'.$combo['promocaoId'],
                'tipo' => 'combo',
                'nome' => $combo['nome'],
                'descricao' => $combo['descricao'],
                'destaque' => 'Leve '.$combo['composicao'].' por '.$dinheiro((float) $combo['preco']),
                'nota' => 'Bordas e adicionais são cobrados à parte.',
                'imagem' => $combo['imagem'],
                'imagem_mobile' => $combo['imagem_mobile'],
                'saldo' => $combo['restam'],
                'secao' => $indice === 0 ? 'secao_combo' : 'secao_combo_'.$combo['promocaoId'],
            ];
        }

        foreach ($relampagos->values() as $indice => $promo) {
            $menor = $promo['produtos']->map(fn (Produto $produto): float => $produto->precoResolvido()->precoFinal())->min();

            $anuncios[] = [
                'chave' => 'relampago-'.$promo['id'],
                'tipo' => 'relampago',
                'nome' => $promo['nome'],
                'descricao' => $promo['descricao'],
                'destaque' => $promo['produtos']->count() === 1
                    ? $promo['produtos']->first()->produto_descricao.' por '.$dinheiro((float) $menor)
                    : 'Preços a partir de '.$dinheiro((float) $menor),
                'nota' => implode(' ', array_filter([
                    $promo['pagamentos'] !== '' ? 'Válido para pagamento em '.$promo['pagamentos'].'.' : null,
                    $promo['saldo'] !== null ? 'Quantidade limitada.' : null,
                ])) ?: null,
                'imagem' => $promo['imagem'],
                'imagem_mobile' => $promo['imagem_mobile'],
                'saldo' => $promo['exibe_contador'] ? $promo['saldo'] : null,
                'segundos_restantes' => $promo['segundos_restantes'],
                'secao' => $indice === 0 ? 'secao_relampago' : 'secao_relampago_'.$promo['id'],
            ];
        }

        foreach ($campanhas->values() as $indice => $campanha) {
            $brindes = collect($campanha['brindes']);
            $gratis = $brindes->every(fn (array $b): bool => (float) $b['valorAdicional'] <= 0);
            $menorValor = (float) $brindes->min('valorAdicional');

            $destaque = match (true) {
                $brindes->count() === 1 && $gratis => 'Ganhe '.$brindes->first()['nomeExibicao'].' grátis',
                $brindes->count() === 1 => 'Leve '.$brindes->first()['nomeExibicao'].' por +'.$dinheiro($menorValor),
                $gratis => 'Ganhe um brinde grátis à sua escolha',
                default => 'Leve outro produto a partir de +'.$dinheiro($menorValor),
            };

            $anuncios[] = [
                'chave' => 'adicional-'.$campanha['promocaoId'],
                'tipo' => 'adicional',
                'nome' => $campanha['nome'],
                'descricao' => $campanha['descricao'],
                'destaque' => $destaque,
                'nota' => $campanha['categoriaExigida'] ? 'Válido na compra de 1 '.$campanha['categoriaExigida'].'.' : null,
                'imagem' => null,
                'imagem_mobile' => null,
                'saldo' => $campanha['saldo'],
                'secao' => $indice === 0 ? 'secao_promocao_adicional' : 'secao_promocao_adicional_'.$indice,
            ];
        }

        return $anuncios;
    }

    /**
     * Perguntas do item para o carrinho perguntar antes de adicionar, na mesma
     * ordem do servidor (Produto/Categoria::perguntasAplicaveis()): as da
     * categoria mãe, as da categoria e, no item comum, as do produto. Uma
     * consulta só, em vez de uma por produto.
     *
     * @param  list<int>  $categoriasComSaboresIds
     * @return array{0: Collection<int, list<array<string, mixed>>>, 1: Collection<int, list<array<string, mixed>>>} [por produto, por categoria de sabores]
     */
    private function perguntasDoCardapio(array $categoriasComSaboresIds): array
    {
        $perguntas = Pergunta::query()->ativas()->with('opcoesAtivas')->orderBy('pergunta_ordem')->orderBy('id')->get();

        if ($perguntas->isEmpty()) {
            return [collect(), collect()];
        }

        $daCategoriaDireta = $perguntas->whereNotNull('pergunta_categoria_id')->groupBy('pergunta_categoria_id');
        $doProduto = $perguntas->whereNotNull('pergunta_produto_id')->groupBy('pergunta_produto_id');
        $paiDaCategoria = Categoria::pluck('categoria_pai_id', 'id');

        $daCategoria = fn (?int $categoriaId): Collection => $categoriaId === null
            ? collect()
            : collect($daCategoriaDireta->get($paiDaCategoria[$categoriaId] ?? 0, []))->concat($daCategoriaDireta->get($categoriaId, []));

        $porProduto = Produto::visivelCardapio()
            ->get(['id', 'produto_categoria_id'])
            ->mapWithKeys(fn (Produto $produto) => [$produto->id => $daCategoria($produto->produto_categoria_id)
                ->concat($doProduto->get($produto->id, []))
                ->map(fn (Pergunta $pergunta) => $pergunta->paraTela())
                ->values()
                ->all()])
            ->filter();

        $porCategoria = collect($categoriasComSaboresIds)
            ->mapWithKeys(fn (int $categoriaId) => [$categoriaId => $daCategoria($categoriaId)
                ->map(fn (Pergunta $pergunta) => $pergunta->paraTela())
                ->values()
                ->all()])
            ->filter();

        return [$porProduto, $porCategoria];
    }
}
