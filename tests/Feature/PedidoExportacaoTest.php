<?php

namespace Tests\Feature;

use App\Enums\MotivoCancelamentoEnum;
use App\Filament\Exports\PedidoExporter;
use App\Filament\Resources\Pedidos\Pages\ListPedidos;
use App\Models\Cliente;
use App\Models\Pedido;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Filament\Actions\Exports\Models\Export;
use Filament\Actions\Testing\TestAction;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Exportação da lista de pedidos (ExportAction nativo do Filament): quem pode,
 * o que sai (aba e filtros da tela), onde o arquivo fica e a fila própria.
 */
class PedidoExportacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        Carbon::setTestNow('2026-10-24 12:00:00');
        Storage::fake('local');
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** view_any/view vêm do Shield em produção; o PermissionSeeder só cobre as de negócio. */
    private function usuario(string $role): User
    {
        $user = User::factory()->{$role}()->create(['name_first' => ucfirst($role)]);
        $user->givePermissionTo([Permission::findOrCreate('view_any:pedido'), Permission::findOrCreate('view:pedido')]);

        return $user;
    }

    private function pedido(string $status, array $extra = []): Pedido
    {
        return Pedido::create(array_merge(
            ['pedido_status' => $status, 'pedido_valor_total' => 40.0, 'pedido_datahora_abertura' => now()->subHour()],
            $extra,
        ));
    }

    /** Roda a exportação inline (fila "sync") e devolve o CSV gerado. */
    private function exportar(callable $configurarTela): string
    {
        config(['pizzaria.pedidos.exportacao.conexao' => 'sync']);

        $tela = Livewire::actingAs($this->usuario('gerente'))->test(ListPedidos::class);
        $configurarTela($tela);
        $tela->callAction(TestAction::make('export'), ['format' => 'csv'])->assertHasNoActionErrors();

        $export = Export::query()->latest('id')->firstOrFail();
        $this->assertNotNull($export->completed_at, 'a exportação não terminou');

        // O Filament monta o CSV no download: o cabeçalho e depois os blocos de linhas.
        $disco = Storage::disk('local');
        $diretorio = $export->getFileDirectory();
        $this->assertTrue($disco->exists($diretorio.DIRECTORY_SEPARATOR.'headers.csv'), 'cabeçalho do CSV não foi gerado em '.$diretorio);

        $blocos = collect($disco->files($diretorio))
            ->reject(fn (string $arquivo): bool => str_ends_with($arquivo, 'headers.csv'))
            ->filter(fn (string $arquivo): bool => str_ends_with($arquivo, '.csv'))
            ->sort()
            ->map(fn (string $arquivo): string => $disco->get($arquivo));

        return $disco->get($diretorio.DIRECTORY_SEPARATOR.'headers.csv').$blocos->implode('');
    }

    public function test_exporta_so_a_aba_e_os_filtros_da_tela_com_valores_em_formato_brasileiro(): void
    {
        $cliente = Cliente::create(['cliente_nome' => 'Maria Souza', 'cliente_tipo' => 'PF', 'cliente_celular' => '62998765432']);
        $cancelado = $this->pedido('CANCELADO', [
            'pedido_cliente_id' => $cliente->id,
            'pedido_valor_total' => 1234.5,
            'pedido_motivo_cancelamento' => MotivoCancelamentoEnum::OUTRO->value,
            'pedido_motivo_cancelamento_observacao' => 'Cliente mudou de ideia',
        ]);
        $outroCancelado = $this->pedido('CANCELADO', ['pedido_motivo_cancelamento' => MotivoCancelamentoEnum::DEMORA->value]);
        $aberto = $this->pedido('ABERTO');

        $csv = $this->exportar(fn ($tela) => $tela
            ->set('activeTab', 'cancelados')
            ->filterTable('motivo_cancelamento', MotivoCancelamentoEnum::OUTRO->value));

        $this->assertStringContainsString('Nº;Abertura;Status;Tipo;Origem;Cliente;Celular', $csv);
        $this->assertStringContainsString("{$cancelado->id};", $csv);
        $this->assertStringContainsString('Cancelado', $csv);
        $this->assertStringContainsString('Maria Souza', $csv);
        $this->assertStringContainsString('1234,50', $csv);
        $this->assertStringContainsString('Cliente mudou de ideia', $csv);
        $this->assertStringContainsString('Outro', $csv);
        $this->assertStringNotContainsString("\n{$outroCancelado->id};", $csv);
        $this->assertStringNotContainsString("\n{$aberto->id};", $csv);
    }

    public function test_arquivo_fica_no_disco_privado_e_nunca_no_publico(): void
    {
        $this->pedido('ABERTO');

        $this->exportar(fn ($tela) => $tela->set('activeTab', 'andamento'));

        $export = Export::query()->latest('id')->firstOrFail();
        $this->assertSame('local', $export->file_disk);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_texto_do_cliente_que_a_planilha_leria_como_formula_sai_neutralizado(): void
    {
        $cliente = Cliente::create(['cliente_nome' => '=HYPERLINK("http://x")', 'cliente_tipo' => 'PF']);
        $this->pedido('ABERTO', ['pedido_cliente_id' => $cliente->id]);

        $csv = $this->exportar(fn ($tela) => $tela->set('activeTab', 'andamento'));

        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString(';=HYPERLINK', $csv);
    }

    public function test_so_quem_tem_a_permissao_ve_a_exportacao(): void
    {
        foreach (['gerente', 'admin'] as $papel) {
            Livewire::actingAs($this->usuario($papel))->test(ListPedidos::class)
                ->assertActionVisible(TestAction::make('export'));
        }

        foreach (['atendente', 'caixa'] as $papel) {
            Livewire::actingAs($this->usuario($papel))->test(ListPedidos::class)
                ->assertActionHidden(TestAction::make('export'));
        }
    }

    public function test_a_fila_da_exportacao_e_so_dela_e_o_worker_esta_agendado(): void
    {
        $exporter = new PedidoExporter(
            export: new Export,
            columnMap: [],
            options: [],
        );

        $this->assertSame('database', $exporter->getJobConnection());
        $this->assertSame('exportacoes', $exporter->getJobQueue());
        $this->assertSame('local', $exporter->getFileDisk());

        $evento = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'queue:work'));

        $this->assertNotNull($evento, 'o worker da fila de exportação não está agendado');
        $this->assertStringContainsString('--queue=exportacoes', $evento->command);
        $this->assertStringContainsString('--stop-when-empty', $evento->command);
        $this->assertSame('* * * * *', $evento->expression);
        // A fila padrão (NFC-e automática) não é consumida por este worker.
        $this->assertStringNotContainsString('--queue=default', $evento->command);
    }
}
