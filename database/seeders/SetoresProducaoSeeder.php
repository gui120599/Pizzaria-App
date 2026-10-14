<?php

namespace Database\Seeders;

use App\Enums\Cozinha\TipoSetorProducao;
use App\Models\Categoria;
use App\Models\RotaProducao;
use App\Models\SetorProducao;
use Illuminate\Database\Seeder;

/**
 * Setores padrão da cozinha (spec §8.5) e as rotas das categorias.
 * Idempotente e conservador: não renomeia nem apaga nada.
 *
 * - Se já existem setores de produção (ex.: copiados das linhas de produção
 *   pela migration 2026_10_14_100003), só garante a Expedição.
 * - Rota só é criada para categoria que ainda não tem rota própria.
 * - Bebida de lata/garrafa não precisa de nada: sem rota (e sem setor
 *   padrão), não gera item de produção.
 */
class SetoresProducaoSeeder extends Seeder
{
    /** @var array<string, array<string, mixed>> */
    private const SETORES = [
        'pizzaria' => ['nome' => 'Pizzaria', 'cor' => '#f97316', 'icone' => 'heroicon-o-fire', 'ordem' => 1, 'tempo_alerta_min' => 10, 'tempo_alvo_min' => 15, 'explode_unidades' => true],
        'cozinha' => ['nome' => 'Cozinha', 'cor' => '#eab308', 'icone' => 'heroicon-o-squares-2x2', 'ordem' => 2, 'tempo_alerta_min' => 10, 'tempo_alvo_min' => 15, 'explode_unidades' => true],
        'bar' => ['nome' => 'Bar', 'cor' => '#0ea5e9', 'icone' => 'heroicon-o-beaker', 'ordem' => 3, 'tempo_alerta_min' => 3, 'tempo_alvo_min' => 5, 'explode_unidades' => false, 'permite_pular_inicio' => true],
    ];

    /**
     * Categoria (nome exato) → setor. As filhas herdam a rota da mãe.
     *
     * @var array<string, list<string>>
     */
    private const ROTAS = [
        'pizzaria' => ['Pizzas', 'Pizza Doce', 'Pizza Combo'],
        'cozinha' => ['Porções', 'Pastel', 'Sanduíche', 'Sanduiche Gourmet', 'Panelinha', 'Tábua de Frios'],
        'bar' => ['Bebidas preparadas', 'Drink', 'Suco de Fruta', 'Suco Polpa', 'Cremes'],
    ];

    public function run(): void
    {
        $semSetores = ! SetorProducao::withTrashed()->where('tipo', TipoSetorProducao::Producao)->exists();

        if ($semSetores) {
            foreach (self::SETORES as $slug => $dados) {
                SetorProducao::firstOrCreate(['slug' => $slug], $dados + ['tipo' => TipoSetorProducao::Producao]);
            }
        } else {
            $this->command?->warn('Já existem setores de produção (ex.: copiados das linhas de produção): só a Expedição foi conferida. Ajuste os setores no admin.');
        }

        if (! SetorProducao::withTrashed()->where('tipo', TipoSetorProducao::Expedicao)->exists()) {
            SetorProducao::firstOrCreate(['slug' => 'expedicao'], [
                'nome' => 'Expedição',
                'tipo' => TipoSetorProducao::Expedicao,
                'cor' => '#22c55e',
                'icone' => 'heroicon-o-arrow-right-start-on-rectangle',
                'ordem' => 9,
                'exige_operador' => false,
            ]);
        }

        if ($semSetores) {
            $this->criarRotas();
        }
    }

    private function criarRotas(): void
    {
        foreach (self::ROTAS as $slug => $categorias) {
            $setor = SetorProducao::where('slug', $slug)->first();

            if ($setor === null) {
                continue;
            }

            foreach (Categoria::whereIn('categoria_nome', $categorias)->get() as $categoria) {
                if ($categoria->rotasProducao()->exists()) {
                    continue;
                }

                RotaProducao::create(['categoria_id' => $categoria->id, 'setor_producao_id' => $setor->id, 'etapa' => 1]);
                $this->command?->info("Rota: {$categoria->categoria_nome} → {$setor->nome}");
            }
        }
    }
}
