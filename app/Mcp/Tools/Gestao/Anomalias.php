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

#[Name('anomalias')]
#[Description('Monitor de anomalias de um dia: cancelamentos, cortesias e descontos acima da média do mesmo dia da semana, diferenças de caixa no fechamento, NFC-e com falha, cobranças Stone travadas e estoque negativo. Devolve só o que fugiu do normal, com severidade. Cada verificação só roda se o perfil tiver acesso à tela correspondente. Padrão: ontem.')]
#[IsReadOnly]
class Anomalias extends FerramentaGestao
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'data' => $schema->string()->description('Dia AAAA-MM-DD (padrão: ontem).'),
        ];
    }

    protected function permitido(User $usuario): bool
    {
        return collect(ResumoDoDia::SECOES)->contains(fn (string $permissao): bool => Gate::forUser($usuario)->allows($permissao));
    }

    protected function executar(Request $request): Response
    {
        $data = $request->validate(['data' => ['nullable', 'date_format:Y-m-d']])['data'] ?? null;
        $dia = $data ? Carbon::parse($data) : today()->subDay();
        $rotinas = app(RotinasGestaoService::class);
        $pode = fn (string $permissao): bool => Gate::forUser($request->user())->allows($permissao);
        $alertas = [];
        $verificado = [];

        if ($pode('view:relatorio_excecoes_pedidos')) {
            $alertas = [...$alertas, ...$rotinas->anomaliasDeExcecoes($dia)];
            $verificado[] = 'cancelamentos, cortesias e descontos';
        }

        if ($pode('view:relatorio_fechamento_caixa')) {
            $alertas = [...$alertas, ...$rotinas->anomaliasDeCaixa($dia)];
            $verificado[] = 'diferenças de caixa';
        }

        if ($pode('view_any:nota_fiscal')) {
            $fiscal = $rotinas->fiscalDoDia($dia);
            if ($fiscal['com_falha'] + $fiscal['pendentes_de_envio'] > 0) {
                $alertas[] = $rotinas->alerta('nfce_com_problema', 'media', "{$fiscal['com_falha']} NFC-e com falha e {$fiscal['pendentes_de_envio']} pendente(s) de envio — reenviar pelo painel.", $fiscal);
            }
            $verificado[] = 'NFC-e';
        }

        if ($pode('view_any:stone_pedido')) {
            $travadas = $rotinas->stoneTravadas();
            if ($travadas > 0) {
                $alertas[] = $rotinas->alerta('stone_travada', 'alta', "{$travadas} cobrança(s) Stone com falha, indeterminada ou parada há mais de 30 min nos últimos 7 dias.", ['quantidade' => $travadas]);
            }
            $verificado[] = 'Stone';
        }

        if ($pode('view_any:produto')) {
            $alertas = [...$alertas, ...$rotinas->anomaliasDeEstoque()];
            $verificado[] = 'estoque negativo';
        }

        return Response::json([
            'data' => $dia->toDateString(),
            'verificado' => $verificado,
            'total_alertas' => count($alertas),
            'alertas' => collect($alertas)->sortBy(fn (array $a): int => ['alta' => 0, 'media' => 1, 'baixa' => 2][$a['severidade']] ?? 3)->values()->all(),
        ]);
    }
}
