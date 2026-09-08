<?php

namespace Tests\Feature;

use App\Filament\Resources\SessoesCaixa\Pages\CreateSessaoCaixa;
use App\Models\Caixa;
use App\Models\NotaMoeda;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * ContagemNotasSchema (compartilhado por SessaoCaixaForm e FechamentoCaixaForm).
 *
 * Regressão: os botões +/- de cada cédula (suffixAction/prefixAction em
 * TextInput dentro do TableRepeater) usavam Action::action() normal, que
 * despacha via wire:click="mountAction(...)" — dentro do TableRepeater
 * (icetalker/filament-table-repeater), esse mecanismo reusa a mesma instância
 * de Action entre as linhas, e o argumento "schemaComponent" (qual linha o
 * clique deve afetar) ficava desatualizado a cada clique numa linha: o
 * PRIMEIRO clique numa cédula funcionava certo, mas a partir do segundo
 * clique NAQUELA MESMA linha, o botão passava a apontar pra linha ACIMA dela
 * (e assim sucessivamente a cada clique subsequente). Confirmado inspecionando
 * o HTML renderizado por Livewire::test() antes/depois de um clique — só o
 * atributo wire:click da linha clicada ficava com a key da linha anterior; o
 * wire:model.blur do próprio input nunca teve esse problema.
 *
 * Fix: alpineClickHandler() (JS puro, sem mountAction) que lê o
 * wire:model.blur do input irmão diretamente do DOM no momento do clique
 * (sempre correto) e chama $wire.set() — mesmo caminho que já funciona ao
 * digitar o valor manualmente.
 */
class ContagemNotasSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_botoes_de_quantidade_nao_usam_mountaction(): void
    {
        $caixa = Caixa::create(['caixa_nome' => 'Caixa 1']);
        $user = User::factory()->create(['name_first' => 'Operador']);
        $user->assignRole('Admin');
        NotaMoeda::create(['descricao' => 'R$ 100,00', 'valor' => 100, 'tipo' => 'cedula', 'ordem_exibicao' => 1]);

        $this->actingAs($user);

        $html = Livewire::test(CreateSessaoCaixa::class)->html();

        $this->assertStringNotContainsString("mountAction('incrementarQuantidade'", $html);
        $this->assertStringNotContainsString("mountAction('decrementarQuantidade'", $html);
        $this->assertStringContainsString('$wire.set(input.getAttribute', $html);
    }

    public function test_cliques_sucessivos_na_mesma_cedula_nao_vazam_para_a_linha_acima(): void
    {
        $caixa = Caixa::create(['caixa_nome' => 'Caixa 1']);
        $user = User::factory()->create(['name_first' => 'Operador']);
        $user->assignRole('Admin');
        $nota200 = NotaMoeda::create(['descricao' => 'R$ 200,00', 'valor' => 200, 'tipo' => 'cedula', 'ordem_exibicao' => 3]);
        $nota100 = NotaMoeda::create(['descricao' => 'R$ 100,00', 'valor' => 100, 'tipo' => 'cedula', 'ordem_exibicao' => 2]);
        $nota50 = NotaMoeda::create(['descricao' => 'R$ 50,00', 'valor' => 50, 'tipo' => 'cedula', 'ordem_exibicao' => 1]);

        $this->actingAs($user);

        $component = Livewire::test(CreateSessaoCaixa::class);
        $data0 = $component->get('data');
        $key200 = collect($data0['notas'])->search(fn ($row) => $row['nota_moeda_id'] === $nota200->id);
        $key100 = collect($data0['notas'])->search(fn ($row) => $row['nota_moeda_id'] === $nota100->id);
        $key50 = collect($data0['notas'])->search(fn ($row) => $row['nota_moeda_id'] === $nota50->id);

        // Dois "cliques" seguidos no + da nota de 100 (o que o Alpine faz: $wire.set no
        // mesmo path, lido do DOM a cada clique — nunca muda de linha).
        $component->set("data.notas.$key100.quantidade", 1);
        $component->set("data.notas.$key100.quantidade", 2);

        $data = $component->get('data');

        $this->assertSame(0, (int) $data['notas'][$key200]['quantidade'], 'a cédula de cima não deveria ter sido afetada');
        $this->assertSame(2, (int) $data['notas'][$key100]['quantidade']);
        $this->assertSame('200,00', $data['notas'][$key100]['valor_total']);
        $this->assertSame(0, (int) $data['notas'][$key50]['quantidade']);
    }

    /**
     * Regressão: o "Saldo inicial (dinheiro)" no topo do form era um Money
     * ->disabled()->dehydrated(false) sem nenhuma lógica pra se atualizar —
     * ficava sempre em R$ 0,00 durante o preenchimento, mesmo com cédulas já
     * contadas abaixo (só o valor real, calculado no servidor após salvar,
     * aparecia). Virou um TextEntry que reavalia a soma a cada render, igual
     * o Subtotal de cada linha da Contagem de dinheiro.
     */
    public function test_saldo_inicial_no_topo_atualiza_ao_vivo_com_a_contagem(): void
    {
        $caixa = Caixa::create(['caixa_nome' => 'Caixa 1']);
        $user = User::factory()->create(['name_first' => 'Operador']);
        $user->assignRole('Admin');
        $nota100 = NotaMoeda::create(['descricao' => 'R$ 100,00', 'valor' => 100, 'tipo' => 'cedula', 'ordem_exibicao' => 1]);

        $this->actingAs($user);

        $component = Livewire::test(CreateSessaoCaixa::class);
        $component->assertSeeHtml('R$ 0,00');

        $data0 = $component->get('data');
        $key100 = collect($data0['notas'])->search(fn ($row) => $row['nota_moeda_id'] === $nota100->id);

        $component->set("data.notas.$key100.quantidade", 3);
        $component->assertSeeHtml('R$ 300,00');
    }
}
