<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Services\ClienteResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Identificação do cliente pelo celular (ClienteResolverService + chave
 * cliente_celular_normalizado), que é a rotina usada por todos os pontos de
 * atendimento.
 */
class ClienteResolverServiceTest extends TestCase
{
    use RefreshDatabase;

    /** Cadastro de antes da normalização: celular gravado do jeito que veio, chave preenchida pelo backfill. */
    private function clienteAntigo(string $celularGravado, string $nome = 'Maria Antiga'): int
    {
        $id = DB::table('clientes')->insertGetId([
            'cliente_nome' => $nome,
            'cliente_tipo' => 'Física',
            'cliente_celular' => $celularGravado,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('clientes:normalizar-telefones')->assertSuccessful();

        return $id;
    }

    /** @return array<string, array{string}> */
    public static function formatosInformados(): array
    {
        return [
            'com máscara' => ['(62) 98123-4567'],
            'sem máscara' => ['62981234567'],
            'com código do país' => ['+55 62 98123-4567'],
            'com zero antes do DDD' => ['062981234567'],
            'sem o nono dígito' => ['6281234567'],
        ];
    }

    #[DataProvider('formatosInformados')]
    public function test_encontra_cadastro_antigo_sem_nono_digito_e_com_mascara_em_qualquer_formato(string $informado): void
    {
        $id = $this->clienteAntigo('(62) 8123-4567');

        $cliente = app(ClienteResolverService::class)->resolverOuCriar(['nome' => 'Maria', 'celular' => $informado]);

        $this->assertSame($id, $cliente->id);
        $this->assertSame(1, Cliente::count());
        $this->assertSame('62981234567', $cliente->fresh()->cliente_celular);
    }

    public function test_encontra_cadastro_antigo_gravado_com_codigo_do_pais(): void
    {
        $id = $this->clienteAntigo('5562981234567');

        $this->assertSame($id, app(ClienteResolverService::class)->buscarPorCelular('(62) 98123-4567')?->id);
    }

    public function test_numero_novo_cria_cliente_com_celular_canonico_e_chave(): void
    {
        $cliente = app(ClienteResolverService::class)->resolverOuCriar(['nome' => 'João', 'celular' => '+55 (62) 8123-4567']);

        $this->assertDatabaseHas('clientes', [
            'id' => $cliente->id,
            'cliente_nome' => 'João',
            'cliente_celular' => '62981234567',
            'cliente_celular_normalizado' => '62981234567',
        ]);
    }

    public function test_segundo_cadastro_do_mesmo_numero_em_outro_formato_reaproveita_o_primeiro(): void
    {
        $resolver = app(ClienteResolverService::class);

        $primeiro = $resolver->resolverOuCriar(['nome' => 'Ana', 'celular' => '(62) 98123-4567']);
        $segundo = $resolver->resolverOuCriar(['nome' => 'Ana Paula', 'celular' => '5562981234567']);

        $this->assertSame($primeiro->id, $segundo->id);
        $this->assertSame(1, Cliente::count());
        $this->assertSame('Ana Paula', $primeiro->fresh()->cliente_nome);
    }

    /** @return array<string, array{string}> */
    public static function numerosNaoEquivalentes(): array
    {
        return [
            'outro DDD' => ['(64) 98123-4567'],
            'fixo com o mesmo final' => ['(62) 3123-4567'],
            'sem DDD' => ['98123-4567'],
        ];
    }

    #[DataProvider('numerosNaoEquivalentes')]
    public function test_numero_nao_equivalente_cria_outro_cadastro(string $informado): void
    {
        $existente = Cliente::create(['cliente_nome' => 'Maria', 'cliente_tipo' => 'Física', 'cliente_celular' => '62981234567']);

        $novo = app(ClienteResolverService::class)->resolverOuCriar(['nome' => 'Outra pessoa', 'celular' => $informado]);

        $this->assertNotSame($existente->id, $novo->id);
        $this->assertSame('Maria', $existente->fresh()->cliente_nome);
    }

    public function test_nome_livre_de_mesa_nao_e_identificado_pelo_celular(): void
    {
        Cliente::create(['cliente_nome' => 'Mesa 5', 'cliente_tipo' => 'Física', 'cliente_celular' => '62981234567', 'cliente_avulso' => true]);

        $this->assertNull(app(ClienteResolverService::class)->buscarPorCelular('62981234567'));
    }

    public function test_com_cadastros_duplicados_identifica_o_mais_antigo(): void
    {
        $antigo = $this->clienteAntigo('6281234567', 'Primeiro');
        Cliente::create(['cliente_nome' => 'Segundo', 'cliente_tipo' => 'Física', 'cliente_celular' => '62981234567']);

        $this->assertSame($antigo, app(ClienteResolverService::class)->buscarPorCelular('62981234567')?->id);
    }

    public function test_cadastro_editado_grava_o_celular_canonico_e_atualiza_a_chave(): void
    {
        $cliente = Cliente::create(['cliente_nome' => 'Maria', 'cliente_tipo' => 'Física', 'cliente_celular' => '(62) 8123-4567']);

        $this->assertSame('62981234567', $cliente->fresh()->cliente_celular);

        $cliente->update(['cliente_celular' => '98123-4567']);

        $this->assertSame('981234567', $cliente->fresh()->cliente_celular);
        $this->assertSame('981234567', $cliente->fresh()->cliente_celular_normalizado);
    }
}
