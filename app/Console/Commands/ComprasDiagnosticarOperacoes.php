<?php

namespace App\Console\Commands;

use App\Enums\CompraStatusEnum;
use App\Enums\ContaPagarOperacaoEnum;
use App\Enums\EfeitoEstoqueEnum;
use App\Enums\MovimentacaoTipoEnum;
use App\Enums\OperacaoEntradaEnum;
use App\Exceptions\NfeXmlInvalidoException;
use App\Models\Compra;
use App\Services\Compras\CompraFiscalService;
use App\Services\Nfe\NfeXmlParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Relê o XML guardado de cada compra e compara a operação sugerida pelo
 * classificador com o que a compra realmente gerou (entrada de estoque,
 * títulos). Sem --gravar é só um relatório; com --gravar preenche apenas as
 * colunas fiscais e de sugestão — nunca estoque, financeiro, status ou valores.
 * Ver docs/planos/operacoes-fiscais-entrada-nfe.md (Fase 1, item 5).
 */
class ComprasDiagnosticarOperacoes extends Command
{
    protected $signature = 'compras:diagnosticar-operacoes
        {--gravar : Grava as colunas fiscais e a operação sugerida nas compras (não toca em estoque, títulos, status nem valores)}';

    protected $description = 'Classifica a operação fiscal das compras com XML e compara com os efeitos que elas geraram';

    public function handle(CompraFiscalService $fiscal, NfeXmlParser $parser): int
    {
        $gravar = (bool) $this->option('gravar');
        $linhas = [];
        $erros = [];
        $semXml = [];
        $porOperacao = [];
        $divergencias = 0;

        Compra::query()
            ->whereNotNull('compra_xml_path')
            ->with('prestador')
            ->withCount([
                'lancamentos',
                'movimentacoes as entradas_count' => fn ($q) => $q->where('mov_tipo', MovimentacaoTipoEnum::ENTRADA),
            ])
            ->orderBy('id')
            ->chunkById(100, function ($compras) use ($fiscal, $parser, $gravar, &$linhas, &$erros, &$semXml, &$porOperacao, &$divergencias): void {
                foreach ($compras as $compra) {
                    $xml = Storage::disk('local')->get($compra->compra_xml_path);
                    if ($xml === null) {
                        $semXml[] = $compra->id;

                        continue;
                    }

                    try {
                        $nfe = $parser->parse($xml);
                    } catch (NfeXmlInvalidoException $e) {
                        $erros[] = [$compra->id, $e->getMessage()];

                        continue;
                    }

                    $classificacao = $fiscal->classificar($nfe, $compra->compra_prestador_id, $compra->id);
                    $divergencia = $this->divergencia($compra, $classificacao->operacao, $classificacao->mista);

                    if ($gravar) {
                        $fiscal->gravar($compra, $nfe, $classificacao);
                    }

                    $valor = $classificacao->valorCabecalho();
                    $porOperacao[$valor] = ($porOperacao[$valor] ?? 0) + 1;
                    if ($divergencia !== null) {
                        $divergencias++;
                    }

                    $linhas[] = [
                        $compra->id,
                        $compra->compra_numero ?? '—',
                        mb_substr((string) $compra->prestador?->nome_exibicao, 0, 24),
                        $compra->compra_status->label(),
                        OperacaoEntradaEnum::rotulo($valor),
                        $classificacao->confianca->label(),
                        $compra->entradas_count > 0 ? "sim ({$compra->entradas_count})" : 'não',
                        $compra->lancamentos_count,
                        $divergencia === null ? '' : "<fg=red>{$divergencia}</>",
                    ];
                }
            });

        $this->table(
            ['ID', 'NF', 'Fornecedor', 'Status', 'Operação sugerida', 'Confiança', 'Entrada estoque', 'Títulos', 'Divergência'],
            $linhas,
        );

        foreach ($erros as [$id, $mensagem]) {
            $this->warn("Compra #{$id}: {$mensagem}");
        }

        if ($semXml !== []) {
            $this->warn(count($semXml).' compra(s) com XML ausente no disco (ex.: #'.implode(', #', array_slice($semXml, 0, 5)).').');
        }

        $this->newLine();
        $this->info(count($linhas).' compra(s) analisada(s), '.$divergencias.' com divergência, '.count($erros).' com XML inválido, '.count($semXml).' sem XML no disco.');
        foreach ($porOperacao as $valor => $total) {
            $this->line('  '.OperacaoEntradaEnum::rotulo((string) $valor).": {$total}");
        }
        $this->line($gravar
            ? 'Colunas fiscais e de sugestão gravadas (estoque, títulos, status e valores intactos).'
            : 'Nada foi gravado (use --gravar para preencher as colunas fiscais e de sugestão).');

        return self::SUCCESS;
    }

    /**
     * Compara o que a operação sugerida prevê com o que a compra confirmada
     * efetivamente gerou. Rascunho não gerou nada, então não diverge.
     */
    private function divergencia(Compra $compra, ?OperacaoEntradaEnum $operacao, bool $mista): ?string
    {
        if ($compra->compra_status !== CompraStatusEnum::CONFIRMADA) {
            return null;
        }

        if ($mista || $operacao === null) {
            return 'Mista: revisar item a item';
        }

        $efeitos = $operacao->efeitos();
        $problemas = [];

        if ($compra->entradas_count > 0 && in_array($efeitos->estoque, [EfeitoEstoqueEnum::NENHUM, EfeitoEstoqueEnum::SAIDA], true)) {
            $problemas[] = 'deu entrada no estoque, mas a operação prevê '.mb_strtolower($efeitos->estoque->label());
        }

        if ($compra->lancamentos_count > 0 && $efeitos->contaPagar === ContaPagarOperacaoEnum::NAO) {
            $problemas[] = 'gerou título, mas a operação não gera conta a pagar';
        }

        if ($compra->lancamentos_count === 0 && $efeitos->contaPagar === ContaPagarOperacaoEnum::SIM) {
            $problemas[] = 'sem título a pagar';
        }

        if ($operacao->efeitos()->bloqueiaConfirmacao) {
            $problemas[] = 'operação não processável foi confirmada';
        }

        return $problemas === [] ? null : implode('; ', $problemas);
    }
}
