<?php

namespace Tests\Feature;

use App\Enums\Ponto\MotivoAjuste;
use App\Enums\Ponto\OrigemAjuste;
use App\Enums\Ponto\SituacaoDia;
use App\Enums\Ponto\StatusAjuste;
use App\Enums\Ponto\StatusPeriodo;
use App\Enums\Ponto\TipoAjuste;
use App\Exceptions\PontoException;
use App\Models\Colaborador;
use App\Models\Escala;
use App\Models\PontoAjuste;
use App\Models\PontoDia;
use App\Models\PontoMarcacao;
use App\Models\PontoPeriodo;
use App\Models\User;
use App\Notifications\Ponto\PontoCorrigidoNotification;
use App\Services\Ponto\CorrecaoPontoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CorrecaoPontoServiceTest extends TestCase
{
    use RefreshDatabase;

    private const JUSTIFICATIVA = 'Colaborador bateu na hora errada, conferido na câmera.';

    public function test_gerente_corrige_horario_sem_tocar_na_marcacao_original(): void
    {
        $this->travelTo('2026-10-08 12:00:00');
        $gerente = $this->gerente();
        $colaborador = $this->colaborador();
        $entrada = PontoMarcacao::factory()->for($colaborador)->em('2026-10-07 17:30')->create();
        $errada = PontoMarcacao::factory()->for($colaborador)->em('2026-10-07 19:47')->create();
        Notification::fake();

        $ajustes = $this->correcao()->corrigirHorario($errada, Carbon::parse('2026-10-08 00:30'), MotivoAjuste::HorarioErrado, self::JUSTIFICATIVA, $gerente);

        $this->assertSame('2026-10-07 19:47:00', $errada->fresh()->registrada_em->format('Y-m-d H:i:s'));
        $this->assertSame(2, PontoMarcacao::count());
        $this->assertSame([TipoAjuste::Desconsideracao, TipoAjuste::Inclusao], $ajustes->pluck('tipo')->all());
        $this->assertCount(1, $ajustes->pluck('lote_uuid')->unique());
        $this->assertTrue($ajustes->every(fn (PontoAjuste $a): bool => $a->status === StatusAjuste::Aprovado && $a->origem === OrigemAjuste::Gestor));
        $this->assertSame(441, PontoDia::sole()->minutos_trabalhados);
        $this->assertModelExists($entrada);
        Notification::assertSentTo($colaborador->user, PontoCorrigidoNotification::class, fn (PontoCorrigidoNotification $n): bool => $n->antes === ['17:30', '19:47'] && $n->depois === ['17:30', '00:30']);
    }

    public function test_gerente_nao_corrige_o_proprio_ponto(): void
    {
        $this->travelTo('2026-10-08 12:00:00');
        $gerente = $this->gerente();
        $colaborador = Colaborador::factory()->for($gerente, 'user')->comEscala()->create();

        try {
            $this->correcao()->incluirMarcacao($colaborador, Carbon::parse('2026-10-07 17:30'), MotivoAjuste::Esquecimento, self::JUSTIFICATIVA, $gerente);
            $this->fail('O gerente corrigiu o próprio ponto.');
        } catch (PontoException) {
        }

        $this->assertSame(0, PontoAjuste::count());
    }

    public function test_admin_nao_corrige_o_proprio_ponto_salvo_configuracao(): void
    {
        $this->travelTo('2026-10-08 12:00:00');
        $admin = User::factory()->admin()->create(['name_first' => 'Admin']);
        $colaborador = Colaborador::factory()->for($admin, 'user')->comEscala()->create();

        try {
            $this->correcao()->incluirMarcacao($colaborador, Carbon::parse('2026-10-07 17:30'), MotivoAjuste::Esquecimento, self::JUSTIFICATIVA, $admin);
            $this->fail('O admin corrigiu o próprio ponto sem a configuração.');
        } catch (PontoException) {
        }

        config(['ponto.admin_corrige_proprio' => true]);
        $this->correcao()->incluirMarcacao($colaborador, Carbon::parse('2026-10-07 17:30'), MotivoAjuste::Esquecimento, self::JUSTIFICATIVA, $admin);

        $this->assertSame(1, PontoAjuste::count());
    }

    public function test_usuario_sem_permissao_nao_corrige_ponto(): void
    {
        $this->travelTo('2026-10-08 12:00:00');
        $garcom = User::factory()->garcom()->create(['name_first' => 'Garçom']);
        $colaborador = $this->colaborador();

        $this->expectException(PontoException::class);

        $this->correcao()->abonar($colaborador, Carbon::parse('2026-10-07'), null, MotivoAjuste::Atestado, self::JUSTIFICATIVA, $garcom);
    }

    public function test_lancar_dia_completo_num_dia_sem_marcacao_gera_dia_normal(): void
    {
        $this->travelTo('2026-10-08 12:00:00');
        $colaborador = $this->colaborador();

        $ajustes = $this->correcao()->lancarDia($colaborador, Carbon::parse('2026-10-07'), ['17:30', '20:00', '21:00', '00:30'], MotivoAjuste::SemAcessoAoApp, self::JUSTIFICATIVA, $this->gerente());

        $dia = PontoDia::sole();
        $this->assertCount(4, $ajustes);
        $this->assertSame('2026-10-08 00:30', $ajustes->last()->data_hora->format('Y-m-d H:i'));
        $this->assertSame(SituacaoDia::Normal, $dia->situacao);
        $this->assertSame(381, $dia->minutos_trabalhados);
        $this->assertSame(0, $dia->minutos_extra_50);
        $this->assertSame(0, $dia->minutos_atraso);
    }

    public function test_lancar_dia_pode_desconsiderar_as_batidas_existentes_no_mesmo_lote(): void
    {
        $this->travelTo('2026-10-08 12:00:00');
        $colaborador = $this->colaborador();
        PontoMarcacao::factory()->for($colaborador)->em('2026-10-07 23:59')->create();

        $ajustes = $this->correcao()->lancarDia($colaborador, Carbon::parse('2026-10-07'), ['17:30', '00:30'], MotivoAjuste::MarcouPorOutro, self::JUSTIFICATIVA, $this->gerente(), desconsiderarExistentes: true);

        $this->assertSame([TipoAjuste::Desconsideracao, TipoAjuste::Inclusao, TipoAjuste::Inclusao], $ajustes->pluck('tipo')->all());
        $this->assertSame(441, PontoDia::sole()->minutos_trabalhados);
    }

    public function test_desfazer_correcao_volta_a_apuracao_e_mantem_os_ajustes_como_desfeitos(): void
    {
        $this->travelTo('2026-10-08 12:00:00');
        $gerente = $this->gerente();
        $colaborador = $this->colaborador();
        PontoMarcacao::factory()->for($colaborador)->em('2026-10-07 17:30')->create();
        $ajuste = $this->correcao()->incluirMarcacao($colaborador, Carbon::parse('2026-10-08 00:30'), MotivoAjuste::Esquecimento, self::JUSTIFICATIVA, $gerente);

        $this->correcao()->desfazerCorrecao($ajuste->lote_uuid, 'Inclusão lançada no colaborador errado.', $gerente);

        $ajuste->refresh();
        $this->assertSame(StatusAjuste::Desfeito, $ajuste->status);
        $this->assertSame($gerente->id, $ajuste->desfeito_por);
        $this->assertSame('Inclusão lançada no colaborador errado.', $ajuste->justificativa_desfazer);
        $this->assertSame(SituacaoDia::Incompleto, PontoDia::sole()->situacao);
        $this->assertSame(1, PontoAjuste::count());
    }

    /** @return array<string, array{StatusPeriodo}> */
    public static function periodosTravados(): array
    {
        return [
            'em conferência' => [StatusPeriodo::EmConferencia],
            'fechado' => [StatusPeriodo::Fechado],
        ];
    }

    #[DataProvider('periodosTravados')]
    public function test_correcao_e_bloqueada_em_periodo_travado(StatusPeriodo $status): void
    {
        $this->travelTo('2026-10-12 12:00:00');
        $colaborador = $this->colaborador();
        PontoPeriodo::factory()->entre('2026-10-05', '2026-10-11')->status($status)->create();

        try {
            $this->correcao()->abonar($colaborador, Carbon::parse('2026-10-07'), 30, MotivoAjuste::DeclaracaoComparecimento, self::JUSTIFICATIVA, $this->gerente());
            $this->fail('Correção aceita em período travado.');
        } catch (PontoException $e) {
            $this->assertStringContainsString('Reabra o período', $e->getMessage());
        }

        $this->assertSame(0, PontoAjuste::count());
    }

    public function test_justificativa_curta_e_recusada(): void
    {
        $this->travelTo('2026-10-08 12:00:00');

        $this->expectException(PontoException::class);

        $this->correcao()->abonar($this->colaborador(), Carbon::parse('2026-10-07'), null, MotivoAjuste::Atestado, 'atestado', $this->gerente());
    }

    public function test_inclusao_no_futuro_e_recusada(): void
    {
        $this->travelTo('2026-10-07 20:00:00');

        $this->expectException(PontoException::class);

        $this->correcao()->incluirMarcacao($this->colaborador(), Carbon::parse('2026-10-07 23:00'), MotivoAjuste::Esquecimento, self::JUSTIFICATIVA, $this->gerente());
    }

    public function test_inclusao_a_menos_de_1_minuto_de_outra_marcacao_e_recusada(): void
    {
        $this->travelTo('2026-10-08 12:00:00');
        $colaborador = $this->colaborador();
        PontoMarcacao::factory()->for($colaborador)->em('2026-10-07 17:30:20')->create();

        $this->expectException(PontoException::class);

        $this->correcao()->incluirMarcacao($colaborador, Carbon::parse('2026-10-07 17:30'), MotivoAjuste::Esquecimento, self::JUSTIFICATIVA, $this->gerente());
    }

    public function test_marcacao_ja_desconsiderada_nao_e_corrigida_de_novo(): void
    {
        $this->travelTo('2026-10-08 12:00:00');
        $gerente = $this->gerente();
        $marcacao = PontoMarcacao::factory()->for($this->colaborador())->em('2026-10-07 19:47')->create();
        $this->correcao()->desconsiderar($marcacao, MotivoAjuste::MarcacaoDuplicada, self::JUSTIFICATIVA, $gerente);

        $this->expectException(PontoException::class);

        $this->correcao()->corrigirHorario($marcacao, Carbon::parse('2026-10-07 18:00'), MotivoAjuste::HorarioErrado, self::JUSTIFICATIVA, $gerente);
    }

    private function gerente(): User
    {
        return User::factory()->gerente()->create(['name_first' => 'Gerente']);
    }

    private function colaborador(): Colaborador
    {
        return Colaborador::factory()->comUsuario()->comEscala(Escala::factory()->noitePizzaria()->create())->create();
    }

    private function correcao(): CorrecaoPontoService
    {
        return app(CorrecaoPontoService::class);
    }
}
