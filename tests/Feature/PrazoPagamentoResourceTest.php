<?php

namespace Tests\Feature;

use App\Filament\Resources\PrazosPagamento\Pages\CreatePrazoPagamento;
use App\Filament\Resources\PrazosPagamento\Pages\EditPrazoPagamento;
use App\Models\PrazoPagamento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PrazoPagamentoResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
    }

    public function test_cria_prazo_de_pagamento_com_parcelas_via_repeater(): void
    {
        Livewire::test(CreatePrazoPagamento::class)
            ->fillForm([
                'prazo_pagamento_nome' => '30/60/90',
                'prazo_pagamento_ativo' => true,
                'parcelas' => [
                    ['parcela_dias' => 30, 'parcela_percentual' => 33.34],
                    ['parcela_dias' => 60, 'parcela_percentual' => 33.33],
                    ['parcela_dias' => 90, 'parcela_percentual' => 33.33],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $prazo = PrazoPagamento::where('prazo_pagamento_nome', '30/60/90')->firstOrFail();
        $parcelas = $prazo->parcelas;

        $this->assertCount(3, $parcelas);
        $this->assertSame([1, 2, 3], $parcelas->pluck('parcela_numero')->all());
        $this->assertSame([30, 60, 90], $parcelas->pluck('parcela_dias')->all());
    }

    public function test_percentual_da_parcela_pode_ser_zero(): void
    {
        Livewire::test(CreatePrazoPagamento::class)
            ->fillForm([
                'prazo_pagamento_nome' => 'Entrada + 2x',
                'parcelas' => [
                    ['parcela_dias' => 0, 'parcela_percentual' => 0],
                    ['parcela_dias' => 30, 'parcela_percentual' => 50],
                    ['parcela_dias' => 60, 'parcela_percentual' => 50],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $prazo = PrazoPagamento::where('prazo_pagamento_nome', 'Entrada + 2x')->firstOrFail();
        $this->assertEqualsWithDelta(0.0, (float) $prazo->parcelas()->where('parcela_numero', 1)->first()->parcela_percentual, 0.0001);
    }

    public function test_bloqueia_prazo_com_soma_de_percentuais_zero(): void
    {
        Livewire::test(CreatePrazoPagamento::class)
            ->fillForm([
                'prazo_pagamento_nome' => '7/14/21 sem percentual',
                'parcelas' => [
                    ['parcela_dias' => 7, 'parcela_percentual' => 0],
                    ['parcela_dias' => 14, 'parcela_percentual' => 0],
                    ['parcela_dias' => 21, 'parcela_percentual' => 0],
                ],
            ])
            ->call('create')
            ->assertHasFormErrors(['parcelas']);

        $this->assertDatabaseMissing('prazos_pagamento', ['prazo_pagamento_nome' => '7/14/21 sem percentual']);
    }

    public function test_exige_nome_e_ao_menos_uma_parcela(): void
    {
        Livewire::test(CreatePrazoPagamento::class)
            ->fillForm([
                'prazo_pagamento_nome' => '',
                'parcelas' => [],
            ])
            ->call('create')
            ->assertHasFormErrors(['prazo_pagamento_nome', 'parcelas']);
    }

    public function test_edita_parcelas_de_um_prazo_existente(): void
    {
        $prazo = PrazoPagamento::create(['prazo_pagamento_nome' => '7/15/20 dias']);
        $prazo->parcelas()->createMany([
            ['parcela_numero' => 1, 'parcela_dias' => 7, 'parcela_percentual' => 33.34],
            ['parcela_numero' => 2, 'parcela_dias' => 15, 'parcela_percentual' => 33.33],
            ['parcela_numero' => 3, 'parcela_dias' => 20, 'parcela_percentual' => 33.33],
        ]);

        Livewire::test(EditPrazoPagamento::class, ['record' => $prazo->getKey()])
            ->fillForm([
                'parcelas' => [
                    ['parcela_dias' => 7, 'parcela_percentual' => 40],
                    ['parcela_dias' => 15, 'parcela_percentual' => 30],
                    ['parcela_dias' => 20, 'parcela_percentual' => 30],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $prazo->refresh();
        $this->assertSame([40.0, 30.0, 30.0], $prazo->parcelas->pluck('parcela_percentual')->map(fn ($v) => (float) $v)->all());
    }

    public function test_bloqueia_editar_prazo_zerando_todos_os_percentuais(): void
    {
        $prazo = PrazoPagamento::create(['prazo_pagamento_nome' => '7/14/21 dias']);
        $prazo->parcelas()->createMany([
            ['parcela_numero' => 1, 'parcela_dias' => 7, 'parcela_percentual' => 33.34],
            ['parcela_numero' => 2, 'parcela_dias' => 14, 'parcela_percentual' => 33.33],
            ['parcela_numero' => 3, 'parcela_dias' => 21, 'parcela_percentual' => 33.33],
        ]);

        Livewire::test(EditPrazoPagamento::class, ['record' => $prazo->getKey()])
            ->fillForm([
                'parcelas' => [
                    ['parcela_dias' => 7, 'parcela_percentual' => 0],
                    ['parcela_dias' => 14, 'parcela_percentual' => 0],
                    ['parcela_dias' => 21, 'parcela_percentual' => 0],
                ],
            ])
            ->call('save')
            ->assertHasFormErrors(['parcelas']);

        // Não deve ter salvo os percentuais zerados por cima dos originais.
        $prazo->refresh();
        $this->assertEqualsWithDelta(33.34, (float) $prazo->parcelas()->where('parcela_numero', 1)->first()->parcela_percentual, 0.001);
    }
}
