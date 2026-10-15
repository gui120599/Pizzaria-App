<?php

namespace Tests\Feature;

use App\Enums\Ponto\AbrangenciaFeriado;
use App\Support\FeriadosNacionais;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FeriadosNacionaisTest extends TestCase
{
    /** @return array<string, array{int, string}> */
    public static function pascoas(): array
    {
        return [
            '2019' => [2019, '2019-04-21'],
            '2026' => [2026, '2026-04-05'],
            '2027' => [2027, '2027-03-28'],
            '2038 (mais tardia do século)' => [2038, '2038-04-25'],
        ];
    }

    #[DataProvider('pascoas')]
    public function test_calcula_a_pascoa(int $ano, string $esperada): void
    {
        $this->assertSame($esperada, FeriadosNacionais::pascoa($ano)->toDateString());
    }

    public function test_feriados_moveis_de_2026_e_suas_abrangencias(): void
    {
        $moveis = collect(FeriadosNacionais::doAno(2026))
            ->reject(fn (array $feriado): bool => $feriado['recorrente_anual'])
            ->mapWithKeys(fn (array $feriado): array => [$feriado['nome'] => [$feriado['data']->toDateString(), $feriado['abrangencia']]])
            ->all();

        $this->assertSame([
            'Carnaval (segunda-feira)' => ['2026-02-16', AbrangenciaFeriado::Facultativo],
            'Carnaval (terça-feira)' => ['2026-02-17', AbrangenciaFeriado::Facultativo],
            'Paixão de Cristo' => ['2026-04-03', AbrangenciaFeriado::Nacional],
            'Corpus Christi' => ['2026-06-04', AbrangenciaFeriado::Facultativo],
        ], $moveis);
    }

    public function test_inclui_a_consciencia_negra_como_feriado_nacional_fixo(): void
    {
        $fixo = collect(FeriadosNacionais::doAno(2026))->firstWhere('nome', 'Dia Nacional de Zumbi e da Consciência Negra');

        $this->assertSame('2026-11-20', $fixo['data']->toDateString());
        $this->assertTrue($fixo['recorrente_anual']);
    }
}
