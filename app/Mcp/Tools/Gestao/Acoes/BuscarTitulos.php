<?php

namespace App\Mcp\Tools\Gestao\Acoes;

use App\Enums\Gestao\TipoAcaoClaude;
use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Mcp\Tools\Gestao\FerramentaGestao;
use App\Models\Lancamento;
use App\Models\User;
use App\Services\Gestao\Acoes\AcoesClaudeService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('buscar_titulos')]
#[Description('Títulos em aberto (pendentes ou parciais) de contas a pagar e a receber, para achar o lancamento_id antes de preparar_baixa_titulos. Filtra por texto (descrição, fornecedor ou cliente), tipo, vencimento e valor aproximado. Ordena pelo vencimento.')]
#[IsReadOnly]
class BuscarTitulos extends FerramentaGestao
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'busca' => $schema->string()->description('Parte da descrição, do fornecedor ou do cliente.'),
            'tipo' => $schema->string()->enum(['pagar', 'receber']),
            'vencimento_ate' => $schema->string()->description('AAAA-MM-DD. Ex.: hoje, para os vencidos e de hoje.'),
            'vencimento_de' => $schema->string()->description('AAAA-MM-DD.'),
            'valor' => $schema->number()->description('Valor aproximado (±2%) do título ou do que falta.'),
            'limite' => $schema->integer()->min(1)->max(50)->description('Padrão 20.'),
        ];
    }

    protected function permitido(User $usuario): bool
    {
        return app(AcoesClaudeService::class)->permitido(TipoAcaoClaude::BaixaTitulos, $usuario);
    }

    protected function executar(Request $request): Response
    {
        $dados = $request->validate([
            'busca' => ['nullable', 'string', 'max:100'],
            'tipo' => ['nullable', 'in:pagar,receber'],
            'vencimento_ate' => ['nullable', 'date_format:Y-m-d'],
            'vencimento_de' => ['nullable', 'date_format:Y-m-d'],
            'valor' => ['nullable', 'numeric', 'gt:0'],
            'limite' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $titulos = Lancamento::query()
            ->whereIn('status', [StatusLancamento::Pendente, StatusLancamento::Parcial])
            ->whereNull('maquininha_id')
            ->when($dados['tipo'] ?? null, fn ($q, string $tipo) => $q->where('tipo', $tipo === 'pagar' ? TipoLancamento::Pagar : TipoLancamento::Receber))
            ->when($dados['vencimento_ate'] ?? null, fn ($q, string $data) => $q->whereDate('vencimento', '<=', $data))
            ->when($dados['vencimento_de'] ?? null, fn ($q, string $data) => $q->whereDate('vencimento', '>=', $data))
            ->when($dados['busca'] ?? null, fn ($q, string $busca) => $q->where(fn ($w) => $w
                ->where('descricao', 'like', "%{$busca}%")
                ->orWhereHas('favorecido', fn ($f) => $f->where('nome', 'like', "%{$busca}%")->orWhere('nome_fantasia', 'like', "%{$busca}%")->orWhere('razao_social', 'like', "%{$busca}%"))
                ->orWhereHas('cliente', fn ($c) => $c->where('cliente_nome', 'like', "%{$busca}%"))))
            ->with(['favorecido', 'cliente', 'pagamentos'])
            ->orderBy('vencimento')
            ->limit(($dados['valor'] ?? null) ? 200 : ($dados['limite'] ?? 20))
            ->get()
            ->when($dados['valor'] ?? null, fn ($lista, $valor) => $lista->filter(fn (Lancamento $t): bool => abs((float) $t->valor - $valor) <= $valor * 0.02
                || abs((float) $t->valorRestante - $valor) <= $valor * 0.02))
            ->take($dados['limite'] ?? 20);

        return Response::json([
            'total' => $titulos->count(),
            'titulos' => $titulos->map(fn (Lancamento $t): array => [
                'lancamento_id' => $t->id,
                'tipo' => $t->tipo === TipoLancamento::Pagar ? 'pagar' : 'receber',
                'descricao' => $t->descricao,
                'fornecedor_ou_cliente' => $t->tipo === TipoLancamento::Pagar ? $t->favorecido?->nome_exibicao : $t->cliente?->cliente_nome,
                'vencimento' => $t->vencimento?->toDateString(),
                'valor' => (float) $t->valor,
                'falta' => round((float) $t->valorRestante, 2),
                'status' => $t->status->getLabel(),
                'documento' => $t->numero_documento,
            ])->values()->all(),
        ]);
    }
}
