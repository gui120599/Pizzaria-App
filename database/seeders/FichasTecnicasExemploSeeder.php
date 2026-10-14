<?php

namespace Database\Seeders;

use App\Enums\Cozinha\EscopoIngrediente;
use App\Enums\ProdutoTipoEnum;
use App\Models\Categoria;
use App\Models\FichaTecnica;
use App\Models\FichaTecnicaItem;
use App\Models\FichaTecnicaPasso;
use App\Models\Produto;
use App\Models\SetorProducao;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Fichas de preparo de EXEMPLO (spec §8.5) — ajustar à receita da casa.
 *
 * Usa as pizzas que já existem na categoria "Pizza Grande" (Calabresa,
 * Marguerita/Margherita, Portuguesa); não cria produto de venda. Os insumos
 * são procurados pelo nome e só os que faltam são criados, numa categoria
 * própria. Produto que já tem ficha de custo mantém os ingredientes dele:
 * o seeder só marca o escopo (base) e cria cabeçalho e passos.
 */
class FichasTecnicasExemploSeeder extends Seeder
{
    private const CATEGORIA_INSUMOS = 'Insumos (exemplo de ficha)';

    /** Ingrediente: [insumo, quantidade, unidade, escopo, opcional_ref] */
    private const RECEITAS = [
        'Calabresa' => [
            ['Massa G', 1, 'UN', EscopoIngrediente::Base, null],
            ['Molho de tomate', 0.12, 'KG', EscopoIngrediente::Base, null],
            ['Mussarela', 0.25, 'KG', EscopoIngrediente::Base, null],
            ['Calabresa fatiada', 0.15, 'KG', EscopoIngrediente::Cobertura, 'calabresa'],
            ['Cebola', 0.06, 'KG', EscopoIngrediente::Cobertura, 'cebola'],
            ['Azeitona', 8, 'UN', EscopoIngrediente::Cobertura, 'azeitona'],
            ['Orégano', 0.001, 'KG', EscopoIngrediente::Finalizacao, 'oregano'],
        ],
        'Margu' => [
            ['Massa G', 1, 'UN', EscopoIngrediente::Base, null],
            ['Molho de tomate', 0.12, 'KG', EscopoIngrediente::Base, null],
            ['Mussarela', 0.25, 'KG', EscopoIngrediente::Base, null],
            ['Tomate', 0.1, 'KG', EscopoIngrediente::Cobertura, 'tomate'],
            ['Manjericão', 0.005, 'KG', EscopoIngrediente::Finalizacao, 'manjericao'],
        ],
        'Portuguesa' => [
            ['Massa G', 1, 'UN', EscopoIngrediente::Base, null],
            ['Molho de tomate', 0.12, 'KG', EscopoIngrediente::Base, null],
            ['Mussarela', 0.25, 'KG', EscopoIngrediente::Base, null],
            ['Presunto', 0.1, 'KG', EscopoIngrediente::Cobertura, 'presunto'],
            ['Ovo', 2, 'UN', EscopoIngrediente::Cobertura, 'ovo'],
            ['Cebola', 0.05, 'KG', EscopoIngrediente::Cobertura, 'cebola'],
            ['Azeitona', 8, 'UN', EscopoIngrediente::Cobertura, 'azeitona'],
        ],
    ];

    /** [título, descrição, segundos, temperatura, ponto crítico] */
    private const PASSOS = [
        ['Abrir a massa', 'Abrir em 35 cm, borda de 2 dedos.', null, null, false],
        ['Molho', 'Em espiral, deixando 2 cm de borda.', null, null, false],
        ['Mussarela', 'Espalhar uniforme até a borda do molho.', null, null, false],
        ['Cobertura', 'Distribuir a cobertura do sabor (no meio a meio, cada metade com o seu).', null, null, false],
        ['Forno', 'Forno a 300 °C.', 240, '300 °C', false],
        ['Girar a pizza', 'Girar 180° para assar por igual.', null, null, true],
        ['Terminar de assar', 'Mais 3 a 4 minutos.', 210, '300 °C', false],
        ['Cortar e conferir', 'Cortar em 8 e conferir a borda.', null, null, false],
    ];

    /** Massa G (rendimento 1 un, 450 g). */
    private const MASSA = [
        ['Farinha de trigo', 0.27, 'KG'],
        ['Água', 0.16, 'L'],
        ['Fermento', 0.005, 'KG'],
        ['Sal', 0.006, 'KG'],
        ['Óleo', 0.01, 'L'],
    ];

    private ?Categoria $categoriaInsumos = null;

    public function run(): void
    {
        $grande = Categoria::where('categoria_nome', 'Pizza Grande')->first();

        if ($grande === null) {
            $this->command?->warn('Categoria "Pizza Grande" não encontrada: nenhuma ficha de exemplo criada.');

            return;
        }

        DB::transaction(function () use ($grande): void {
            $massa = $this->massa();
            $setor = SetorProducao::where('slug', 'pizzaria')->first();

            foreach (self::RECEITAS as $nome => $receita) {
                $pizza = Produto::where('produto_categoria_id', $grande->id)
                    ->where('produto_descricao', 'like', $nome.'%')
                    ->orderBy('id')
                    ->first();

                if ($pizza === null) {
                    $this->command?->warn("Pizza Grande \"{$nome}…\" não encontrada: pulada.");

                    continue;
                }

                $this->ficha($pizza, $receita, $massa, $setor);
            }
        });
    }

    /**
     * @param  list<array{0: string, 1: float|int, 2: string, 3: EscopoIngrediente, 4: ?string}>  $receita
     */
    private function ficha(Produto $pizza, array $receita, Produto $massa, ?SetorProducao $setor): void
    {
        $ficha = FichaTecnica::withTrashed()->where('produto_id', $pizza->id)->first();

        if ($ficha === null) {
            $ficha = FichaTecnica::create([
                'produto_id' => $pizza->id,
                'tempo_preparo_min' => 12,
                'alergenos' => ['Glúten', 'Lactose'],
                'pontos_criticos' => 'Borda dourada e queijo sem queimar. Ficha de exemplo: ajuste à receita da casa.',
            ]);

            foreach (self::PASSOS as $ordem => [$titulo, $descricao, $segundos, $temperatura, $critico]) {
                FichaTecnicaPasso::create([
                    'ficha_tecnica_id' => $ficha->id,
                    'ordem' => $ordem + 1,
                    'titulo' => $titulo,
                    'descricao' => $descricao,
                    'setor_producao_id' => $setor?->id,
                    'tempo_segundos' => $segundos,
                    'temperatura_alvo' => $temperatura,
                    'ponto_critico' => $critico,
                ]);
            }

            $this->command?->info("Ficha de preparo: {$pizza->produto_descricao}");
        }

        $existentes = FichaTecnicaItem::with('insumo')->where('fti_produto_id', $pizza->id)->get();

        // Ficha de custo já cadastrada: só o escopo da base (massa, molho, mussarela).
        if ($existentes->isNotEmpty()) {
            foreach ($existentes as $linha) {
                if (preg_match('/massa|molho|mussarela|muçarela/i', (string) $linha->insumo?->produto_descricao) === 1) {
                    $linha->update(['fti_escopo' => EscopoIngrediente::Base]);
                }
            }

            return;
        }

        foreach ($receita as $ordem => [$nome, $quantidade, $unidade, $escopo, $ref]) {
            $insumo = $nome === 'Massa G' ? $massa : $this->insumo($nome, $unidade);

            FichaTecnicaItem::create([
                'fti_produto_id' => $pizza->id,
                'fti_insumo_id' => $insumo->id,
                'fti_quantidade' => $quantidade,
                'fti_unidade' => $unidade,
                'fti_escopo' => $escopo,
                'fti_opcional_ref' => $ref,
                'fti_ordem' => $ordem + 1,
            ]);
        }
    }

    /** Massa G como subficha (insumo produzido com ficha própria). */
    private function massa(): Produto
    {
        $massa = Produto::where('produto_descricao', 'Massa G')
            ->whereIn('produto_tipo', [ProdutoTipoEnum::INSUMO_PRODUZIDO->value, ProdutoTipoEnum::INSUMO->value])
            ->first()
            ?? Produto::create([
                'produto_descricao' => 'Massa G',
                'produto_categoria_id' => $this->categoriaInsumos()->id,
                'produto_tipo' => ProdutoTipoEnum::INSUMO_PRODUZIDO->value,
                'produto_unidade_estoque' => 'UN',
                'produto_ficha_rendimento' => 1,
            ]);

        if (! FichaTecnicaItem::where('fti_produto_id', $massa->id)->exists()) {
            foreach (self::MASSA as $ordem => [$nome, $quantidade, $unidade]) {
                FichaTecnicaItem::create([
                    'fti_produto_id' => $massa->id,
                    'fti_insumo_id' => $this->insumo($nome, $unidade)->id,
                    'fti_quantidade' => $quantidade,
                    'fti_unidade' => $unidade,
                    'fti_escopo' => EscopoIngrediente::Base,
                    'fti_ordem' => $ordem + 1,
                ]);
            }
        }

        return $massa;
    }

    private function insumo(string $nome, string $unidade): Produto
    {
        return Produto::whereIn('produto_tipo', [ProdutoTipoEnum::INSUMO->value, ProdutoTipoEnum::INSUMO_PRODUZIDO->value])
            ->where('produto_descricao', 'like', $nome.'%')
            ->orderBy('id')
            ->first()
            ?? Produto::create([
                'produto_descricao' => $nome,
                'produto_categoria_id' => $this->categoriaInsumos()->id,
                'produto_tipo' => ProdutoTipoEnum::INSUMO->value,
                'produto_unidade_estoque' => $unidade,
            ]);
    }

    private function categoriaInsumos(): Categoria
    {
        return $this->categoriaInsumos ??= Categoria::firstOrCreate(['categoria_nome' => self::CATEGORIA_INSUMOS]);
    }
}
