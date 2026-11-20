<?php

namespace App\Mcp\Tools\Gestao;

use App\Models\User;
use App\Services\Gestao\RotinasGestaoService;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('resumo_do_dia')]
#[Description('Pacote do resumo diário (para a rotina da manhã): vendas do dia × mesmo dia da semana passada e top 5 produtos, caixa (recebido, fiado, conferência por sessão), exceções, NFC-e, Stone, compras (pendências e pagamentos a confirmar) e contas a pagar vencidas/vencendo. Cada seção só vem se o perfil tiver acesso à tela correspondente. Padrão: ontem.')]
#[IsReadOnly]
class ResumoDoDia extends FerramentaGestao
{
    /** Seção => permissão da tela de onde ela vem. */
    public const SECOES = [
        'vendas' => 'view:financeiro_dashboard',
        'caixa' => 'view:relatorio_fechamento_caixa',
        'excecoes' => 'view:relatorio_excecoes_pedidos',
        'fiscal' => 'view_any:nota_fiscal',
        'stone' => 'view_any:stone_pedido',
        'compras' => 'resolver:pendencia_compra',
        'contas' => 'view:relatorio_contas_pagar_receber',
    ];

    public function schema(JsonSchema $schema): array
    {
        return [
            'data' => $schema->string()->description('Dia AAAA-MM-DD (padrão: ontem).'),
        ];
    }

    protected function permitido(User $usuario): bool
    {
        return collect(self::SECOES)->contains(fn (string $permissao): bool => Gate::forUser($usuario)->allows($permissao));
    }

    protected function executar(Request $request): Response
    {
        $data = $request->validate(['data' => ['nullable', 'date_format:Y-m-d']])['data'] ?? null;
        $dia = $data ? Carbon::parse($data) : today()->subDay();
        $rotinas = app(RotinasGestaoService::class);
        $pode = fn (string $secao): bool => Gate::forUser($request->user())->allows(self::SECOES[$secao]);

        return Response::json(array_filter([
            'data' => $dia->toDateString(),
            'dia_da_semana' => $dia->locale('pt_BR')->dayName,
            'vendas' => $pode('vendas') ? $rotinas->vendasDoDia($dia) : null,
            'caixa' => $pode('caixa') ? $rotinas->caixaDoDia($dia) : null,
            'excecoes' => $pode('excecoes') ? $rotinas->excecoesDoDia($dia) : null,
            'fiscal' => $pode('fiscal') ? $rotinas->fiscalDoDia($dia) : null,
            'stone_travadas_7_dias' => $pode('stone') ? $rotinas->stoneTravadas() : null,
            'compras' => $pode('compras') ? $rotinas->comprasEmAberto() : null,
            'contas_a_pagar' => $pode('contas') ? $rotinas->contasProximas() : null,
        ], fn (mixed $valor): bool => $valor !== null));
    }
}
