<?php

namespace Tests\Feature;

use App\Enums\Checklist\TipoResposta;
use App\Models\ChecklistModelo;
use App\Models\ChecklistModeloItem;
use Database\Seeders\ChecklistModelosExemploSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChecklistModelosExemploSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_os_quatro_modelos_da_pizzaria_com_itens_e_papeis(): void
    {
        $this->seed(ChecklistModelosExemploSeeder::class);

        $this->assertSame(
            ['Abertura — Cozinha' => 9, 'Abertura — Salão' => 6, 'Fechamento — Cozinha' => 8, 'Fechamento — Salão' => 4],
            ChecklistModelo::withCount('itens')->orderBy('nome')->pluck('itens_count', 'nome')->all(),
        );
        $aberturaCozinha = ChecklistModelo::where('nome', 'Abertura — Cozinha')->sole();
        $this->assertSame(['Cozinha', 'Gerente'], $aberturaCozinha->roles()->orderBy('name')->pluck('name')->all());
        $this->assertSame(['Equipamentos', 'Pré-preparo', 'Higiene'], $aberturaCozinha->secoes()->pluck('titulo')->all());
        $this->assertTrue(ChecklistModelo::where('nome', 'Fechamento — Cozinha')->sole()->exige_aprovacao);
        $freezer = ChecklistModeloItem::where('checklist_modelo_id', $aberturaCozinha->id)->where('titulo', 'Temperatura do freezer')->sole();
        $this->assertSame(TipoResposta::Temperatura, $freezer->tipo_resposta);
        $this->assertSame(['-25.00', '-18.00'], [$freezer->valor_minimo, $freezer->valor_maximo]);
    }

    public function test_rodar_de_novo_nao_duplica_nem_desfaz_ajustes_do_gestor(): void
    {
        $this->seed(ChecklistModelosExemploSeeder::class);
        ChecklistModelo::where('nome', 'Abertura — Salão')->sole()->update(['janela_fim' => '19:00']);

        $this->seed(ChecklistModelosExemploSeeder::class);

        $this->assertSame(4, ChecklistModelo::count());
        $this->assertSame('19:00:00', ChecklistModelo::where('nome', 'Abertura — Salão')->sole()->janela_fim);
    }
}
