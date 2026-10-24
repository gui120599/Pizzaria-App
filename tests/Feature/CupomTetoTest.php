<?php

namespace Tests\Feature;

use App\Enums\OrigemCupomEnum;
use App\Enums\StatusInfluenciadorEnum;
use App\Enums\TipoDescontoEnum;
use App\Exceptions\CupomInvalidoException;
use App\Filament\Influenciador\Resources\Cupoms\Pages\CreateCupom;
use App\Filament\Influenciador\Resources\Cupoms\Pages\EditCupom;
use App\Models\Cliente;
use App\Models\Cupom;
use App\Models\Empresa;
use App\Models\Influenciador;
use App\Models\Pedido;
use App\Services\CupomService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** F1.3: teto do cupom do influenciador, travas depois do uso e dono ativo. */
class CupomTetoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Empresa::create([
            'empresa_razao_social' => 'Pizzaria',
            'empresa_influ_teto_percentual' => 10,
            'empresa_influ_teto_valor_fixo' => 8,
            'empresa_influ_teto_usos_total' => 50,
        ]);
    }

    private function influenciador(): Influenciador
    {
        $influenciador = Influenciador::create([
            'influenciador_nome' => 'Fulano',
            'email' => uniqid().'@teste.com',
            'password' => 'senha-segura',
            'influenciador_valor_comissao' => 5,
        ]);
        $influenciador->forceFill(['influenciador_status' => StatusInfluenciadorEnum::ATIVO])->save();

        return $influenciador;
    }

    /** @param array<string, mixed> $extra */
    private function dadosCupom(Influenciador $dono, array $extra = []): array
    {
        return array_merge([
            'cupom_influenciador_id' => $dono->id,
            'cupom_origem' => OrigemCupomEnum::INFLUENCIADOR->value,
            'cupom_codigo' => 'JOAO10',
            'cupom_tipo_desconto' => TipoDescontoEnum::PERCENTUAL->value,
            'cupom_valor_desconto' => 10,
            'cupom_limite_uso_total' => 50,
        ], $extra);
    }

    private function noPainelDoInfluenciador(Influenciador $influenciador): void
    {
        $this->actingAs($influenciador, 'influenciador');
        Filament::setCurrentPanel(Filament::getPanel('influenciador'));
    }

    public function test_formulario_recusa_desconto_acima_do_teto(): void
    {
        $this->noPainelDoInfluenciador($this->influenciador());

        Livewire::test(CreateCupom::class)
            ->fillForm([
                'cupom_codigo' => 'GRANDE',
                'cupom_tipo_desconto' => TipoDescontoEnum::PERCENTUAL->value,
                'cupom_valor_desconto' => 25,
                'cupom_limite_uso_por_cliente' => 1,
                'cupom_limite_uso_total' => 10,
            ])
            ->call('create')
            ->assertHasFormErrors(['cupom_valor_desconto']);

        $this->assertSame(0, Cupom::count());
    }

    public function test_formulario_aceita_desconto_dentro_do_teto_e_normaliza_o_codigo(): void
    {
        $this->noPainelDoInfluenciador($this->influenciador());

        Livewire::test(CreateCupom::class)
            ->fillForm([
                'cupom_codigo' => 'joao 10',
                'cupom_tipo_desconto' => TipoDescontoEnum::VALOR_FIXO->value,
                'cupom_valor_desconto' => 8,
                'cupom_limite_uso_por_cliente' => 1,
                'cupom_limite_uso_total' => 20,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('JOAO10', Cupom::sole()->cupom_codigo);
    }

    public function test_formulario_exige_limite_de_usos_dentro_do_teto(): void
    {
        $this->noPainelDoInfluenciador($this->influenciador());

        Livewire::test(CreateCupom::class)
            ->fillForm([
                'cupom_codigo' => 'SEMLIMITE',
                'cupom_tipo_desconto' => TipoDescontoEnum::PERCENTUAL->value,
                'cupom_valor_desconto' => 5,
                'cupom_limite_uso_por_cliente' => 1,
                'cupom_limite_uso_total' => 500,
            ])
            ->call('create')
            ->assertHasFormErrors(['cupom_limite_uso_total']);
    }

    public function test_servidor_recusa_cupom_de_influenciador_acima_do_teto_mesmo_sem_formulario(): void
    {
        $dono = $this->influenciador();

        foreach ([
            ['cupom_valor_desconto' => 11],
            ['cupom_tipo_desconto' => TipoDescontoEnum::VALOR_FIXO->value, 'cupom_valor_desconto' => 9],
            ['cupom_limite_uso_total' => 51],
            ['cupom_limite_uso_total' => null],
        ] as $i => $extra) {
            try {
                Cupom::create($this->dadosCupom($dono, ['cupom_codigo' => "X{$i}"] + $extra));
                $this->fail("O cupom {$i} deveria ter sido recusado.");
            } catch (CupomInvalidoException) {
                $this->assertDatabaseMissing('cupons', ['cupom_codigo' => "X{$i}"]);
            }
        }

        Cupom::create($this->dadosCupom($dono));
        $this->assertSame(1, Cupom::count());
    }

    public function test_cupom_interno_nao_tem_teto(): void
    {
        Cupom::create([
            'cupom_origem' => OrigemCupomEnum::INTERNO->value,
            'cupom_codigo' => 'BLACK50',
            'cupom_tipo_desconto' => TipoDescontoEnum::PERCENTUAL->value,
            'cupom_valor_desconto' => 50,
        ]);

        $this->assertSame('50.00', Cupom::sole()->cupom_valor_desconto);
    }

    public function test_tipo_e_valor_ficam_travados_depois_do_primeiro_uso(): void
    {
        $dono = $this->influenciador();
        $cupom = Cupom::create($this->dadosCupom($dono));

        // Antes de qualquer uso o valor ainda pode mudar.
        $cupom->update(['cupom_valor_desconto' => 5]);

        Pedido::create([
            'pedido_cliente_id' => Cliente::create(['cliente_nome' => 'Cliente', 'cliente_tipo' => 'PF'])->id,
            'pedido_cupom_id' => $cupom->id,
            'pedido_valor_total' => 90,
            'pedido_status' => 'PREPARANDO',
        ]);

        try {
            $cupom->fresh()->update(['cupom_valor_desconto' => 8]);
            $this->fail('A alteração do valor depois do uso deveria ser recusada.');
        } catch (CupomInvalidoException) {
            $this->assertSame('5.00', $cupom->fresh()->cupom_valor_desconto);
        }

        // O que não é tipo/valor continua editável.
        $cupom->fresh()->update(['cupom_ativo' => false]);
        $this->assertFalse($cupom->fresh()->cupom_ativo);
    }

    public function test_edicao_no_painel_mostra_valor_travado_depois_do_uso(): void
    {
        $dono = $this->influenciador();
        $cupom = Cupom::create($this->dadosCupom($dono));
        Pedido::create(['pedido_cupom_id' => $cupom->id, 'pedido_valor_total' => 90, 'pedido_status' => 'PREPARANDO']);
        $this->noPainelDoInfluenciador($dono);

        Livewire::test(EditCupom::class, ['record' => $cupom->id])
            ->assertFormFieldIsDisabled('cupom_valor_desconto')
            ->assertFormFieldIsDisabled('cupom_tipo_desconto');
    }

    public function test_cupom_de_influenciador_inativo_e_recusado_pelo_servico(): void
    {
        $dono = $this->influenciador();
        Cupom::create($this->dadosCupom($dono));
        $service = app(CupomService::class);

        $this->assertSame('JOAO10', $service->validar('joao10', 100, null)['cupom']->cupom_codigo);

        $dono->forceFill(['influenciador_status' => StatusInfluenciadorEnum::INATIVO])->save();

        $this->expectException(CupomInvalidoException::class);
        $service->validar('JOAO10', 100, null);
    }
}
