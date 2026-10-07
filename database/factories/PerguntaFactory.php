<?php

namespace Database\Factories;

use App\Models\Categoria;
use App\Models\Pergunta;
use App\Models\PerguntaOpcao;
use App\Models\Produto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pergunta>
 */
class PerguntaFactory extends Factory
{
    /**
     * Opcional de escolha única; a dona (produto ou categoria) vem de
     * doProduto()/daCategoria().
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pergunta_texto' => fake()->randomElement(['Escolha a borda', 'Ponto da carne', 'Molho']),
            'pergunta_minimo' => 0,
            'pergunta_maximo' => 1,
            'pergunta_ordem' => 0,
            'pergunta_ativa' => true,
        ];
    }

    public function doProduto(Produto $produto): static
    {
        return $this->state(['pergunta_produto_id' => $produto->id, 'pergunta_categoria_id' => null]);
    }

    public function daCategoria(Categoria $categoria): static
    {
        return $this->state(['pergunta_categoria_id' => $categoria->id, 'pergunta_produto_id' => null]);
    }

    public function obrigatoria(): static
    {
        return $this->state(['pergunta_minimo' => 1]);
    }

    /** @param  array<string, float>  $opcoes  nome => valor */
    public function comOpcoes(array $opcoes): static
    {
        return $this->afterCreating(function (Pergunta $pergunta) use ($opcoes): void {
            $ordem = 0;

            foreach ($opcoes as $nome => $valor) {
                PerguntaOpcao::factory()->for($pergunta)->create([
                    'pergunta_opcao_nome' => $nome,
                    'pergunta_opcao_valor' => $valor,
                    'pergunta_opcao_ordem' => $ordem++,
                ]);
            }
        });
    }
}
