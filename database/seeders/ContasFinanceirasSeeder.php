<?php

namespace Database\Seeders;

use App\Enums\Financeiro\TipoContaFinanceira;
use App\Models\ContaFinanceira;
use Illuminate\Database\Seeder;

/**
 * Contas iniciais: "Caixa PDV" (gaveta das sessões de caixa) e "Cofre", com
 * saldo 0. Contas bancárias o usuário cadastra. Idempotente.
 *
 * php artisan db:seed --class=ContasFinanceirasSeeder
 */
class ContasFinanceirasSeeder extends Seeder
{
    public function run(): void
    {
        if (! ContaFinanceira::withTrashed()->where('tipo', TipoContaFinanceira::CaixaPdv)->exists()) {
            ContaFinanceira::create([
                'nome' => 'Caixa PDV',
                'tipo' => TipoContaFinanceira::CaixaPdv,
                'saldo_inicial' => 0,
                'data_saldo_inicial' => now()->toDateString(),
                'ordem' => 1,
            ]);
        }

        ContaFinanceira::withTrashed()->firstOrCreate(
            ['nome' => 'Cofre'],
            [
                'tipo' => TipoContaFinanceira::CaixaInterno,
                'saldo_inicial' => 0,
                'data_saldo_inicial' => now()->toDateString(),
                'ordem' => 2,
            ],
        );
    }
}
