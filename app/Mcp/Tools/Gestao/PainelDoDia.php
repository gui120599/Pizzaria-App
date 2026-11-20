<?php

namespace App\Mcp\Tools\Gestao;

use App\Mcp\Apps\PainelDoDiaApp;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\RendersApp;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('painel_do_dia')]
#[Description('Mostra o CARTÃO interativo "Painel do dia" no chat (vendas × semana passada, top produtos, caixa e pontos de atenção), com setas para navegar entre os dias. Use quando o usuário pedir o painel, o dashboard ou "como está o dia". Mesmos dados e permissões do resumo_do_dia. Padrão: hoje.')]
#[IsReadOnly]
#[RendersApp(resource: PainelDoDiaApp::class)]
class PainelDoDia extends FerramentaGestao
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'data' => $schema->string()->description('Dia AAAA-MM-DD (padrão: hoje).'),
        ];
    }

    protected function permitido(User $usuario): bool
    {
        return ResumoDoDia::algumaSecao($usuario);
    }

    protected function executar(Request $request): Response
    {
        $data = $request->validate(['data' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today']])['data'] ?? null;
        $dia = $data ? Carbon::parse($data) : today();

        return Response::json([
            ...ResumoDoDia::pacote($dia, $request->user()),
            'hoje' => today()->toDateString(),
            'atualizado_em' => now()->format('H:i'),
        ]);
    }
}
