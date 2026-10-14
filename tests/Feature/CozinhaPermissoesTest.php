<?php

namespace Tests\Feature;

use App\Exceptions\ProducaoException;
use App\Models\CozinhaHistorico;
use App\Models\DispositivoCozinha;
use App\Models\FichaTecnica;
use App\Models\FichaTecnicaPasso;
use App\Models\ProducaoItem;
use App\Models\SetorProducao;
use App\Models\User;
use App\Services\Cozinha\PareamentoDispositivoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/** Fase 2: policies, pareamento do tablet, auditoria dos cadastros e versão da ficha. */
class CozinhaPermissoesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_papeis_da_cozinha_produzem_mas_nao_editam_ficha_nem_despacham(): void
    {
        $cozinha = User::factory()->cozinha()->create(['name_first' => 'Ana']);
        $garcom = User::factory()->garcom()->create(['name_first' => 'Beto']);
        $expedicao = User::factory()->create(['name_first' => 'Caio']);
        $expedicao->assignRole('Expedicao');
        $item = ProducaoItem::factory()->create();
        $ficha = FichaTecnica::factory()->create();

        $this->assertTrue(Gate::forUser($cozinha)->allows('produzir', $item));
        $this->assertTrue(Gate::forUser($cozinha)->allows('view', $ficha));
        $this->assertFalse(Gate::forUser($cozinha)->allows('update', $ficha));
        $this->assertFalse(Gate::forUser($cozinha)->allows('despachar', ProducaoItem::class));
        $this->assertFalse(Gate::forUser($garcom)->allows('produzir', $item));
        $this->assertTrue(Gate::forUser($expedicao)->allows('despachar', ProducaoItem::class));
        $this->assertTrue(Gate::forUser($expedicao)->allows('refazer', ProducaoItem::class));

        config(['cozinha.cozinha_despacha' => true]);
        $this->assertTrue(Gate::forUser($cozinha)->allows('despachar', ProducaoItem::class));
    }

    public function test_tablet_so_produz_os_setores_pareados(): void
    {
        $cozinha = User::factory()->cozinha()->create(['name_first' => 'Ana']);
        $pizzaria = SetorProducao::factory()->create();
        $bar = SetorProducao::factory()->bar()->create();
        $tablet = DispositivoCozinha::factory()->paraSetores($pizzaria)->create();

        $this->assertTrue(Gate::forUser($cozinha)->allows('produzir', [ProducaoItem::factory()->doSetor($pizzaria)->create(), $tablet]));
        $this->assertFalse(Gate::forUser($cozinha)->allows('produzir', [ProducaoItem::factory()->doSetor($bar)->create(), $tablet]));
    }

    public function test_token_de_pareamento_e_de_uso_unico_e_expira(): void
    {
        Carbon::setTestNow('2026-10-14 18:00:00');
        $this->actingAs(User::factory()->gerente()->create(['name_first' => 'Gerente']));
        $servico = app(PareamentoDispositivoService::class);
        $tablet = DispositivoCozinha::factory()->create();

        $token = $servico->gerarToken($tablet);
        $tablet->refresh();
        $this->assertNotSame($token, $tablet->token_hash);
        $this->assertTrue($tablet->usuario->hasRole('EstacaoCozinha'));

        $pareado = $servico->parear($token, '10.0.0.5');
        $this->assertNotNull($pareado->pareado_em);
        $this->assertNull($pareado->token_hash);

        try {
            $servico->parear($token);
            $this->fail('Token reutilizado deveria ser recusado.');
        } catch (ProducaoException $e) {
            $this->assertStringContainsString('já foi usado', $e->getMessage());
        }

        $expira = $servico->gerarToken($tablet->fresh());
        Carbon::setTestNow(now()->addMinutes(11));

        $this->expectExceptionMessage('expirou');
        $servico->parear($expira);
    }

    public function test_auditoria_dos_cadastros_ignora_o_ping_e_nao_grava_o_hash(): void
    {
        $this->actingAs(User::factory()->gerente()->create(['name_first' => 'Gerente']));
        $setor = SetorProducao::factory()->create();
        $setor->update(['tempo_alvo_min' => 20]);
        $tablet = DispositivoCozinha::factory()->create();
        $tablet->update(['ultimo_ping_em' => now()]);
        app(PareamentoDispositivoService::class)->gerarToken($tablet);

        $this->assertSame(['atualizado', 'criado'], $setor->historicos()->pluck('evento')->all());
        $this->assertSame(['de' => 15, 'para' => 20], $setor->historicos()->first()->dados['tempo_alvo_min']);
        $eventosTablet = CozinhaHistorico::where('auditavel_type', $tablet->getMorphClass())->where('auditavel_id', $tablet->id)->pluck('evento')->all();
        $this->assertSame(['criado', 'pareamento_gerado'], $eventosTablet);
        $this->assertStringNotContainsString((string) $tablet->fresh()->token_hash, json_encode(CozinhaHistorico::pluck('dados')));
    }

    public function test_ficha_ja_usada_na_producao_ganha_nova_versao_ao_mudar(): void
    {
        $ficha = FichaTecnica::factory()->create();
        $ficha->update(['tempo_preparo_min' => 14]);
        $this->assertSame(1, $ficha->fresh()->versao, 'Ainda não usada: edita na mesma versão.');

        ProducaoItem::factory()->create(['ficha_tecnica_id' => $ficha->id, 'ficha_versao' => 1]);

        $ficha->update(['tempo_preparo_min' => 16]);
        $this->assertSame(2, $ficha->fresh()->versao);

        FichaTecnicaPasso::factory()->create(['ficha_tecnica_id' => $ficha->id]);
        $this->assertSame(2, $ficha->fresh()->versao, 'Versão 2 ainda não foi usada.');
    }
}
