<?php

namespace App\Console\Commands;

use App\Exceptions\SefazAutenticacaoException;
use App\Exceptions\SefazDocumentoAindaNaoDisponivelException;
use App\Exceptions\SefazIndisponivelException;
use App\Models\Empresa;
use App\Services\Sefaz\SefazDistribuicaoService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Busca uma NF-e específica na SEFAZ (Distribuição DFe) pela chave de
 * acesso e importa como rascunho de compra. Útil pra operação via CLI,
 * além do botão "Buscar por chave" no Filament (ver BuscarNfePorChaveAction).
 */
class SefazBuscarNfePorChave extends Command
{
    protected $signature = 'sefaz:buscar-chave {chave : Chave de acesso de 44 dígitos}';

    protected $description = 'Busca uma NF-e na SEFAZ pela chave de acesso e importa como rascunho de compra';

    public function handle(): int
    {
        $chave = preg_replace('/\D/', '', (string) $this->argument('chave')) ?? '';
        if (strlen($chave) !== 44) {
            $this->error('A chave de acesso precisa ter 44 dígitos.');

            return self::FAILURE;
        }

        $empresa = Empresa::first();
        if (! $empresa?->certificadoConfigurado()) {
            $this->error('Nenhum certificado digital configurado (Configurações → Certificado SEFAZ).');

            return self::FAILURE;
        }
        if ($empresa->certificadoVencido()) {
            $this->error('O certificado digital está vencido.');

            return self::FAILURE;
        }

        try {
            $compra = app(SefazDistribuicaoService::class)->buscarPorChave($chave);
        } catch (SefazDocumentoAindaNaoDisponivelException $e) {
            $this->warn($e->getMessage());

            return self::FAILURE;
        } catch (SefazAutenticacaoException|SefazIndisponivelException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (ValidationException $e) {
            $this->error(collect($e->errors())->flatten()->first());

            return self::FAILURE;
        }

        $this->info("Compra #{$compra->id} criada (rascunho) a partir da chave {$chave}.");

        return self::SUCCESS;
    }
}
