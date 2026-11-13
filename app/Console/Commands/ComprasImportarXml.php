<?php

namespace App\Console\Commands;

use App\Exceptions\NfeDestinatarioDivergenteException;
use App\Exceptions\NfeXmlInvalidoException;
use App\Models\CompraPendencia;
use App\Models\Lancamento;
use App\Services\Compras\CompraImportacaoService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Importa um XML de NF-e local pela automação de compras — o mesmo caminho
 * da Distribuição DF-e (pendências, contas a pagar, baixa e entrada pelo
 * perfil do fornecedor). Útil para notas antigas e para testar o fluxo.
 */
class ComprasImportarXml extends Command
{
    protected $signature = 'compras:importar-xml {arquivo : Caminho do XML da NF-e (procNFe)}';

    protected $description = 'Importa um XML de NF-e local pela automação de compras';

    public function handle(CompraImportacaoService $importacao): int
    {
        $arquivo = (string) $this->argument('arquivo');

        if (! is_file($arquivo) || ! is_readable($arquivo)) {
            $this->error("Arquivo não encontrado ou sem permissão de leitura: {$arquivo}");

            return self::FAILURE;
        }

        try {
            $compra = $importacao->importar((string) file_get_contents($arquivo));
        } catch (NfeXmlInvalidoException|NfeDestinatarioDivergenteException|ValidationException $e) {
            $this->error('Nota recusada: '.($e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage()));

            return self::FAILURE;
        }

        $this->info("Compra #{$compra->id} — {$compra->situacaoImportacao()} ({$compra->prestador?->nome_exibicao})");

        $this->table(
            ['Parcela', 'Vencimento', 'Valor', 'Status'],
            $compra->lancamentos()->orderBy('parcela_numero')->get()->map(fn (Lancamento $titulo): array => [
                $titulo->parcela_label ?? '—',
                $titulo->vencimento?->format('d/m/Y'),
                number_format((float) $titulo->valor, 2, ',', '.'),
                $titulo->status->getLabel(),
            ])->all(),
        );

        $pendencias = $compra->pendencias()->emAberto()->get();
        if ($pendencias->isNotEmpty()) {
            $this->warn('Pendências:');
            $pendencias->each(fn (CompraPendencia $pendencia) => $this->line(' - '.$pendencia->tipo->getLabel()
                .($pendencia->item ? ': '.$pendencia->item->ci_descricao_fornecedor : '')));
        }

        return self::SUCCESS;
    }
}
