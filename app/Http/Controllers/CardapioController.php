<?php

namespace App\Http\Controllers;

use App\Models\AvaliacaoLink;
use App\Models\Categoria;
use App\Models\HorarioFuncionamento;
use App\Models\OpcoesEntregas;
use App\Models\OpcoesPagamento;
use App\Models\Produto;
use App\Models\PromocaoRelampago;
use Illuminate\Support\Facades\Storage;

class CardapioController extends Controller
{
    public function index()
    {
        // Só categorias de topo entram como seção própria — uma categoria com
        // categoria_pai_id preenchida aparece aninhada dentro da seção da mãe
        // (ver 'filhas' abaixo), não como seção solta no cardápio.
        $categorias = Categoria::whereNull('categoria_pai_id')
            ->where('categoria_cardapio', true)
            ->with([
                'produtos' => function ($query) {
                    $query->visivelCardapio()
                        ->with('categoria')
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
                        ->with('categoria')
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
            ->with(['promocaoProdutos.produto.categoria'])
            ->orderBy('promocao_ordem')
            ->orderBy('promocao_nome')
            ->get()
            ->map(fn (PromocaoRelampago $promo) => [
                'id' => $promo->id,
                'nome' => $promo->promocao_nome,
                'descricao' => $promo->promocao_descricao,
                'exibe_contador' => $promo->deveExibirContador(),
                'saldo' => $promo->saldoDisponivel(),
                'produtos' => $promo->promocaoProdutos
                    ->map(fn ($prp) => $prp->produto)
                    ->filter(fn ($p) => $p && $p->visivelNoCardapio())
                    ->values(),
            ])
            ->filter(fn (array $promo) => $promo['produtos']->isNotEmpty())
            ->values();

        // A janela de datas do produto passa a valer: promoção vencida sai do ar
        // sozinha, sem depender de alguém zerar o preço promocional na mão.
        $promocoes = Produto::visivelCardapio()
            ->comPromocaoDeProdutoVigente()
            ->with('categoria')
            ->orderByDesc('produto_qtd_vendas')
            ->get();

        $maisVendidos = Produto::visivelCardapio()
            ->where('produto_qtd_vendas', '>', 0)
            ->where('produto_destaque_mais_vendidos', true)
            ->with('categoria')
            ->orderByDesc('produto_qtd_vendas')
            ->limit(8)
            ->get();

        $opcoesEntregas = OpcoesEntregas::whereNull('deleted_at')
            ->whereNotIn('opcaoentrega_nome', ['Comer no Local'])
            ->get()
            ->map(fn ($o) => [
                'id' => $o->id,
                'nome' => $o->opcaoentrega_nome,
                'requer_endereco' => str_contains(strtolower($o->opcaoentrega_nome), 'entrega') || str_contains(strtolower($o->opcaoentrega_nome), 'deliver'),
                'valor_frete' => (float) $o->opcaoentrega_valor_frete,
                'min_frete' => (float) $o->opcaoentrega_min_valor_frete,
            ]);

        $opcoesPagamento = OpcoesPagamento::whereNull('deleted_at')
            ->where('opcaopag_aparece_cardapio', true)
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
                'maxSabores' => $c->categoria_max_sabores ?? 2,
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
                        'foto' => $p->getImagemUrl(),
                    ])->values(),
            ])
            ->values();

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

        return view('cardapio', compact('categorias', 'promocoesRelampago', 'promocoes', 'maisVendidos', 'top10Ids', 'opcoesEntregas', 'opcoesPagamento', 'categoriasComSabores', 'estaAberto', 'proximoHorario', 'horarios', 'avaliacaoLinks'));
    }
}
