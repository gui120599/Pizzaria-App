<?php

namespace App\Mcp\Tools\Gestao;

use App\Enums\StatusPedidoEnum;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('desempenho_promocoes')]
#[Description('Quanto cada promoção vendeu num período (pedidos não cancelados, pela data de abertura): Promoção Relâmpago e Produto Adicional (itens e valor) e Promoção Combo (aplicações, valor cobrado e desconto concedido).')]
#[IsReadOnly]
class DesempenhoPromocoes extends FerramentaGestao
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'inicio' => $schema->string()->description('AAAA-MM-DD (padrão: hoje).'),
            'fim' => $schema->string()->description('AAAA-MM-DD (padrão: igual ao início).'),
        ];
    }

    protected function permitido(User $usuario): bool
    {
        return Gate::forUser($usuario)->allows('view:financeiro_dashboard');
    }

    protected function executar(Request $request): Response
    {
        [$inicio, $fim] = $this->periodo($request, 400);

        $pedidosValidos = fn (Builder $query): Builder => $query
            ->whereNull('pedidos.deleted_at')
            ->where('pedidos.pedido_status', '!=', StatusPedidoEnum::CANCELADO->value)
            ->whereBetween('pedidos.pedido_datahora_abertura', [$inicio, $fim]);

        $itens = fn (string $coluna, string $tabela, string $chave, string $nome) => DB::table('itens_pedidos')
            ->join('pedidos', 'pedidos.id', '=', 'itens_pedidos.item_pedido_pedido_id')
            ->join($tabela, "{$tabela}.id", '=', "itens_pedidos.{$coluna}")
            ->where('itens_pedidos.item_pedido_status', 'INSERIDO')
            ->tap($pedidosValidos)
            ->groupBy("{$tabela}.{$chave}")
            ->selectRaw("{$tabela}.{$nome} as promocao, SUM(itens_pedidos.item_pedido_quantidade) as itens, SUM(itens_pedidos.item_pedido_valor) as valor")
            ->orderByDesc('valor')
            ->get()
            ->map(fn (object $l): array => ['promocao' => $l->promocao, 'itens' => round((float) $l->itens, 2), 'valor' => round((float) $l->valor, 2)])
            ->all();

        $relampago = $itens('item_pedido_promocao_id', 'promocoes_relampago', 'id', 'promocao_nome');

        $adicional = DB::table('itens_pedidos')
            ->join('pedidos', 'pedidos.id', '=', 'itens_pedidos.item_pedido_pedido_id')
            ->join('promocao_adicional_regras', 'promocao_adicional_regras.id', '=', 'itens_pedidos.item_pedido_promocao_adicional_regra_id')
            ->join('promocoes_adicionais', 'promocoes_adicionais.id', '=', 'promocao_adicional_regras.par_promocao_id')
            ->where('itens_pedidos.item_pedido_status', 'INSERIDO')
            ->tap($pedidosValidos)
            ->groupBy('promocoes_adicionais.id', 'promocoes_adicionais.promoad_nome')
            ->selectRaw('promocoes_adicionais.promoad_nome as promocao, SUM(itens_pedidos.item_pedido_quantidade) as itens, SUM(itens_pedidos.item_pedido_valor) as valor')
            ->orderByDesc('valor')
            ->get()
            ->map(fn (object $l): array => ['promocao' => $l->promocao, 'itens' => round((float) $l->itens, 2), 'valor' => round((float) $l->valor, 2)])
            ->all();

        $combo = DB::table('pedido_combo_aplicacoes')
            ->join('pedidos', 'pedidos.id', '=', 'pedido_combo_aplicacoes.pca_pedido_id')
            ->tap($pedidosValidos)
            ->groupBy('pedido_combo_aplicacoes.pca_nome')
            ->selectRaw('pedido_combo_aplicacoes.pca_nome as promocao, COUNT(*) as aplicacoes, SUM(pca_preco) as valor, SUM(pca_desconto) as desconto')
            ->orderByDesc('valor')
            ->get()
            ->map(fn (object $l): array => ['promocao' => $l->promocao, 'aplicacoes' => (int) $l->aplicacoes, 'valor' => round((float) $l->valor, 2), 'desconto' => round((float) $l->desconto, 2)])
            ->all();

        return Response::json([
            'periodo' => self::rotuloPeriodo($inicio, $fim),
            'promocao_relampago' => $relampago,
            'produto_adicional' => $adicional,
            'promocao_combo' => $combo,
        ]);
    }
}
