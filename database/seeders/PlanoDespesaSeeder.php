<?php

namespace Database\Seeders;

use App\Enums\Comportamento;
use App\Enums\Periodicidade;
use App\Models\PlanoDespesa;
use Illuminate\Database\Seeder;

class PlanoDespesaSeeder extends Seeder
{
    public function run(): void
    {
        // [nome do grupo => ['grupo' => [comportamento, periodicidade representativos], 'contas' => [[nome, comportamento, periodicidade], ...]]]
        $estrutura = [
            'CMV / Insumos' => [
                'grupo' => [Comportamento::Variavel, Periodicidade::Eventual],
                'contas' => [
                    ['Insumos / Ingredientes', Comportamento::Variavel, Periodicidade::Eventual],
                    ['Embalagens', Comportamento::Variavel, Periodicidade::Eventual],
                    ['Bebidas para revenda', Comportamento::Variavel, Periodicidade::Eventual],
                ],
            ],
            'Pessoal' => [
                'grupo' => [Comportamento::Fixo, Periodicidade::Mensal],
                'contas' => [
                    ['Salários + encargos', Comportamento::Fixo, Periodicidade::Mensal],
                    ['Pró-labore', Comportamento::Fixo, Periodicidade::Mensal],
                ],
            ],
            'Ocupação e Utilidades' => [
                'grupo' => [Comportamento::Fixo, Periodicidade::Mensal],
                'contas' => [
                    ['Aluguel', Comportamento::Fixo, Periodicidade::Mensal],
                    ['Energia elétrica', Comportamento::Fixo, Periodicidade::Mensal],
                    ['Água', Comportamento::Fixo, Periodicidade::Mensal],
                    ['Gás', Comportamento::Variavel, Periodicidade::Mensal],
                    ['Internet / Telefone', Comportamento::Fixo, Periodicidade::Mensal],
                ],
            ],
            'Comercial / Vendas' => [
                'grupo' => [Comportamento::Variavel, Periodicidade::Mensal],
                'contas' => [
                    ['Comissão iFood', Comportamento::Variavel, Periodicidade::Mensal],
                    ['Taxas de cartão', Comportamento::Variavel, Periodicidade::Mensal],
                    ['Marketing / Tráfego pago', Comportamento::Variavel, Periodicidade::Mensal],
                    ['Combustível delivery', Comportamento::Variavel, Periodicidade::Eventual],
                ],
            ],
            'Administrativas' => [
                'grupo' => [Comportamento::Fixo, Periodicidade::Mensal],
                'contas' => [
                    ['Contador', Comportamento::Fixo, Periodicidade::Mensal],
                    ['Software / Sistemas', Comportamento::Fixo, Periodicidade::Mensal],
                    ['Manutenção / Equipamentos', Comportamento::Variavel, Periodicidade::Eventual],
                    ['Impostos / Simples Nacional', Comportamento::Variavel, Periodicidade::Mensal],
                ],
            ],
        ];

        foreach ($estrutura as $nomeGrupo => $dados) {
            [$comportamentoGrupo, $periodicidadeGrupo] = $dados['grupo'];

            $grupo = PlanoDespesa::firstOrCreate(
                ['nome' => $nomeGrupo, 'pai_id' => null],
                [
                    'comportamento' => $comportamentoGrupo,
                    'periodicidade' => $periodicidadeGrupo,
                ],
            );

            foreach ($dados['contas'] as [$nomeConta, $comportamento, $periodicidade]) {
                PlanoDespesa::firstOrCreate(
                    ['nome' => $nomeConta, 'pai_id' => $grupo->id],
                    [
                        'comportamento' => $comportamento,
                        'periodicidade' => $periodicidade,
                    ],
                );
            }
        }
    }
}
