<?php

namespace App\Console\Commands;

use App\Models\Cliente;
use Illuminate\Console\Command;

/**
 * Normaliza (dígitos puros) celular/CPF/CNPJ/CEP dos clientes já cadastrados
 * antes desta feature existir — daqui pra frente isso é feito automaticamente
 * por ClienteObserver a cada save(). Sem esse backfill, registros antigos
 * salvos com máscara (ex.: pelo ClienteForm) não batem por igualdade exata
 * com o que ClienteResolverService/ClienteMergeService buscam. Não é
 * destrutivo: só reformata os mesmos dados já existentes. Rodar uma vez,
 * manualmente, depois do deploy desta feature.
 */
class NormalizarDocumentosClientes extends Command
{
    protected $signature = 'clientes:normalizar-documentos';

    protected $description = 'Normaliza celular/CPF/CNPJ/CEP dos clientes já cadastrados para dígitos puros';

    private const CAMPOS_SOMENTE_DIGITOS = [
        'cliente_celular',
        'cliente_cpf',
        'cliente_cnpj',
        'cliente_cep',
    ];

    public function handle(): int
    {
        $total = Cliente::withTrashed()->count();

        if ($total === 0) {
            $this->warn('Nenhum cliente encontrado.');

            return self::SUCCESS;
        }

        $normalizados = 0;

        $this->withProgressBar(Cliente::withTrashed()->cursor(), function (Cliente $cliente) use (&$normalizados) {
            $mudou = false;

            foreach (self::CAMPOS_SOMENTE_DIGITOS as $campo) {
                $valor = $cliente->{$campo};
                $digitos = $valor !== null ? preg_replace('/\D/', '', (string) $valor) : null;
                $digitos = filled($digitos) ? $digitos : null;

                if ($digitos !== $valor) {
                    $cliente->{$campo} = $digitos;
                    $mudou = true;
                }
            }

            if ($mudou) {
                $cliente->save();
                $normalizados++;
            }
        });

        $this->newLine();
        $this->info("✓ {$normalizados} de {$total} cliente(s) normalizados.");

        return self::SUCCESS;
    }
}
