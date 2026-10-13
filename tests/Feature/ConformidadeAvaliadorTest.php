<?php

namespace Tests\Feature;

use App\Enums\Checklist\TipoResposta;
use App\Models\ChecklistAnexo;
use App\Models\ChecklistExecucaoItem;
use App\Services\Checklist\ConformidadeAvaliador;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ConformidadeAvaliadorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: TipoResposta, 1: array<string, mixed>, 2: array<string, mixed>, 3: ?bool}>
     */
    public static function respostas(): array
    {
        return [
            'check feito' => [TipoResposta::Check, [], ['valor_booleano' => true], true],
            'check não feito' => [TipoResposta::Check, [], ['valor_booleano' => false], false],
            'sim/não igual ao esperado' => [TipoResposta::SimNao, ['resposta_esperada' => 'sim'], ['valor_booleano' => true], true],
            'sim/não diferente do esperado' => [TipoResposta::SimNao, ['resposta_esperada' => 'sim'], ['valor_booleano' => false], false],
            'sim/não sem esperado' => [TipoResposta::SimNao, ['resposta_esperada' => null], ['valor_booleano' => false], true],
            'número no limite mínimo' => [TipoResposta::Numero, ['valor_minimo' => '40.00', 'valor_maximo' => null], ['valor_numero' => '40.00'], true],
            'número abaixo do mínimo' => [TipoResposta::Numero, ['valor_minimo' => '40.00', 'valor_maximo' => null], ['valor_numero' => '39.99'], false],
            'temperatura dentro da faixa' => [TipoResposta::Temperatura, ['valor_minimo' => '-25.00', 'valor_maximo' => '-18.00'], ['valor_numero' => '-18.00'], true],
            'temperatura acima do máximo' => [TipoResposta::Temperatura, ['valor_minimo' => '-25.00', 'valor_maximo' => '-18.00'], ['valor_numero' => '-17.90'], false],
            'temperatura sem limites' => [TipoResposta::Temperatura, ['valor_minimo' => null, 'valor_maximo' => null], ['valor_numero' => '99.00'], true],
            'seleção conforme' => [TipoResposta::Selecao, self::opcoes(), ['valor_opcao' => 'ok'], true],
            'seleção não conforme' => [TipoResposta::Selecao, self::opcoes(), ['valor_opcao' => 'sem_sinal'], false],
            'texto preenchido' => [TipoResposta::Texto, [], ['valor_texto' => 'Faltou orégano.'], true],
        ];
    }

    #[DataProvider('respostas')]
    public function test_avalia_a_conformidade_pela_regra_do_tipo(TipoResposta $tipo, array $regras, array $resposta, ?bool $esperado): void
    {
        $item = ChecklistExecucaoItem::factory()->make([
            'tipo_resposta' => $tipo,
            'config' => [...ChecklistExecucaoItem::factory()->make()->config, ...$regras],
            ...$resposta,
            'respondido_em' => now(),
        ]);

        $this->assertSame($esperado, app(ConformidadeAvaliador::class)->avaliar($item));
    }

    public function test_item_sem_resposta_nao_e_avaliado(): void
    {
        $item = ChecklistExecucaoItem::factory()->make(['tipo_resposta' => TipoResposta::Check]);

        $this->assertNull(app(ConformidadeAvaliador::class)->avaliar($item));
    }

    public function test_item_de_foto_e_conforme_quando_tem_anexo(): void
    {
        $semFoto = ChecklistExecucaoItem::factory()->create(['tipo_resposta' => TipoResposta::Foto]);
        $comFoto = ChecklistExecucaoItem::factory()->create(['tipo_resposta' => TipoResposta::Foto]);
        ChecklistAnexo::factory()->for($comFoto, 'execucaoItem')->create();

        $this->assertNull(app(ConformidadeAvaliador::class)->avaliar($semFoto));
        $this->assertTrue(app(ConformidadeAvaliador::class)->avaliar($comFoto));
    }

    /** @return array{opcoes: list<array{valor: string, rotulo: string, conforme: bool}>} */
    private static function opcoes(): array
    {
        return ['opcoes' => [
            ['valor' => 'ok', 'rotulo' => 'OK', 'conforme' => true],
            ['valor' => 'sem_sinal', 'rotulo' => 'Sem sinal', 'conforme' => false],
        ]];
    }
}
