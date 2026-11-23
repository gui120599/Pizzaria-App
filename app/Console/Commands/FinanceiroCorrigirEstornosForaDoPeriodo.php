<?php

namespace App\Console\Commands;

use App\Models\ContaFinanceira;
use App\Services\Financeiro\MovimentacaoService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Corrige estornos gravados antes da regra de MovimentacaoService::dataDoEstorno():
 * a original era anterior ao saldo inicial da conta (nunca entrou no saldo),
 * mas o estorno foi datado do dia em que foi feito e passou a mexer no saldo.
 * O estorno recebe a data da original e o saldo das contas é recalculado.
 * Nenhuma movimentação é criada nem apagada.
 */
class FinanceiroCorrigirEstornosForaDoPeriodo extends Command
{
    protected $signature = 'financeiro:corrigir-estornos-fora-do-periodo
        {--aplicar : Grava a correção (sem esta opção só mostra o que mudaria)}';

    protected $description = 'Redata estornos de movimentações anteriores ao saldo inicial da conta e recalcula os saldos';

    public function handle(MovimentacaoService $movimentacoes): int
    {
        $estornos = $this->estornosAfetados();

        if ($estornos->isEmpty()) {
            $this->info('Nenhum estorno de movimentação anterior ao saldo inicial está mexendo no saldo.');

            return self::SUCCESS;
        }

        $this->table(
            ['Estorno', 'Original', 'Conta', 'Tipo', 'Valor', 'Data do estorno', 'Data da original', 'Efeito no saldo hoje'],
            $estornos->map(fn (object $estorno): array => [
                $estorno->id,
                $estorno->estorno_de_id,
                $estorno->conta_nome,
                $estorno->tipo,
                self::moeda((float) $estorno->valor),
                $estorno->data_movimento,
                $estorno->data_original,
                self::moeda(($estorno->tipo === 'entrada' ? 1 : -1) * (float) $estorno->valor),
            ])->all(),
        );

        $contas = ContaFinanceira::withTrashed()->whereKey($estornos->pluck('conta_financeira_id')->unique())->get();

        if (! $this->option('aplicar')) {
            $this->table(['Conta', 'Saldo atual', 'Saldo corrigido', 'Diferença'], $contas->map(function (ContaFinanceira $conta) use ($estornos): array {
                $efeito = $estornos->where('conta_financeira_id', $conta->id)
                    ->sum(fn (object $estorno): float => ($estorno->tipo === 'entrada' ? 1 : -1) * (float) $estorno->valor);

                return [$conta->nome, self::moeda((float) $conta->saldo_atual), self::moeda((float) $conta->saldo_atual - $efeito), self::moeda(-$efeito)];
            })->all());
            $this->comment('Nada foi gravado. Rode com --aplicar para corrigir.');

            return self::SUCCESS;
        }

        $saldos = DB::transaction(function () use ($estornos, $contas, $movimentacoes): array {
            foreach ($estornos as $estorno) {
                // Movimentação é append-only no model; a correção da data é feita direto e registrada no log.
                DB::table('movimentacoes_financeiras')->where('id', $estorno->id)->update(['data_movimento' => $estorno->data_original]);
            }

            return $contas->mapWithKeys(fn (ContaFinanceira $conta): array => [$conta->nome => $movimentacoes->recalcularSaldo($conta)])->all();
        });

        Log::warning('financeiro:corrigir-estornos-fora-do-periodo aplicado', [
            'estornos' => $estornos->map(fn (object $estorno): array => [
                'id' => $estorno->id,
                'estorno_de_id' => $estorno->estorno_de_id,
                'data_anterior' => $estorno->data_movimento,
                'data_nova' => $estorno->data_original,
            ])->all(),
            'saldos' => $saldos,
        ]);

        $this->table(['Conta', 'Saldo anterior', 'Saldo corrigido'], collect($saldos)->map(fn (array $saldo, string $nome): array => [
            $nome, self::moeda($saldo['anterior']), self::moeda($saldo['atual']),
        ])->values()->all());
        $this->info("{$estornos->count()} estorno(s) corrigido(s).");

        return self::SUCCESS;
    }

    /** @return Collection<int, object> */
    private function estornosAfetados(): Collection
    {
        return DB::table('movimentacoes_financeiras as estorno')
            ->join('movimentacoes_financeiras as original', 'original.id', '=', 'estorno.estorno_de_id')
            ->join('contas_financeiras as conta', 'conta.id', '=', 'estorno.conta_financeira_id')
            ->whereColumn('original.data_movimento', '<', 'conta.data_saldo_inicial')
            ->whereColumn('estorno.data_movimento', '>=', 'conta.data_saldo_inicial')
            ->orderBy('estorno.id')
            ->get([
                'estorno.id',
                'estorno.estorno_de_id',
                'estorno.conta_financeira_id',
                'estorno.tipo',
                'estorno.valor',
                'estorno.data_movimento',
                'original.data_movimento as data_original',
                'conta.nome as conta_nome',
            ]);
    }

    private static function moeda(float $valor): string
    {
        return number_format($valor, 2, ',', '.');
    }
}
