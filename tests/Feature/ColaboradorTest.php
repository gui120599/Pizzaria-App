<?php

namespace Tests\Feature;

use App\Enums\Ponto\VinculoColaborador;
use App\Enums\PrestadorCategoriaEnum;
use App\Enums\PrestadorTipoEnum;
use App\Models\Colaborador;
use App\Models\ColaboradorEscala;
use App\Models\ColaboradorRemuneracao;
use App\Models\Escala;
use App\Models\Prestador;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ColaboradorTest extends TestCase
{
    use RefreshDatabase;

    public function test_cpf_fica_criptografado_so_com_digitos_e_com_hash_de_busca(): void
    {
        $colaborador = Colaborador::factory()->create(['cpf' => '123.456.789-09']);

        $linha = DB::table('colaboradores')->where('id', $colaborador->id)->first();

        $this->assertStringNotContainsString('12345678909', $linha->cpf);
        $this->assertSame('12345678909', $colaborador->fresh()->cpf);
        $this->assertSame(Colaborador::hashCpf('12345678909'), $linha->cpf_hash);
        $this->assertSame(Colaborador::hashCpf('123.456.789-09'), $linha->cpf_hash);
    }

    public function test_rejeita_cpf_ja_cadastrado_em_outro_formato(): void
    {
        Colaborador::factory()->create(['cpf' => '12345678909']);

        $this->expectException(UniqueConstraintViolationException::class);

        Colaborador::factory()->create(['cpf' => '123.456.789-09']);
    }

    public function test_ativos_exclui_quem_ja_foi_desligado(): void
    {
        $ativo = Colaborador::factory()->create();
        $desligaAmanha = Colaborador::factory()->desligado(now()->addDay()->toDateString())->create();
        Colaborador::factory()->desligado()->create();

        $ids = Colaborador::ativos()->orderBy('id')->pluck('id')->all();

        $this->assertSame([$ativo->id, $desligaAmanha->id], $ids);
    }

    public function test_remuneracao_vigente_segue_a_data(): void
    {
        $colaborador = Colaborador::factory()->create();
        $antiga = ColaboradorRemuneracao::factory()->for($colaborador)->porDiaria(100)->vigencia('2026-01-01', '2026-06-30')->create();
        $atual = ColaboradorRemuneracao::factory()->for($colaborador)->porDiaria(130)->vigencia('2026-07-01')->create();

        $this->assertSame($antiga->id, $colaborador->remuneracaoVigente(Carbon::parse('2026-06-30'))?->id);
        $this->assertSame($atual->id, $colaborador->remuneracaoVigente(Carbon::parse('2026-07-01'))?->id);
        $this->assertNull($colaborador->remuneracaoVigente(Carbon::parse('2025-12-31')));
    }

    public function test_escala_vigente_segue_a_data(): void
    {
        $colaborador = Colaborador::factory()->create();
        $semana = Escala::factory()->create();
        $noite = Escala::factory()->create();
        ColaboradorEscala::factory()->for($colaborador)->for($semana)->vigencia('2026-01-01', '2026-09-30')->create();
        ColaboradorEscala::factory()->for($colaborador)->for($noite)->vigencia('2026-10-01')->create();

        $this->assertSame($semana->id, $colaborador->escalaVigente(Carbon::parse('2026-09-30'))?->id);
        $this->assertSame($noite->id, $colaborador->escalaVigente(Carbon::parse('2026-10-13'))?->id);
    }

    public function test_pin_do_quiosque_fica_com_hash_e_confere(): void
    {
        $colaborador = Colaborador::factory()->comPin('4321')->create();

        $this->assertNotSame('4321', DB::table('colaboradores')->where('id', $colaborador->id)->value('pin_quiosque'));
        $this->assertTrue($colaborador->fresh()->conferePin('4321'));
        $this->assertFalse($colaborador->fresh()->conferePin('1234'));
    }

    public function test_cadastro_cria_o_prestador_espelho_com_os_dados_do_colaborador(): void
    {
        $colaborador = Colaborador::factory()->create(['nome' => 'Ana Souza', 'cpf' => '12345678909', 'chave_pix' => 'ana@exemplo.com']);

        $espelho = Prestador::sole();

        $this->assertSame($colaborador->id, $espelho->colaborador_id);
        $this->assertSame('Ana Souza', $espelho->nome);
        $this->assertSame('123.456.789-09', $espelho->cpf_cnpj);
        $this->assertSame('ana@exemplo.com', $espelho->chave_pix);
        $this->assertSame(PrestadorTipoEnum::PF, $espelho->tipo);
        $this->assertSame(PrestadorCategoriaEnum::AUTONOMO, $espelho->categoria);
    }

    public function test_alteracao_do_colaborador_atualiza_o_mesmo_espelho(): void
    {
        $colaborador = Colaborador::factory()->create();

        $colaborador->update(['nome' => 'Nome Novo', 'vinculo' => VinculoColaborador::Clt]);

        $espelho = Prestador::sole();
        $this->assertSame('Nome Novo', $espelho->nome);
        $this->assertSame(PrestadorCategoriaEnum::FUNCIONARIO, $espelho->categoria);
    }

    public function test_excluir_e_restaurar_colaborador_leva_o_espelho_junto(): void
    {
        $colaborador = Colaborador::factory()->create();

        $colaborador->delete();
        $this->assertSoftDeleted(Prestador::withTrashed()->sole());

        $colaborador->restore();
        $this->assertNotSoftDeleted(Prestador::sole());
    }
}
