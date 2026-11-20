<?php

namespace App\Mcp\Tools\Gestao;

use App\Models\User;
use App\Models\Venda;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('notas_fiscais_situacao')]
#[Description('NFC-e das vendas de um período por situação (autorizada, com falha, pendente de envio, em andamento, cancelada) e a lista das vendas com falha ou pendentes, para reenviar pelo painel.')]
#[IsReadOnly]
class NotasFiscaisSituacao extends FerramentaGestao
{
    private const SITUACOES = ['autorizada', 'falha', 'pendente', 'andamento', 'cancelada'];

    public function schema(JsonSchema $schema): array
    {
        return [
            'inicio' => $schema->string()->description('AAAA-MM-DD (padrão: hoje).'),
            'fim' => $schema->string()->description('AAAA-MM-DD (padrão: igual ao início).'),
        ];
    }

    protected function permitido(User $usuario): bool
    {
        return Gate::forUser($usuario)->allows('view_any:nota_fiscal');
    }

    protected function executar(Request $request): Response
    {
        [$inicio, $fim] = $this->periodo($request, 92);
        $doPeriodo = fn () => Venda::query()->whereBetween('venda_datahora_finalizada', [$inicio, $fim]);

        $problemas = $doPeriodo()
            ->where(fn ($q) => $q->situacaoNfe('falha')->orWhere(fn ($p) => $p->situacaoNfe('pendente')))
            ->with('nfEmissoes')
            ->orderBy('venda_datahora_finalizada')
            ->limit(40)
            ->get();

        return Response::json([
            'periodo' => self::rotuloPeriodo($inicio, $fim),
            'por_situacao' => collect(self::SITUACOES)->mapWithKeys(fn (string $situacao): array => [$situacao => $doPeriodo()->situacaoNfe($situacao)->count()])->all(),
            'vendas_com_problema' => $problemas->map(fn (Venda $venda): array => [
                'venda_id' => $venda->id,
                'finalizada_em' => $venda->venda_datahora_finalizada?->toIso8601String(),
                'total' => round((float) $venda->venda_valor_total, 2),
                'status_nfe' => $venda->venda_status_nfe ?: ($venda->nfEmissoes->last()?->status ?? 'sem envio'),
                'erro' => $venda->nfEmissoes->last()?->erro_envio,
            ])->all(),
        ]);
    }
}
