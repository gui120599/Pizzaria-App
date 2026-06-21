<?php

namespace App\Http\Controllers;

use App\Models\AvaliacaoLink;
use App\Models\Categoria;
use Illuminate\Support\Facades\Storage;
use App\Models\HorarioFuncionamento;
use App\Models\OpcoesPagamento;
use App\Models\OpcoesEntregas;
use App\Models\Produto;
use Illuminate\Http\Request;

class CardapioController extends Controller
{
    public function index()
    {
        $categorias = Categoria::whereHas('produtos', function ($query) {
            $query->where('produto_cardapio', true);
        })
            ->with([
                'produtos' => function ($query) {
                    $query->where('produto_cardapio', true)
                          ->with('categoria')
                          ->orderBy('produto_ordem')
                          ->orderBy('produto_descricao');
                }
            ])
            ->where('categoria_cardapio', true)
            ->orderBy('categoria_ordem')
            ->orderBy('categoria_nome')
            ->get();

        $top10Ids = Produto::where('produto_cardapio', true)
            ->where('produto_destaque_mais_vendidos', true)
            ->where('produto_qtd_vendas', '>', 0)
            ->orderByDesc('produto_qtd_vendas')
            ->limit(10)
            ->pluck('id')
            ->all();

        $promocoes = Produto::where('produto_cardapio', true)
            ->where('produto_preco_promocional', '>', 0)
            ->with('categoria')
            ->orderByDesc('produto_qtd_vendas')
            ->get();

        $maisVendidos = Produto::where('produto_cardapio', true)
            ->where('produto_qtd_vendas', '>', 0)
            ->where('produto_destaque_mais_vendidos', true)
            ->with('categoria')
            ->orderByDesc('produto_qtd_vendas')
            ->limit(8)
            ->get();

        $opcoesEntregas = OpcoesEntregas::whereNull('deleted_at')
            ->whereNotIn('opcaoentrega_nome', ['Comer no Local'])
            ->get()
            ->map(fn($o) => [
                'id'              => $o->id,
                'nome'            => $o->opcaoentrega_nome,
                'requer_endereco' => str_contains(strtolower($o->opcaoentrega_nome), 'entrega') || str_contains(strtolower($o->opcaoentrega_nome), 'deliver'),
                'valor_frete'     => (float) $o->opcaoentrega_valor_frete,
                'min_frete'       => (float) $o->opcaoentrega_min_valor_frete,
            ]);

        $opcoesPagamento = OpcoesPagamento::whereNull('deleted_at')
            ->where('opcaopag_aparece_cardapio', true)
            ->get()
            ->map(fn($p) => [
                'id'        => $p->id,
                'nome'      => $p->opcaopag_nome,
                'descricao' => $p->opcaopag_descricao,
                'dinheiro'  => str_contains(strtolower($p->opcaopag_nome), 'dinheiro'),
            ]);

        $categoriasComSabores = $categorias
            ->filter(fn($c) => $c->categoria_permite_sabores)
            ->map(fn($c) => [
                'id'         => $c->id,
                'nome'       => $c->categoria_nome,
                'maxSabores' => $c->categoria_max_sabores ?? 2,
                'produtos'   => $c->produtos->map(fn($p) => [
                    'id'           => $p->id,
                    'nome'         => $p->produto_descricao,
                    'preco'        => $p->produto_preco_promocional > 0 ? (float) $p->produto_preco_promocional : (float) $p->produto_preco_venda,
                    'precoOriginal'=> (float) $p->produto_preco_venda,
                    'foto'         => $p->getImagemUrl(),
                ])->values(),
            ])
            ->values();

        $estaAberto      = HorarioFuncionamento::estaAberto();
        $proximoHorario  = $estaAberto ? null : HorarioFuncionamento::proximoHorario();

        $horarios = HorarioFuncionamento::where('horario_ativo', true)
            ->get()
            ->map(fn ($h) => [
                'dia'       => (int) $h->horario_dia_semana,
                'abertura'  => substr($h->horario_abertura, 0, 5),
                'fechamento' => substr($h->horario_fechamento, 0, 5),
            ])
            ->values()
            ->all();

        $avaliacaoLinks = AvaliacaoLink::where('avaliacao_link_ativo', true)
            ->orderBy('avaliacao_link_ordem')
            ->get(['avaliacao_link_nome', 'avaliacao_link_url', 'avaliacao_link_logo_url'])
            ->map(fn ($l) => [
                'avaliacao_link_nome'     => $l->avaliacao_link_nome,
                'avaliacao_link_url'      => $l->avaliacao_link_url,
                'avaliacao_link_logo_url' => Storage::disk('public')->url($l->avaliacao_link_logo_url),
            ])
            ->toArray();

        return view('cardapio', compact('categorias', 'promocoes', 'maisVendidos', 'top10Ids', 'opcoesEntregas', 'opcoesPagamento', 'categoriasComSabores', 'estaAberto', 'proximoHorario', 'horarios', 'avaliacaoLinks'));
    }
}
