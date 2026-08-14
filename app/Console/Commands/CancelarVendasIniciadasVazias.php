<?php

namespace App\Console\Commands;

use App\Enums\MotivoCancelamentoEnum;
use App\Models\Venda;
use App\Services\FinalizacaoVendaService;
use Illuminate\Console\Command;

/**
 * Rede de segurança complementar ao cancelamento via beforeunload/sendBeacon
 * do PDV (OperarVenda): cobre os casos em que o beacon falha (perda de rede,
 * navegador mobile fechado à força etc.), evitando o acúmulo de vendas
 * INICIADA vazias — inevitáveis com a criação antecipada da Venda ao abrir
 * a tela (ver App\Filament\Pages\OperarVenda::mount()).
 */
class CancelarVendasIniciadasVazias extends Command
{
    protected $signature = 'vendas:cancelar-iniciadas-vazias {--horas=4 : Idade mínima (em horas) para considerar a venda abandonada}';

    protected $description = 'Cancela vendas INICIADA sem itens/valor, abandonadas há mais de N horas';

    public function handle(FinalizacaoVendaService $service): int
    {
        $horas = (int) $this->option('horas');

        $vendas = Venda::where('venda_status', 'INICIADA')
            ->where('venda_valor_total', '<=', 0)
            ->where('venda_datahora_iniciada', '<', now()->subHours($horas))
            ->get();

        foreach ($vendas as $venda) {
            $service->cancelar($venda, MotivoCancelamentoEnum::OUTRO->value);
        }

        $this->info("{$vendas->count()} venda(s) INICIADA vazia(s) cancelada(s).");

        return self::SUCCESS;
    }
}
