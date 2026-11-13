<?php

namespace App\Console\Commands;

use App\Enums\CompraStatusEnum;
use App\Exceptions\NfeXmlInvalidoException;
use App\Models\Compra;
use App\Models\CompraPendencia;
use App\Models\Lancamento;
use App\Services\Compras\CompraImportacaoService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Passa pela automação de compras os rascunhos importados de XML antes dela
 * existir (CompraImportacaoService::reprocessarRascunho): pendências, contas
 * a pagar, baixa pela regra do fornecedor e entrada automática. Com
 * --simular tudo roda dentro de uma transação desfeita no fim.
 */
class ComprasReprocessarRascunhos extends Command
{
    protected $signature = 'compras:reprocessar-rascunhos
        {--compra=* : Ids das compras (padrão: todos os rascunhos de XML ainda não automatizados)}
        {--fornecedor= : Só os rascunhos deste fornecedor (id)}
        {--limite= : Quantidade máxima de compras}
        {--simular : Mostra o resultado sem gravar nada}';

    protected $description = 'Passa os rascunhos importados de XML antes da automação pelo fluxo automático (pendências, financeiro e entrada)';

    public function handle(CompraImportacaoService $importacao): int
    {
        $compras = $this->consulta()->get();

        if ($compras->isEmpty()) {
            $this->info('Nenhum rascunho para reprocessar.');

            return self::SUCCESS;
        }

        $simular = (bool) $this->option('simular');
        $this->line(($simular ? 'SIMULAÇÃO — nada será gravado. ' : '')."Rascunhos: {$compras->count()}");

        $linhas = [];
        $falhas = 0;

        foreach ($compras as $compra) {
            if ($simular) {
                DB::beginTransaction();
            }

            try {
                $linhas[] = $this->linha($importacao->reprocessarRascunho($compra));
            } catch (ValidationException|NfeXmlInvalidoException|Throwable $e) {
                $falhas++;
                $mensagem = $e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage();
                $linhas[] = [$compra->id, $compra->prestador?->nome_exibicao ?? '—', $compra->compra_numero ?? '—', 'ERRO', '—', '—', mb_strimwidth((string) $mensagem, 0, 80, '…')];
            } finally {
                if ($simular) {
                    DB::rollBack();
                }
            }
        }

        $this->table(['Compra', 'Fornecedor', 'NF', 'Situação', 'Títulos', 'Itens sem produto', 'Pendências'], $linhas);
        $this->info(($simular ? 'Simulação concluída' : 'Reprocessamento concluído').': '.(count($linhas) - $falhas)." ok, {$falhas} com erro.");

        return $falhas > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return Builder<Compra> */
    private function consulta(): Builder
    {
        $ids = array_filter(array_map('intval', (array) $this->option('compra')));

        return Compra::query()
            ->with('prestador')
            ->where('compra_status', CompraStatusEnum::RASCUNHO)
            ->where('compra_importacao_automatica', false)
            ->whereNotNull('compra_chave_nfe')
            ->whereNotNull('compra_xml_path')
            ->when($ids !== [], fn (Builder $query) => $query->whereKey($ids))
            ->when($this->option('fornecedor'), fn (Builder $query, $fornecedor) => $query->where('compra_prestador_id', (int) $fornecedor))
            ->when($this->option('limite'), fn (Builder $query, $limite) => $query->limit((int) $limite))
            ->orderBy('compra_data_emissao')
            ->orderBy('id');
    }

    /** @return list<int|string> */
    private function linha(Compra $compra): array
    {
        $titulos = $compra->lancamentos()->get();
        $pendencias = $compra->pendencias()->emAberto()->get()
            ->countBy(fn (CompraPendencia $pendencia): string => $pendencia->tipo->getLabel())
            ->map(fn (int $quantidade, string $tipo): string => $quantidade > 1 ? "{$tipo} ({$quantidade})" : $tipo)
            ->implode(', ');

        return [
            $compra->id,
            $compra->prestador?->nome_exibicao ?? '—',
            $compra->compra_numero ?? '—',
            $compra->compra_status === CompraStatusEnum::CONFIRMADA ? 'Confirmada (entrou no estoque)' : 'Rascunho',
            $titulos->isEmpty() ? '—' : $titulos->countBy(fn (Lancamento $titulo): string => $titulo->status->getLabel())
                ->map(fn (int $quantidade, string $status): string => "{$quantidade} {$status}")->implode(', '),
            $compra->itens()->whereNull('ci_produto_id')->count(),
            $pendencias ?: '—',
        ];
    }
}
