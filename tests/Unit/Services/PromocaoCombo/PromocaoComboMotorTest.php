<?php

namespace Tests\Unit\Services\PromocaoCombo;

use App\Services\PromocaoCombo\LinhaCombo;
use App\Services\PromocaoCombo\PromocaoComboMotor;
use App\Services\PromocaoCombo\RegraCombo;
use PHPUnit\Framework\TestCase;

/**
 * "2 pizzas grandes por R$ 87,99": categoria 1 = Pizza Grande, 2 = Bebidas.
 * Valores em centavos.
 */
class PromocaoComboMotorTest extends TestCase
{
    private const GRANDE = 1;

    private const BEBIDA = 2;

    private function regra(array $extra = []): RegraCombo
    {
        return new RegraCombo(...$extra + [
            'promocaoId' => 1,
            'nome' => '2 Grandes por 87,99',
            'quantidade' => 2,
            'precoCentavos' => 8799,
            'categoriasIncluidas' => [self::GRANDE],
        ]);
    }

    /** @param  list<int>  $sabores  produtos (todos da categoria Grande) */
    private function pizza(string $chave, int $base, int $unidades = 1, array $sabores = [10], array $extra = []): LinhaCombo
    {
        return new LinhaCombo(...$extra + [
            'chave' => $chave,
            'ordem' => (int) $chave,
            'unidades' => $unidades,
            'baseUnitariaCentavos' => $base,
            'produtos' => array_map(fn (int $id): array => ['produto_id' => $id, 'categoria_id' => self::GRANDE], $sabores),
        ]);
    }

    public function test_uma_pizza_nao_fecha_combo_e_avisa_que_falta_uma(): void
    {
        $plano = (new PromocaoComboMotor)->planejar([$this->pizza('1', 5990)], [$this->regra()]);

        $this->assertSame([], $plano->aplicacoes);
        $this->assertCount(1, $plano->pendencias);
        $this->assertSame(1, $plano->pendencias[0]->faltam);
    }

    public function test_duas_pizzas_de_sabores_diferentes_rateiam_o_desconto_sem_perder_centavo(): void
    {
        $plano = (new PromocaoComboMotor)->planejar(
            [$this->pizza('1', 5990), $this->pizza('2', 5010, sabores: [11])],
            [$this->regra()],
        );

        $this->assertCount(1, $plano->aplicacoes);
        $this->assertSame(11000, $plano->aplicacoes[0]->valorCheioCentavos());
        $this->assertSame(2201, $plano->aplicacoes[0]->descontoCentavos());
        $this->assertSame(['1' => 1199, '2' => 1002], $plano->descontoPorLinha());
        $this->assertSame([], $plano->pendencias);
    }

    public function test_duas_unidades_na_mesma_linha_fecham_o_combo(): void
    {
        $plano = (new PromocaoComboMotor)->planejar([$this->pizza('1', 5990, unidades: 2)], [$this->regra()]);

        $this->assertSame(['1' => 3181], $plano->descontoPorLinha());
    }

    public function test_terceira_pizza_sai_cheia_pela_ordem_de_inclusao_e_vira_pendencia(): void
    {
        $plano = (new PromocaoComboMotor)->planejar(
            [$this->pizza('3', 7000), $this->pizza('1', 5000), $this->pizza('2', 5000)],
            [$this->regra()],
        );

        $this->assertSame(['1' => 601, '2' => 600], $plano->descontoPorLinha());
        $this->assertSame(1, $plano->pendencias[0]->faltam);
    }

    public function test_quatro_pizzas_fecham_dois_combos(): void
    {
        $plano = (new PromocaoComboMotor)->planejar([$this->pizza('1', 5000, unidades: 4)], [$this->regra()]);

        $this->assertCount(2, $plano->aplicacoes);
        $this->assertNotSame($plano->aplicacoes[0]->chave, $plano->aplicacoes[1]->chave);
        $this->assertSame(['1' => 2402], $plano->descontoPorLinha());
        $this->assertSame([], $plano->pendencias);
    }

    public function test_meia_a_meia_conta_so_quando_a_promocao_aceita_e_todos_os_sabores_participam(): void
    {
        $meia = $this->pizza('1', 5500, sabores: [10, 11]);
        $outra = $this->pizza('2', 5500);
        $meiaComSaborDeFora = new LinhaCombo('1', 1, 1, 5500, [
            ['produto_id' => 10, 'categoria_id' => self::GRANDE],
            ['produto_id' => 99, 'categoria_id' => self::BEBIDA],
        ]);
        $motor = new PromocaoComboMotor;

        $this->assertCount(1, $motor->planejar([$meia, $outra], [$this->regra()])->aplicacoes);
        $this->assertCount(0, $motor->planejar([$meia, $outra], [$this->regra(['aplicaFracionado' => false])])->aplicacoes);
        $this->assertCount(0, $motor->planejar([$meiaComSaborDeFora, $outra], [$this->regra()])->aplicacoes);
    }

    public function test_relampago_e_brinde_entram_conforme_os_parametros_da_promocao(): void
    {
        $relampago = $this->pizza('1', 3990, extra: ['relampago' => true]);
        $comBrinde = $this->pizza('2', 5990, extra: ['promoAdicional' => true]);
        $outra = $this->pizza('3', 5990);
        $motor = new PromocaoComboMotor;

        $this->assertSame(['1' => 472, '2' => 709], $motor->planejar([$relampago, $comBrinde], [$this->regra()])->descontoPorLinha());
        $this->assertSame(['2' => 1591, '3' => 1590], $motor->planejar([$relampago, $comBrinde, $outra], [$this->regra(['cumulaRelampago' => false])])->descontoPorLinha());
        $this->assertSame(['1' => 472, '3' => 709], $motor->planejar([$relampago, $comBrinde, $outra], [$this->regra(['cumulaPromoAdicional' => false])])->descontoPorLinha());
    }

    public function test_combo_que_encareceria_nao_e_aplicado(): void
    {
        $plano = (new PromocaoComboMotor)->planejar([$this->pizza('1', 4000), $this->pizza('2', 4000)], [$this->regra()]);

        $this->assertSame([], $plano->aplicacoes);
    }

    public function test_produto_excluido_e_produto_de_outra_categoria_ficam_fora(): void
    {
        $regra = $this->regra(['produtosExcluidos' => [10], 'produtosIncluidos' => [50]]);
        $refri = new LinhaCombo('3', 3, 1, 6000, [['produto_id' => 50, 'categoria_id' => self::BEBIDA]]);
        $suco = new LinhaCombo('4', 4, 1, 6000, [['produto_id' => 51, 'categoria_id' => self::BEBIDA]]);

        $plano = (new PromocaoComboMotor)->planejar([$this->pizza('1', 6000), $this->pizza('2', 6000, sabores: [11]), $refri, $suco], [$regra]);

        $this->assertSame(['2' => 1601, '3' => 1600], $plano->descontoPorLinha());
    }

    public function test_promocao_de_maior_prioridade_fica_com_a_unidade_disputada(): void
    {
        $baixa = $this->regra(['promocaoId' => 1]);
        $alta = $this->regra(['promocaoId' => 2, 'nome' => 'Grandes 2 por 80', 'precoCentavos' => 8000, 'prioridade' => 5]);

        $plano = (new PromocaoComboMotor)->planejar([$this->pizza('1', 5000, unidades: 3)], [$baixa, $alta]);

        $this->assertCount(1, $plano->aplicacoes);
        $this->assertSame(2, $plano->aplicacoes[0]->promocaoId);
        $this->assertSame(1, $plano->pendencias[0]->faltam);
    }

    public function test_limite_por_pedido_desconta_as_aplicacoes_que_ja_existem(): void
    {
        $regra = $this->regra(['limitePorPedido' => 2]);
        $motor = new PromocaoComboMotor;

        $this->assertCount(2, $motor->planejar([$this->pizza('1', 5000, unidades: 6)], [$regra])->aplicacoes);
        $this->assertCount(1, $motor->planejar([$this->pizza('1', 5000, unidades: 6)], [$regra], jaAplicadas: [1 => 1])->aplicacoes);
    }

    public function test_mesma_entrada_gera_o_mesmo_plano(): void
    {
        $linhas = [$this->pizza('2', 5990), $this->pizza('1', 6990), $this->pizza('3', 5490)];
        $motor = new PromocaoComboMotor;

        $this->assertEquals($motor->planejar($linhas, [$this->regra()]), $motor->planejar(array_reverse($linhas), [$this->regra()]));
    }
}
