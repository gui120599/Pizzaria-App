<?php

namespace Tests\Feature;

use App\Enums\CompraStatusEnum;
use App\Enums\SefazNotaRecebidaStatusEnum;
use App\Exceptions\NfeDestinatarioDivergenteException;
use App\Filament\Resources\Compras\Pages\ListCompras;
use App\Models\Categoria;
use App\Models\Compra;
use App\Models\CompraItem;
use App\Models\Empresa;
use App\Models\MovimentacaoProduto;
use App\Models\Prestador;
use App\Models\Produto;
use App\Models\SefazNotaRecebida;
use App\Models\User;
use App\Services\CompraService;
use App\Services\Nfe\NfeImportService;
use App\Services\Sefaz\Contracts\SefazClient;
use App\Services\Sefaz\Dto\SefazLoteDistribuicao;
use App\Services\Sefaz\Dto\SefazResumoDocumento;
use App\Services\Sefaz\SefazDistribuicaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\Support\FakeSefazClient;
use Tests\TestCase;

/** Fase 0 do plano docs/planos/operacoes-fiscais-entrada-nfe.md (D5 a D8). */
class ComprasIntegridadeNfeTest extends TestCase
{
    use RefreshDatabase;

    private const CHAVE_VENDA = '35261014200166000166550010000001011000001010';

    private const CHAVE_OUTRO_DESTINATARIO = '35261014200166000166550010000001091000001090';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));

        Empresa::create([
            'empresa_razao_social' => 'Pizzaria Teste LTDA',
            'empresa_cnpj' => '11.222.333/0001-81',
            'empresa_sefaz_ultimo_nsu' => 0,
        ]);

        config(['sefaz.intervalo_pos_manifestacao' => [0, 0, 0]]);
    }

    private function fixture(string $nome): string
    {
        return file_get_contents(base_path("tests/Fixtures/nfe-entrada/{$nome}.xml"));
    }

    private function fake(): FakeSefazClient
    {
        $fake = new FakeSefazClient;
        $this->app->bind(SefazClient::class, fn () => $fake);

        return $fake;
    }

    /** Compra com 1 item já vinculado a um insumo, pronta para confirmar. */
    private function compraPronta(string $status = 'rascunho'): Compra
    {
        $categoria = Categoria::create(['categoria_nome' => 'Teste']);
        $fornecedor = Prestador::create([
            'tipo' => 'pj', 'categoria' => 'fornecedor',
            'razao_social' => 'Distribuidora X', 'nome' => 'Distribuidora X',
        ]);
        $insumo = Produto::create([
            'produto_descricao' => 'Farinha',
            'produto_categoria_id' => $categoria->id,
            'produto_tipo' => 'insumo',
            'produto_unidade_estoque' => 'KG',
        ]);

        $compra = Compra::create([
            'compra_prestador_id' => $fornecedor->id,
            'compra_data_entrada' => today(),
            'compra_status' => $status,
            'compra_user_id' => auth()->id(),
        ]);
        CompraItem::create([
            'ci_compra_id' => $compra->id, 'ci_produto_id' => $insumo->id,
            'ci_quantidade_compra' => 4, 'ci_fator_conversao' => 1, 'ci_custo_unitario_compra' => 25,
        ]);
        app(CompraService::class)->recalcularTotais($compra->fresh('itens'));

        return $compra->fresh();
    }

    /** Usuário que NÃO é Admin (o Admin passa por qualquer Policy via Gate::before). */
    private function usuarioComPermissaoDeExcluir(): User
    {
        $usuario = User::factory()->create(['name_first' => 'Comprador']);
        $usuario->givePermissionTo(array_map(
            fn (string $nome) => Permission::findOrCreate($nome),
            ['view_any:compra', 'view:compra', 'delete:compra', 'delete_any:compra'],
        ));

        return $usuario;
    }

    public function test_exclusao_em_massa_so_exclui_rascunhos(): void
    {
        $rascunho = $this->compraPronta();
        $confirmada = $this->compraPronta();
        app(CompraService::class)->confirmar($confirmada);
        $this->actingAs($this->usuarioComPermissaoDeExcluir());

        Livewire::test(ListCompras::class)
            ->callTableBulkAction('delete', [$rascunho, $confirmada]);

        $this->assertSoftDeleted($rascunho);
        $this->assertNotSoftDeleted($confirmada);
        $this->assertSame(CompraStatusEnum::CONFIRMADA, $confirmada->fresh()->compra_status);
    }

    public function test_policy_nao_permite_excluir_compra_confirmada(): void
    {
        $rascunho = $this->compraPronta();
        $confirmada = $this->compraPronta();
        app(CompraService::class)->confirmar($confirmada);

        $usuario = $this->usuarioComPermissaoDeExcluir();

        $this->assertTrue($usuario->can('delete', $rascunho));
        $this->assertFalse($usuario->can('delete', $confirmada->fresh()));
    }

    public function test_confirmar_duas_vezes_a_mesma_compra_nao_duplica_o_estoque(): void
    {
        $compra = $this->compraPronta();
        $obsoleta = Compra::find($compra->id); // instância ainda em memória como rascunho

        app(CompraService::class)->confirmar($compra);

        try {
            app(CompraService::class)->confirmar($obsoleta);
            $this->fail('A segunda confirmação deveria falhar.');
        } catch (ValidationException) {
            // esperado: a checagem de status acontece depois do lock
        }

        $this->assertSame(1, MovimentacaoProduto::where('mov_referencia_id', $compra->id)->count());
    }

    public function test_gerar_conta_pagar_depois_de_confirmada_por_instancia_obsoleta_nao_duplica_titulos(): void
    {
        $compra = $this->compraPronta();
        $outraInstancia = Compra::find($compra->id);
        $parcelas = [['vencimento' => today()->toDateString(), 'valor' => 100]];

        app(CompraService::class)->confirmar($compra);
        app(CompraService::class)->gerarContaPagar($compra, ['parcelas' => $parcelas]);

        $this->expectException(ValidationException::class);
        app(CompraService::class)->gerarContaPagar($outraInstancia, ['parcelas' => $parcelas]);
    }

    public function test_reimportar_apos_excluir_o_rascunho_remove_o_antigo_e_cria_a_nova_compra(): void
    {
        $service = app(NfeImportService::class);
        $primeira = $service->importar($this->fixture('venda-5102-duplicatas'), auth()->id());
        $xmlPath = $primeira->compra_xml_path;
        $primeira->delete();

        $segunda = $service->importar($this->fixture('venda-5102-duplicatas'), auth()->id());

        $this->assertNotSame($primeira->id, $segunda->id);
        $this->assertSame(1, Compra::withTrashed()->where('compra_chave_nfe', self::CHAVE_VENDA)->count());
        $this->assertDatabaseMissing('compras', ['id' => $primeira->id]);
        $this->assertSame(2, $segunda->itens()->count());
        $this->assertTrue(\Storage::disk('local')->exists($xmlPath));
    }

    public function test_reimportar_compra_confirmada_que_foi_excluida_bloqueia_com_mensagem_clara(): void
    {
        $service = app(NfeImportService::class);
        $compra = $service->importar($this->fixture('venda-5102-duplicatas'), auth()->id());
        $compra->forceFill(['compra_status' => CompraStatusEnum::CONFIRMADA])->save();
        $compra->delete();

        try {
            $service->importar($this->fixture('venda-5102-duplicatas'), auth()->id());
            $this->fail('Deveria bloquear.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString("compra #{$compra->id}", $e->errors()['compra_chave_nfe'][0]);
            $this->assertStringContainsString('Restaure', $e->errors()['compra_chave_nfe'][0]);
        }

        $this->assertSoftDeleted($compra);
    }

    public function test_importar_nota_de_outro_destinatario_e_bloqueado_com_mensagem_amigavel(): void
    {
        try {
            app(NfeImportService::class)->importar($this->fixture('destinatario-diferente'), auth()->id());
            $this->fail('Deveria bloquear.');
        } catch (NfeDestinatarioDivergenteException $e) {
            $this->assertStringContainsString('não é destinada à sua empresa', $e->errors()['destinatario'][0]);
        }

        $this->assertSame(0, Compra::withTrashed()->count());
    }

    public function test_cnpj_da_empresa_com_mascara_e_comparado_so_pelos_digitos(): void
    {
        $compra = app(NfeImportService::class)->importar($this->fixture('venda-5102-duplicatas'), auth()->id());

        $this->assertSame('11222333000181', $compra->compra_dest_documento);
    }

    public function test_polling_ignora_nota_de_outro_destinatario_e_registra_o_motivo(): void
    {
        SefazNotaRecebida::create([
            'snr_chave_acesso' => self::CHAVE_OUTRO_DESTINATARIO,
            'snr_nsu' => 10,
            'snr_status' => SefazNotaRecebidaStatusEnum::PENDENTE,
            'snr_encontrada_em' => now(),
        ]);

        $this->fake()
            ->comDocumentoCompleto(self::CHAVE_OUTRO_DESTINATARIO, $this->fixture('destinatario-diferente'))
            ->comLote(new SefazLoteDistribuicao(
                itens: [new SefazResumoDocumento(nsu: 10, chaveAcesso: self::CHAVE_OUTRO_DESTINATARIO, isEvento: false)],
                ultNsuRetornado: 10,
                maxNsu: 10,
                cStat: '138',
            ));

        $resultado = app(SefazDistribuicaoService::class)->executarPolling();

        $this->assertSame(0, $resultado->importadas);
        $this->assertSame(0, $resultado->erros);
        $this->assertSame(0, Compra::withTrashed()->count());
        $this->assertSame(
            SefazNotaRecebidaStatusEnum::IGNORADA,
            SefazNotaRecebida::where('snr_chave_acesso', self::CHAVE_OUTRO_DESTINATARIO)->value('snr_status'),
        );
    }

    public function test_polling_nao_quebra_quando_a_chave_pertence_a_compra_confirmada_excluida(): void
    {
        $compra = app(NfeImportService::class)->importar($this->fixture('venda-5102-duplicatas'), auth()->id());
        $compra->forceFill(['compra_status' => CompraStatusEnum::CONFIRMADA])->save();
        $compra->delete();

        $this->fake()
            ->comDocumentoCompleto(self::CHAVE_VENDA, $this->fixture('venda-5102-duplicatas'))
            ->comLote(new SefazLoteDistribuicao(
                itens: [new SefazResumoDocumento(nsu: 10, chaveAcesso: self::CHAVE_VENDA, isEvento: false)],
                ultNsuRetornado: 10,
                maxNsu: 10,
                cStat: '138',
            ));

        $resultado = app(SefazDistribuicaoService::class)->executarPolling();

        $this->assertSame(0, $resultado->importadas);
        $this->assertSame(1, $resultado->erros);
        $this->assertSoftDeleted($compra);
    }
}
