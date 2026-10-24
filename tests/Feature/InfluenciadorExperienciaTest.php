<?php

namespace Tests\Feature;

use App\Enums\ProdutoTipoEnum;
use App\Enums\StatusInfluenciadorEnum;
use App\Enums\TipoDescontoEnum;
use App\Enums\TipoMovimentoCarteiraEnum;
use App\Filament\Influenciador\Pages\AceitarRegulamento;
use App\Filament\Influenciador\Pages\Auth\EditProfile;
use App\Filament\Influenciador\Pages\Auth\Register;
use App\Filament\Influenciador\Pages\KitDivulgacao;
use App\Filament\Influenciador\Widgets\ComissaoStatsOverview;
use App\Filament\Influenciador\Widgets\PedidosPorDiaChart;
use App\Filament\Pages\RegulamentoInfluenciadores;
use App\Filament\Pages\RelatorioInfluenciadores;
use App\Filament\Resources\Influenciadores\Pages\ManageInfluenciadores;
use App\Filament\Widgets\RelatorioInfluenciadoresWidget;
use App\Models\CarteiraMovimento;
use App\Models\Categoria;
use App\Models\Cliente;
use App\Models\CliqueReferencia;
use App\Models\Cupom;
use App\Models\Influenciador;
use App\Models\ItensPedido;
use App\Models\Pedido;
use App\Models\Produto;
use App\Models\RegulamentoInfluenciador;
use App\Models\User;
use App\Models\Venda;
use App\Providers\Filament\InfluenciadorPanelProvider;
use App\Services\CarteiraInfluenciadorService;
use App\Services\ComissaoService;
use App\Services\InfluenciadorService;
use App\Services\RelatorioInfluenciadoresService;
use Database\Seeders\PermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** Fase 3: regulamento, senha, perfil, notificações, dashboard, kit e relatório. */
class InfluenciadorExperienciaTest extends TestCase
{
    use RefreshDatabase;

    private function influenciador(array $extra = []): Influenciador
    {
        $influenciador = Influenciador::create(array_merge([
            'influenciador_nome' => 'Maria Souza',
            'email' => uniqid().'@teste.com',
            'password' => 'senha-segura-123',
            'influenciador_valor_comissao' => 10,
        ], $extra));
        $influenciador->forceFill(['influenciador_status' => StatusInfluenciadorEnum::ATIVO])->save();

        return $influenciador;
    }

    private function publicarRegulamento(string $texto = 'Texto do regulamento do programa.'): RegulamentoInfluenciador
    {
        return RegulamentoInfluenciador::create([
            'ri_versao' => (int) RegulamentoInfluenciador::max('ri_versao') + 1,
            'ri_texto' => $texto,
            'ri_publicado_em' => now(),
        ]);
    }

    private function noPainel(Influenciador $influenciador): void
    {
        $this->actingAs($influenciador, 'influenciador');
        Filament::setCurrentPanel(Filament::getPanel('influenciador'));
    }

    // ── Regulamento (D8) ────────────────────────────────────────────────────

    public function test_admin_publica_versoes_do_regulamento_e_so_o_admin_abre_a_pagina(): void
    {
        $this->seed(PermissionSeeder::class);
        $this->actingAs(User::factory()->gerente()->create(['name_first' => 'Gerente']));
        $this->assertFalse(RegulamentoInfluenciadores::canAccess());

        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $this->assertTrue(RegulamentoInfluenciadores::canAccess());

        Livewire::test(RegulamentoInfluenciadores::class)
            ->fillForm(['texto' => 'Primeira versão do regulamento.'])
            ->call('publicar');
        Livewire::test(RegulamentoInfluenciadores::class)
            ->fillForm(['texto' => 'Segunda versão do regulamento.'])
            ->call('publicar');

        $this->assertSame([1, 2], RegulamentoInfluenciador::orderBy('ri_versao')->pluck('ri_versao')->all());
        $this->assertSame('Segunda versão do regulamento.', RegulamentoInfluenciador::atual()->ri_texto);
    }

    public function test_autocadastro_exige_o_aceite_e_grava_versao_data_e_ip(): void
    {
        $versao = $this->publicarRegulamento();
        Filament::setCurrentPanel(Filament::getPanel('influenciador'));

        $dados = [
            'influenciador_nome' => 'Fulano de Tal',
            'email' => 'fulano@teste.com',
            'password' => 'senha-segura-123',
            'passwordConfirmation' => 'senha-segura-123',
        ];

        Livewire::test(Register::class)->fillForm($dados)->call('register')->assertHasFormErrors(['aceite_regulamento']);
        $this->assertSame(0, Influenciador::count());

        Livewire::test(Register::class)->fillForm($dados + ['aceite_regulamento' => true])->call('register')->assertHasNoFormErrors();

        $influenciador = Influenciador::sole();
        $this->assertSame($versao->ri_versao, $influenciador->influenciador_regulamento_versao);
        $this->assertNotNull($influenciador->influenciador_regulamento_aceito_em);
        $this->assertNotNull($influenciador->influenciador_regulamento_aceito_ip);
    }

    public function test_sem_regulamento_publicado_o_cadastro_nao_exige_aceite(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('influenciador'));

        Livewire::test(Register::class)
            ->fillForm([
                'influenciador_nome' => 'Fulano de Tal',
                'email' => 'fulano@teste.com',
                'password' => 'senha-segura-123',
                'passwordConfirmation' => 'senha-segura-123',
            ])
            ->call('register')
            ->assertHasNoFormErrors();

        $this->assertNull(Influenciador::sole()->influenciador_regulamento_versao);
    }

    public function test_pagina_publica_do_regulamento_mostra_a_versao_atual(): void
    {
        $this->get(route('influenciador.regulamento'))->assertOk()->assertSee('ainda não foi publicado');

        $this->publicarRegulamento('Regra única do programa.');
        $this->get(route('influenciador.regulamento'))->assertOk()->assertSee('Regra única do programa.');
    }

    public function test_versao_nova_bloqueia_o_painel_ate_o_aceite(): void
    {
        $this->publicarRegulamento();
        $influenciador = $this->influenciador();
        $influenciador->aceitarRegulamento('127.0.0.1');

        $this->actingAs($influenciador, 'influenciador');
        $this->get('/influenciadores')->assertOk();

        // Sai uma versão nova: qualquer página do painel redireciona para o aceite.
        $this->publicarRegulamento('Versão 2 com regras novas.');
        $this->get('/influenciadores')->assertRedirect(AceitarRegulamento::getUrl(panel: 'influenciador'));
        $this->get('/influenciadores/carteira')->assertRedirect(AceitarRegulamento::getUrl(panel: 'influenciador'));
        $this->get('/influenciadores/aceitar-regulamento')->assertOk()->assertSee('Versão 2 com regras novas.');

        $this->noPainel($influenciador);
        Livewire::test(AceitarRegulamento::class)->call('aceitar')->assertNotified();
        $this->assertTrue($influenciador->fresh()->precisaAceitarRegulamento());

        Livewire::test(AceitarRegulamento::class)->set('aceito', true)->call('aceitar');

        $influenciador->refresh();
        $this->assertFalse($influenciador->precisaAceitarRegulamento());
        $this->assertSame(2, $influenciador->influenciador_regulamento_versao);
        $this->get('/influenciadores')->assertOk();
    }

    // ── Senha ───────────────────────────────────────────────────────────────

    public function test_admin_redefine_a_senha_e_o_influenciador_e_forcado_a_trocar(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $influenciador = $this->influenciador();
        $senhaAntiga = $influenciador->password;

        Livewire::test(ManageInfluenciadores::class)->callTableAction('redefinirSenha', $influenciador);

        $influenciador->refresh();
        $this->assertNotSame($senhaAntiga, $influenciador->password);
        $this->assertTrue($influenciador->influenciador_deve_trocar_senha);

        // Com a senha temporária, qualquer página do painel manda para o perfil.
        $this->actingAs($influenciador, 'influenciador');
        $this->get('/influenciadores')->assertRedirect(Filament::getPanel('influenciador')->getProfileUrl());
        $this->get(Filament::getPanel('influenciador')->getProfileUrl())->assertOk();
    }

    public function test_perfil_so_libera_o_painel_com_uma_nova_senha(): void
    {
        $influenciador = $this->influenciador();
        $influenciador->forceFill(['influenciador_deve_trocar_senha' => true])->save();
        $this->noPainel($influenciador);

        Livewire::test(EditProfile::class)->call('save');
        $this->assertTrue($influenciador->fresh()->influenciador_deve_trocar_senha);

        Livewire::test(EditProfile::class)
            ->fillForm(['password' => 'NovaSenha#2026', 'passwordConfirmation' => 'NovaSenha#2026', 'currentPassword' => 'senha-segura-123'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($influenciador->fresh()->influenciador_deve_trocar_senha);
        $this->assertTrue(password_verify('NovaSenha#2026', $influenciador->fresh()->password));
    }

    public function test_recuperacao_de_senha_so_liga_com_email_configurado(): void
    {
        $this->assertFalse(InfluenciadorPanelProvider::emailConfigurado(), 'No teste o mailer é "array".');
        $this->assertFalse(Filament::getPanel('influenciador')->hasPasswordReset());

        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.exemplo.com', 'mail.from.address' => 'loja@exemplo.com']);
        $this->assertTrue(InfluenciadorPanelProvider::emailConfigurado());

        config(['mail.mailers.smtp.host' => '']);
        $this->assertFalse(InfluenciadorPanelProvider::emailConfigurado());
    }

    // ── Perfil ──────────────────────────────────────────────────────────────

    public function test_perfil_valida_cpf_normaliza_whatsapp_e_criptografa_cpf_e_pix(): void
    {
        $influenciador = $this->influenciador();
        $this->noPainel($influenciador);

        Livewire::test(EditProfile::class)
            ->fillForm(['influenciador_cpf' => '111.111.111-11', 'influenciador_chave_pix' => 'maria@teste.com'])
            ->call('save')
            ->assertHasFormErrors(['influenciador_cpf']);

        Livewire::test(EditProfile::class)
            ->fillForm([
                'influenciador_whatsapp' => '(64) 98138-0071',
                'influenciador_cpf' => '529.982.247-25',
                'influenciador_chave_pix' => 'maria@teste.com',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $influenciador->refresh();
        $this->assertSame('5564981380071', $influenciador->influenciador_whatsapp);
        $this->assertSame('52998224725', $influenciador->influenciador_cpf);
        $this->assertSame('maria@teste.com', $influenciador->influenciador_chave_pix);

        // No banco o CPF e o Pix ficam criptografados.
        $bruto = DB::table('influenciadores')->where('id', $influenciador->id)->first();
        $this->assertStringNotContainsString('52998224725', (string) $bruto->influenciador_cpf);
        $this->assertStringNotContainsString('maria@teste.com', (string) $bruto->influenciador_chave_pix);
        $this->assertNotNull($bruto->influenciador_cpf_hash);
    }

    public function test_cpf_duplicado_e_recusado_no_perfil(): void
    {
        $this->influenciador(['influenciador_cpf' => '529.982.247-25']);
        $outro = $this->influenciador();
        $this->noPainel($outro);

        Livewire::test(EditProfile::class)
            ->fillForm(['influenciador_cpf' => '529.982.247-25'])
            ->call('save')
            ->assertHasFormErrors(['influenciador_cpf']);
    }

    // ── Notificações no painel (D7) ─────────────────────────────────────────

    public function test_painel_tem_notificacoes_de_banco_e_os_eventos_chegam_la(): void
    {
        $this->assertTrue(Filament::getPanel('influenciador')->hasDatabaseNotifications());

        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $influenciador = $this->influenciador();
        $influenciador->forceFill(['influenciador_status' => StatusInfluenciadorEnum::PENDENTE])->save();

        // Cadastro aprovado.
        app(InfluenciadorService::class)->aprovar($influenciador);
        $this->assertSame('Cadastro aprovado', $influenciador->notifications()->latest()->first()->data['title']);

        // Comissão creditada e estornada (depois do commit da transação).
        $cupom = Cupom::create(['cupom_influenciador_id' => $influenciador->id, 'cupom_codigo' => 'MARIA10', 'cupom_tipo_desconto' => TipoDescontoEnum::PERCENTUAL->value, 'cupom_valor_desconto' => 10]);
        $pedido = Pedido::create(['pedido_cupom_id' => $cupom->id, 'pedido_valor_total' => 100, 'pedido_status' => 'PREPARANDO']);
        ItensPedido::create(['item_pedido_pedido_id' => $pedido->id, 'item_pedido_produto_id' => $this->produtoId(), 'item_pedido_quantidade' => 1, 'item_pedido_valor_unitario' => 100, 'item_pedido_valor' => 100, 'item_pedido_desconto' => 0, 'item_pedido_status' => 'INSERIDO']);

        app(ComissaoService::class)->creditar($pedido);
        app(ComissaoService::class)->cancelar($pedido);

        $titulos = $influenciador->notifications()->orderBy('created_at')->get()->pluck('data.title')->all();
        $this->assertContains('Comissão creditada', $titulos);
        $this->assertContains('Comissão estornada', $titulos);
    }

    private function produtoId(): int
    {
        return Produto::create([
            'produto_descricao' => 'Pizza '.uniqid(),
            'produto_categoria_id' => Categoria::firstOrCreate(['categoria_nome' => 'Pizzas'])->id,
            'produto_tipo' => ProdutoTipoEnum::PRODUZIDO->value,
            'produto_preco_venda' => 100,
        ])->id;
    }

    // ── Dashboard ───────────────────────────────────────────────────────────

    public function test_dashboard_conta_so_pedidos_validos_e_separa_pendente_de_saldo(): void
    {
        $influenciador = $this->influenciador();
        $cupom = Cupom::create(['cupom_influenciador_id' => $influenciador->id, 'cupom_codigo' => 'MARIA10', 'cupom_tipo_desconto' => TipoDescontoEnum::PERCENTUAL->value, 'cupom_valor_desconto' => 10]);
        $cliente = Cliente::create(['cliente_nome' => 'Cliente', 'cliente_tipo' => 'PF']);

        foreach (['FINALIZADO' => 100, 'INICIADO' => 700, 'CANCELADO' => 300] as $status => $valor) {
            Pedido::create(['pedido_cliente_id' => $cliente->id, 'pedido_cupom_id' => $cupom->id, 'pedido_valor_total' => $valor, 'pedido_status' => $status]);
        }

        app(CarteiraInfluenciadorService::class)->lancar($influenciador, TipoMovimentoCarteiraEnum::CREDITO_COMISSAO, 25);
        CliqueReferencia::create(['clique_influenciador_id' => $influenciador->id, 'clique_ip_hash' => str_repeat('a', 64)]);
        CliqueReferencia::create(['clique_influenciador_id' => $influenciador->id, 'clique_ip_hash' => str_repeat('b', 64)]);
        $this->noPainel($influenciador);

        Livewire::test(ComissaoStatsOverview::class)
            ->assertSee('Vendas Geradas')
            ->assertSee('R$ 100,00')
            ->assertDontSee('R$ 700,00')
            ->assertDontSee('R$ 300,00')
            ->assertSee('Saldo da Carteira')
            ->assertSee('R$ 25,00')
            ->assertSee('Comissão Pendente')
            ->assertSee('Cliques no Link')
            ->assertSee('Ativo');

        Livewire::test(PedidosPorDiaChart::class)->assertOk();
    }

    // ── Kit de divulgação ───────────────────────────────────────────────────

    public function test_kit_mostra_link_rastreavel_e_qr_do_cupom_principal(): void
    {
        $influenciador = $this->influenciador();
        $cupom = Cupom::create(['cupom_influenciador_id' => $influenciador->id, 'cupom_codigo' => 'MARIA10', 'cupom_tipo_desconto' => TipoDescontoEnum::PERCENTUAL->value, 'cupom_valor_desconto' => 10]);
        $influenciador->update(['influenciador_cupom_principal_id' => $cupom->id]);
        $this->noPainel($influenciador);

        Livewire::test(KitDivulgacao::class)
            ->assertSee('/r/'.$influenciador->influenciador_slug)
            ->assertSee('MARIA10')
            ->assertSee('Copiar link')
            ->assertSeeHtml('data:image/png;base64,');
    }

    // ── Relatório admin ─────────────────────────────────────────────────────

    public function test_relatorio_e_so_de_admin_e_gerente(): void
    {
        $this->seed(PermissionSeeder::class);

        foreach (['garcom', 'atendente', 'caixa'] as $papel) {
            $this->actingAs(User::factory()->{$papel}()->create(['name_first' => ucfirst($papel)]));
            $this->assertFalse(RelatorioInfluenciadores::canAccess(), $papel);
        }

        foreach (['gerente', 'admin'] as $papel) {
            $this->actingAs(User::factory()->{$papel}()->create(['name_first' => ucfirst($papel)]));
            $this->assertTrue(RelatorioInfluenciadores::canAccess(), $papel);
        }
    }

    public function test_relatorio_soma_cliques_pedidos_descontos_comissoes_resgates_e_saldo(): void
    {
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
        $influenciador = $this->influenciador();
        $cupom = Cupom::create(['cupom_influenciador_id' => $influenciador->id, 'cupom_codigo' => 'MARIA10', 'cupom_tipo_desconto' => TipoDescontoEnum::PERCENTUAL->value, 'cupom_valor_desconto' => 10]);
        $venda = Venda::create(['venda_status' => 'FINALIZADA', 'venda_valor_total' => 90]);
        $pedido = Pedido::create(['pedido_cupom_id' => $cupom->id, 'pedido_venda_id' => $venda->id, 'pedido_valor_total' => 90, 'pedido_status' => 'FINALIZADO']);
        ItensPedido::create(['item_pedido_pedido_id' => $pedido->id, 'item_pedido_produto_id' => $this->produtoId(), 'item_pedido_quantidade' => 1, 'item_pedido_valor_unitario' => 100, 'item_pedido_valor' => 90, 'item_pedido_desconto' => 0, 'item_pedido_desconto_cupom' => 10, 'item_pedido_status' => 'INSERIDO']);
        // Pedido ainda sem venda não entra.
        Pedido::create(['pedido_cupom_id' => $cupom->id, 'pedido_valor_total' => 500, 'pedido_status' => 'PREPARANDO']);

        CliqueReferencia::create(['clique_influenciador_id' => $influenciador->id, 'clique_ip_hash' => str_repeat('a', 64)]);
        $carteira = app(CarteiraInfluenciadorService::class);
        $carteira->lancar($influenciador, TipoMovimentoCarteiraEnum::CREDITO_COMISSAO, 40);
        $carteira->debitar($influenciador, TipoMovimentoCarteiraEnum::CONSUMO, 15);

        $linhas = (new RelatorioInfluenciadoresService(now()->startOfMonth(), now()->endOfDay()))->linhas();

        $linha = $linhas->sole();
        $this->assertSame(1, $linha['cliques']);
        $this->assertSame(1, $linha['pedidos']);
        $this->assertSame(90.0, $linha['vendas']);
        $this->assertSame(10.0, $linha['descontos']);
        $this->assertSame(40.0, $linha['comissoes']);
        $this->assertSame(15.0, $linha['consumo']);
        $this->assertSame(25.0, $linha['saldo']);

        Livewire::test(RelatorioInfluenciadoresWidget::class)->assertOk()->assertSee('Maria Souza');
        $this->assertSame(1, CarteiraMovimento::where('cm_tipo', TipoMovimentoCarteiraEnum::CONSUMO)->count());
    }
}
