<?php

namespace Tests\Feature;

use App\Filament\Resources\Prestadors\Pages\ManagePrestadors;
use App\Models\Prestador;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PrestadorCpfCnpjTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create(['name_first' => 'Admin']));
    }

    public function test_pessoa_juridica_salva_cnpj_completo(): void
    {
        Livewire::test(ManagePrestadors::class)
            ->callAction(CreateAction::class, [
                'tipo' => 'pj',
                'categoria' => 'fornecedor',
                'razao_social' => 'Distribuidora LTDA',
                'cpf_cnpj' => '12.345.678/0001-90',
            ])
            ->assertHasNoFormErrors();

        $this->assertSame('12.345.678/0001-90', Prestador::sole()->cpf_cnpj);
    }

    public function test_pessoa_juridica_recusa_cpf(): void
    {
        Livewire::test(ManagePrestadors::class)
            ->callAction(CreateAction::class, [
                'tipo' => 'pj',
                'categoria' => 'fornecedor',
                'razao_social' => 'Distribuidora LTDA',
                'cpf_cnpj' => '123.456.789-01',
            ])
            ->assertHasFormErrors(['cpf_cnpj']);

        $this->assertSame(0, Prestador::count());
    }

    public function test_pessoa_fisica_salva_cpf_e_recusa_cnpj(): void
    {
        Livewire::test(ManagePrestadors::class)
            ->callAction(CreateAction::class, [
                'tipo' => 'pf',
                'categoria' => 'autonomo',
                'nome' => 'João da Silva',
                'cpf_cnpj' => '12.345.678/0001-90',
            ])
            ->assertHasFormErrors(['cpf_cnpj']);

        Livewire::test(ManagePrestadors::class)
            ->callAction(CreateAction::class, [
                'tipo' => 'pf',
                'categoria' => 'autonomo',
                'nome' => 'João da Silva',
                'cpf_cnpj' => '123.456.789-01',
            ])
            ->assertHasNoFormErrors();

        $this->assertSame('123.456.789-01', Prestador::sole()->cpf_cnpj);
    }

    public function test_edicao_aceita_cnpj_importado_so_com_digitos(): void
    {
        $prestador = Prestador::create([
            'tipo' => 'pj',
            'categoria' => 'fornecedor',
            'razao_social' => 'Fornecedor da NF-e',
            'cpf_cnpj' => '12345678000190',
        ]);

        Livewire::test(ManagePrestadors::class)
            ->callAction(TestAction::make(EditAction::class)->table($prestador), [
                'nome_fantasia' => 'Fornecedor',
            ])
            ->assertHasNoFormErrors();

        $this->assertSame('Fornecedor', $prestador->fresh()->nome_fantasia);
    }
}
