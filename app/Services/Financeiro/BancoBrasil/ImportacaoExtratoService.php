<?php

namespace App\Services\Financeiro\BancoBrasil;

use App\Enums\Financeiro\StatusConciliacaoExtrato;
use App\Exceptions\FinanceiroException;
use App\Models\ContaFinanceira;
use App\Models\ExtratoBancarioItem;
use App\Services\Financeiro\ConciliacaoBoletoService;
use Illuminate\Support\Carbon;

/**
 * Importa o extrato do Banco do Brasil de uma conta financeira (regra 18:
 * idempotente pelo hash de cada linha; sempre D-3 até hoje por padrão) e
 * tenta conciliar os débitos ainda pendentes.
 */
class ImportacaoExtratoService
{
    public const INTEGRACAO = 'banco_brasil';

    public function __construct(
        private BancoBrasilExtratoClient $client,
        private ConciliacaoBoletoService $conciliacao,
    ) {}

    /**
     * @return array{novas: int, existentes: int, conciliadas: int}
     *
     * @throws FinanceiroException
     */
    public function importar(ContaFinanceira $conta, ?Carbon $de = null, ?Carbon $ate = null): array
    {
        $ate = ($ate ?? today())->copy()->startOfDay();
        $de = ($de ?? $ate->copy()->subDays((int) config('bancos.banco_brasil.dias_retroativos', 3)))->copy()->startOfDay();

        $agencia = (string) ($conta->agencia ?: config('bancos.banco_brasil.agencia'));
        $numeroConta = (string) ($conta->conta ?: config('bancos.banco_brasil.conta'));
        if ($agencia === '' || $numeroConta === '') {
            throw new FinanceiroException("A conta {$conta->nome} não tem agência e conta cadastradas.");
        }

        $lancamentos = $this->client->extrato(self::soDigitos($agencia), self::soDigitos($numeroConta), $de, $ate);

        $novas = 0;
        $sequencias = [];
        foreach ($lancamentos as $lancamento) {
            $chave = implode('|', [
                $lancamento->dataLancamento->toDateString(),
                number_format($lancamento->valor, 2, '.', ''),
                $lancamento->natureza,
                $lancamento->documento ?? '',
                $lancamento->historico,
            ]);
            $sequencias[$chave] = ($sequencias[$chave] ?? 0) + 1;

            $item = ExtratoBancarioItem::query()->firstOrCreate(
                ['hash_unico' => hash('sha256', $conta->id.'|'.$chave.'|'.$sequencias[$chave])],
                [
                    'conta_financeira_id' => $conta->id,
                    'data_lancamento' => $lancamento->dataLancamento->toDateString(),
                    'data_movimento' => $lancamento->dataMovimento?->toDateString(),
                    'valor' => $lancamento->valor,
                    'natureza' => $lancamento->natureza,
                    'historico' => $lancamento->historico,
                    'documento' => $lancamento->documento,
                    'info_complementar' => $lancamento->infoComplementar,
                    'status_conciliacao' => StatusConciliacaoExtrato::Pendente,
                    'payload' => $lancamento->payload,
                ],
            );

            if ($item->wasRecentlyCreated) {
                $novas++;
            }
        }

        $conta->forceFill(['ultimo_extrato_em' => $ate->toDateString()])->save();

        $conciliadas = 0;
        ExtratoBancarioItem::query()
            ->where('conta_financeira_id', $conta->id)
            ->debitos()
            ->where('status_conciliacao', StatusConciliacaoExtrato::Pendente)
            ->orderBy('data_lancamento')
            ->orderBy('id')
            ->each(function (ExtratoBancarioItem $item) use (&$conciliadas): void {
                if ($this->conciliacao->conciliar($item) === StatusConciliacaoExtrato::Conciliado) {
                    $conciliadas++;
                }
            });

        return ['novas' => $novas, 'existentes' => $lancamentos->count() - $novas, 'conciliadas' => $conciliadas];
    }

    private static function soDigitos(string $valor): string
    {
        return preg_replace('/\D/', '', $valor) ?? '';
    }
}
