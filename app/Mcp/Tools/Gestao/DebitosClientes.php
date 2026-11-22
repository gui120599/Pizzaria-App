<?php

namespace App\Mcp\Tools\Gestao;

use App\Models\Cliente;
use App\Models\User;
use App\Services\DebitosClienteService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('debitos_clientes')]
#[Description('Fiado: clientes com saldo em aberto, do maior devedor para o menor (como o Relatório de Débitos de Clientes), com total, vencido, nº de títulos e o vencimento mais antigo. Telefone mascarado.')]
#[IsReadOnly]
class DebitosClientes extends FerramentaGestao
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'somente_vencidos' => $schema->boolean()->description('Só títulos vencidos.'),
            'busca' => $schema->string()->description('Nome ou telefone do cliente.'),
            'limite' => $schema->integer()->min(1)->max(50)->description('Quantos clientes listar (padrão 15).'),
        ];
    }

    protected function permitido(User $usuario): bool
    {
        return Gate::forUser($usuario)->allows('viewAny', Cliente::class);
    }

    protected function executar(Request $request): Response
    {
        $dados = $request->validate([
            'somente_vencidos' => ['nullable', 'boolean'],
            'busca' => ['nullable', 'string', 'max:100'],
            'limite' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $servico = new DebitosClienteService([
            'somente_vencidos' => (bool) ($dados['somente_vencidos'] ?? false),
            'busca' => $dados['busca'] ?? null,
        ]);

        return Response::json([
            'totais' => $servico->totais(),
            'clientes' => $servico->porCliente()->take((int) ($dados['limite'] ?? 15))->map(fn (array $debito): array => [
                'cliente_id' => $debito['cliente']?->id,
                'cliente' => $debito['cliente']?->cliente_nome ?? 'Sem cliente',
                'telefone' => self::mascarar($debito['cliente']?->cliente_celular),
                'total' => $debito['total'],
                'vencido' => $debito['vencido'],
                'titulos' => $debito['titulos']->count(),
                'vencimento_mais_antigo' => self::maisAntigo($debito['titulos']),
            ])->values()->all(),
        ]);
    }

    /** LGPD: só os 4 últimos dígitos. */
    public static function mascarar(?string $telefone): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $telefone);

        return strlen((string) $digitos) >= 4 ? '••••'.substr((string) $digitos, -4) : null;
    }

    /** @param  Collection<int, array<string, mixed>>  $titulos */
    private static function maisAntigo(Collection $titulos): ?string
    {
        return $titulos->map(fn (array $titulo) => $titulo['lancamento']->vencimento)->filter()->min()?->toDateString();
    }
}
