<?php

namespace Tests\Feature;

use App\Enums\Cozinha\EscopoIngrediente;
use App\Enums\ProdutoTipoEnum;
use App\Models\AdicionaisItemPedido;
use App\Models\Adicional;
use App\Models\FichaTecnica;
use App\Models\FichaTecnicaItem;
use App\Models\FichaTecnicaPasso;
use App\Models\ProducaoItem;
use App\Models\Produto;
use App\Models\SetorProducao;
use App\Models\User;
use App\Services\Cozinha\FichaTecnicaMontador;
use App\Support\Cozinha\IngredienteFicha;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CenarioCozinha;
use Tests\TestCase;

/** Fase 2: ficha técnica viva (§5.6). */
class CozinhaFichaTecnicaMontadorTest extends TestCase
{
    use CenarioCozinha, RefreshDatabase;

    private SetorProducao $pizzaria;

    private Produto $calabresa;

    private Produto $portuguesa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->gerente()->create(['name_first' => 'Gerente']));
        $this->pizzaria = SetorProducao::factory()->create(['nome' => 'Pizzaria']);
        $grande = $this->categoria('Pizza Grande');
        $this->rotaCategoria($grande, $this->pizzaria);
        $insumos = $this->categoria('Insumos');
        $insumo = fn (string $nome) => $this->produto($nome, $insumos, ['produto_tipo' => ProdutoTipoEnum::INSUMO->value]);

        $massa = $this->produto('Massa G 450g', $insumos, ['produto_tipo' => ProdutoTipoEnum::INSUMO_PRODUZIDO->value]);
        FichaTecnicaItem::factory()->create(['fti_produto_id' => $massa->id, 'fti_insumo_id' => $insumo('Farinha')->id]);
        $mussarela = $insumo('Mussarela');

        $this->calabresa = $this->produto('Calabresa', $grande);
        $this->portuguesa = $this->produto('Portuguesa', $grande);

        foreach ([$this->calabresa, $this->portuguesa] as $sabor) {
            FichaTecnica::factory()->doProduto($sabor)->create();
            $this->linha($sabor, $massa, 1, 'UN', EscopoIngrediente::Base, 1);
            $this->linha($sabor, $mussarela, 0.25, 'KG', EscopoIngrediente::Base, 2);
        }

        $this->linha($this->calabresa, $insumo('Calabresa fatiada'), 0.15, 'KG', EscopoIngrediente::Cobertura, 3);
        $this->linha($this->calabresa, $insumo('Cebola Roxa'), 0.06, 'KG', EscopoIngrediente::Cobertura, 4);
        $this->linha($this->portuguesa, $insumo('Presunto'), 0.1, 'KG', EscopoIngrediente::Cobertura, 3);
    }

    private function linha(Produto $produto, Produto $insumo, float $quantidade, string $unidade, EscopoIngrediente $escopo, int $ordem): void
    {
        FichaTecnicaItem::factory()->escopo($escopo)->create([
            'fti_produto_id' => $produto->id,
            'fti_insumo_id' => $insumo->id,
            'fti_quantidade' => $quantidade,
            'fti_unidade' => $unidade,
            'fti_ordem' => $ordem,
        ]);
    }

    public function test_meio_a_meio_base_uma_vez_cobertura_pela_fracao_e_sem_cebola_riscado(): void
    {
        $adicional = Adicional::create(['adicional_nome' => 'Bacon', 'adicional_valor' => 5, 'adicional_quantidade_porcao' => 0.04, 'adicional_unidade_porcao' => 'KG']);
        $pedido = $this->pedidoMesa();
        $item = $this->itemMeioAMeio($pedido, $this->calabresa, $this->portuguesa, ['item_pedido_observacao' => 'Sem cebola']);
        AdicionaisItemPedido::create(['aip_item_pedido_id' => $item->id, 'aip_adicional_id' => $adicional->id, 'aip_quantidade' => 1]);
        $this->abrir($pedido);

        $ficha = app(FichaTecnicaMontador::class)->paraItem(ProducaoItem::sole());
        $grupos = $ficha->ingredientesPorGrupo();

        $this->assertSame(['Base', '½ Calabresa', '½ Portuguesa'], array_keys($grupos));
        $this->assertSame(['Massa G 450g', 'Mussarela'], array_map(fn (IngredienteFicha $i) => $i->nome, $grupos['Base']));
        $this->assertSame('250 g', $grupos['Base'][1]->quantidadeFormatada);
        $this->assertSame(['75 g', '30 g'], array_map(fn (IngredienteFicha $i) => $i->quantidadeFormatada, $grupos['½ Calabresa']));
        $this->assertTrue($grupos['½ Calabresa'][1]->removido, 'SEM cebola risca a Cebola Roxa.');
        $this->assertSame($grupos['Base'][0]->subfichaProdutoId !== null, true, 'Massa tem ficha própria: vira subficha.');
        $this->assertSame('50 g', $grupos['½ Portuguesa'][0]->quantidadeFormatada);
        $this->assertSame('+ Bacon', $ficha->adicionais[0]->nome);
        $this->assertSame('40 g', $ficha->adicionais[0]->quantidadeFormatada);
        $this->assertSame([], $ficha->remocoesSemLinha);
    }

    public function test_passos_filtrados_pelo_setor_do_tablet(): void
    {
        $forno = SetorProducao::factory()->create(['nome' => 'Forno']);
        $ficha = $this->calabresa->fichaTecnica;
        FichaTecnicaPasso::factory()->create(['ficha_tecnica_id' => $ficha->id, 'ordem' => 1, 'titulo' => 'Lavar as mãos']);
        FichaTecnicaPasso::factory()->doSetor($this->pizzaria)->create(['ficha_tecnica_id' => $ficha->id, 'ordem' => 2, 'titulo' => 'Abrir a massa']);
        FichaTecnicaPasso::factory()->doSetor($forno)->cronometrado(240)->critico()->create(['ficha_tecnica_id' => $ficha->id, 'ordem' => 3, 'titulo' => 'Assar']);

        $montador = app(FichaTecnicaMontador::class);

        $this->assertSame(['Lavar as mãos', 'Abrir a massa'], array_map(fn ($p) => $p->titulo, $montador->paraProduto($this->calabresa, $this->pizzaria)->passos));
        $passosForno = $montador->paraProduto($this->calabresa, $forno)->passos;
        $this->assertSame(['Lavar as mãos', 'Assar'], array_map(fn ($p) => $p->titulo, $passosForno));
        $this->assertSame(240, $passosForno[1]->tempoSegundos);
        $this->assertTrue($passosForno[1]->pontoCritico);
    }

    public function test_produto_sem_ficha_nao_quebra_e_remocao_sem_linha_fica_destacada(): void
    {
        $pedido = $this->pedidoMesa();
        $this->item($pedido, $this->produto('Pizza nova', $this->calabresa->categoria), 1, ['item_pedido_observacao' => 'Sem orégano']);
        $this->abrir($pedido);

        $ficha = app(FichaTecnicaMontador::class)->paraItem(ProducaoItem::sole());

        $this->assertFalse($ficha->temFicha);
        $this->assertSame([], $ficha->ingredientes);
        $this->assertSame(['oregano'], $ficha->remocoesSemLinha);
        $this->assertSame('Sem orégano', $ficha->observacao);
    }

    public function test_formata_quantidades(): void
    {
        $this->assertSame('½ un', FichaTecnicaMontador::formatarQuantidade(0.5, 'UN'));
        $this->assertSame('1,5 kg', FichaTecnicaMontador::formatarQuantidade(1.5, 'KG'));
        $this->assertSame('120 ml', FichaTecnicaMontador::formatarQuantidade(0.12, 'L'));
        $this->assertSame('8 un', FichaTecnicaMontador::formatarQuantidade(8, null));
    }
}
