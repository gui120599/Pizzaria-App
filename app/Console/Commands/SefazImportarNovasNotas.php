<?php

namespace App\Console\Commands;

use App\Exceptions\SefazAutenticacaoException;
use App\Exceptions\SefazIndisponivelException;
use App\Models\Empresa;
use App\Services\Sefaz\SefazDistribuicaoService;
use Illuminate\Console\Command;

/**
 * Consulta novas NF-e emitidas contra o CNPJ da empresa (Distribuição DFe /
 * NSU) e importa automaticamente como rascunho de compra. Pensado pra rodar
 * de hora em hora via schedule — ver App\Console\Kernel.
 */
class SefazImportarNovasNotas extends Command
{
    protected $signature = 'sefaz:importar-novas-notas';

    protected $description = 'Consulta novas NF-e na SEFAZ (Distribuição DFe) e importa automaticamente como rascunho de compra';

    public function handle(): int
    {
        $empresa = Empresa::first();

        if (! $empresa?->empresa_sefaz_auto_importacao_ativa) {
            $this->line('Auto-importação desativada — nada a fazer.');

            return self::SUCCESS;
        }

        if (! $empresa->certificadoConfigurado()) {
            $this->error('Nenhum certificado digital configurado (Configurações → Certificado SEFAZ).');

            return self::FAILURE;
        }

        if ($empresa->certificadoVencido()) {
            $this->error('O certificado digital está vencido.');

            return self::FAILURE;
        }

        try {
            $resultado = app(SefazDistribuicaoService::class)->executarPolling();
        } catch (SefazAutenticacaoException|SefazIndisponivelException $e) {
            $this->error("Falha ao consultar a SEFAZ: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->table(
            ['Importadas', 'Já existentes/eventos', 'Pendentes', 'Erros'],
            [[$resultado->importadas, $resultado->puladas, $resultado->pendentes, $resultado->erros]],
        );

        return self::SUCCESS;
    }
}
