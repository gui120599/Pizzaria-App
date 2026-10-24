<?php

namespace Tests\Feature;

use App\Enums\StatusInfluenciadorEnum;
use App\Enums\TipoDescontoEnum;
use App\Filament\Influenciador\Resources\Cupoms\Pages\ListCupoms;
use App\Http\Controllers\ReferenciaInfluenciadorController;
use App\Models\CliqueReferencia;
use App\Models\Cupom;
use App\Models\Influenciador;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** F1.4: link rastreável /r/{slug}. */
class ReferenciaInfluenciadorTest extends TestCase
{
    use RefreshDatabase;

    private function influenciador(string $nome = 'Maria Souza', StatusInfluenciadorEnum $status = StatusInfluenciadorEnum::ATIVO): Influenciador
    {
        $influenciador = Influenciador::create([
            'influenciador_nome' => $nome,
            'email' => uniqid().'@teste.com',
            'password' => 'senha-segura',
        ]);
        $influenciador->forceFill(['influenciador_status' => $status])->save();

        return $influenciador;
    }

    private function cupomPrincipal(Influenciador $dono, string $codigo = 'MARIA10'): Cupom
    {
        $cupom = Cupom::create([
            'cupom_influenciador_id' => $dono->id,
            'cupom_codigo' => $codigo,
            'cupom_tipo_desconto' => TipoDescontoEnum::PERCENTUAL->value,
            'cupom_valor_desconto' => 10,
        ]);
        $dono->update(['influenciador_cupom_principal_id' => $cupom->id]);

        return $cupom;
    }

    public function test_slug_nasce_do_nome_e_e_unico(): void
    {
        $this->assertSame('maria-souza', $this->influenciador('Maria Souza')->influenciador_slug);
        $this->assertSame('maria-souza-2', $this->influenciador('Maria Souza')->influenciador_slug);
    }

    public function test_clique_e_registrado_e_abre_o_cardapio_com_o_cupom_principal(): void
    {
        $dono = $this->influenciador();
        $this->cupomPrincipal($dono);

        $this->get('/r/maria-souza', ['User-Agent' => 'TesteBrowser/1.0'])
            ->assertRedirect(route('cardapio', ['cupom' => 'MARIA10']))
            ->assertCookie(ReferenciaInfluenciadorController::COOKIE, (string) $dono->id);

        $clique = CliqueReferencia::sole();
        $this->assertSame($dono->id, $clique->clique_influenciador_id);
        $this->assertSame('TesteBrowser/1.0', $clique->clique_user_agent);
        // O IP vai só como hash, nunca em claro.
        $this->assertSame(64, strlen($clique->clique_ip_hash));
        $this->assertStringNotContainsString('127.0.0.1', $clique->clique_ip_hash);
    }

    public function test_sem_cupom_principal_valido_abre_o_cardapio_sem_cupom(): void
    {
        $dono = $this->influenciador();
        $cupom = $this->cupomPrincipal($dono);
        $cupom->update(['cupom_ativo' => false]);

        $this->get('/r/maria-souza')->assertRedirect(route('cardapio'));

        $this->assertSame(1, CliqueReferencia::count());
    }

    public function test_influenciador_inativo_abre_o_cardapio_sem_cupom_e_sem_rastrear(): void
    {
        $dono = $this->influenciador(status: StatusInfluenciadorEnum::INATIVO);
        $this->cupomPrincipal($dono);

        $this->get('/r/maria-souza')
            ->assertRedirect(route('cardapio'))
            ->assertCookieMissing(ReferenciaInfluenciadorController::COOKIE);

        $this->assertSame(0, CliqueReferencia::count());
    }

    public function test_slug_desconhecido_cai_no_cardapio(): void
    {
        $this->get('/r/nao-existe')->assertRedirect(route('cardapio'));
        $this->assertSame(0, CliqueReferencia::count());
    }

    public function test_link_tem_limite_de_acessos_por_ip(): void
    {
        $this->influenciador();

        for ($i = 0; $i < 30; $i++) {
            $this->get('/r/maria-souza')->assertRedirect();
        }

        $this->get('/r/maria-souza')->assertStatus(429);
    }

    public function test_influenciador_escolhe_o_cupom_principal_em_meus_cupons(): void
    {
        $dono = $this->influenciador();
        $primeiro = $this->cupomPrincipal($dono);
        $segundo = Cupom::create([
            'cupom_influenciador_id' => $dono->id,
            'cupom_codigo' => 'SEGUNDO',
            'cupom_tipo_desconto' => TipoDescontoEnum::PERCENTUAL->value,
            'cupom_valor_desconto' => 5,
        ]);
        $this->actingAs($dono, 'influenciador');
        Filament::setCurrentPanel(Filament::getPanel('influenciador'));

        Livewire::test(ListCupoms::class)
            ->assertTableActionHidden('definirPrincipal', $primeiro)
            ->callTableAction('definirPrincipal', $segundo);

        $this->assertSame($segundo->id, $dono->fresh()->influenciador_cupom_principal_id);
    }
}
