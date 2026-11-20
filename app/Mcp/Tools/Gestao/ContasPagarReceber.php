<?php

namespace App\Mcp\Tools\Gestao;

use App\Enums\StatusLancamento;
use App\Enums\TipoLancamento;
use App\Models\Lancamento;
use App\Models\User;
use App\Services\RelatorioContasPagarReceberService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('contas_a_pagar_receber')]
#[Description('Contas a pagar e a receber em aberto: totais em aberto e vencidos (como o Relatório de Contas a Pagar/Receber), saldo projetado por faixa de vencimento e a lista do que vence até N dias (inclui vencidos), com favorecido/cliente e valor restante.')]
#[IsReadOnly]
class ContasPagarReceber extends FerramentaGestao
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'dias' => $schema->integer()->min(0)->max(90)->description('Até quantos dias à frente listar (padrão 7). Vencidos sempre entram.'),
            'tipo' => $schema->string()->enum(['pagar', 'receber', 'ambos'])->description('Padrão: ambos.'),
        ];
    }

    protected function permitido(User $usuario): bool
    {
        return Gate::forUser($usuario)->allows('view:relatorio_contas_pagar_receber');
    }

    protected function executar(Request $request): Response
    {
        $dados = $request->validate([
            'dias' => ['nullable', 'integer', 'min:0', 'max:90'],
            'tipo' => ['nullable', 'in:pagar,receber,ambos'],
        ]);
        $dias = (int) ($dados['dias'] ?? 7);
        $tipos = match ($dados['tipo'] ?? 'ambos') {
            'pagar' => [TipoLancamento::Pagar],
            'receber' => [TipoLancamento::Receber],
            default => [TipoLancamento::Pagar, TipoLancamento::Receber],
        };
        $relatorio = new RelatorioContasPagarReceberService;

        $titulos = Lancamento::query()
            ->with(['favorecido', 'cliente', 'pagamentos'])
            ->whereIn('tipo', $tipos)
            ->whereIn('status', [StatusLancamento::Pendente, StatusLancamento::Parcial])
            ->whereDate('vencimento', '<=', today()->addDays($dias))
            ->orderBy('vencimento')
            ->limit(60)
            ->get();

        return Response::json([
            'hoje' => today()->toDateString(),
            'totais' => collect($tipos)->mapWithKeys(fn (TipoLancamento $tipo): array => [
                $tipo->value => collect($relatorio->totaisAbertoEVencido($tipo))->map(fn (?float $v): ?float => $v === null ? null : round($v, 2))->all(),
            ])->all(),
            'saldo_projetado_por_faixa' => collect($relatorio->saldoProjetadoPorFaixa())->map(fn (float $v): float => round($v, 2))->all(),
            'vencendo_ate' => today()->addDays($dias)->toDateString(),
            'titulos' => $titulos->map(fn (Lancamento $titulo): array => [
                'lancamento_id' => $titulo->id,
                'tipo' => $titulo->tipo->value,
                'descricao' => $titulo->descricao,
                'quem' => $titulo->tipo === TipoLancamento::Pagar
                    ? $titulo->favorecido?->nome_exibicao
                    : $titulo->cliente?->cliente_nome,
                'vencimento' => $titulo->vencimento?->toDateString(),
                'vencido' => $titulo->vencimento?->isBefore(today()) ?? false,
                'valor_restante' => round((float) $titulo->valorRestante, 2),
                'forma_pagamento' => $titulo->forma_pagamento?->getLabel(),
            ])->all(),
            'lista_truncada' => $titulos->count() >= 60,
        ]);
    }
}
