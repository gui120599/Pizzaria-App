<?php

namespace Tests\Unit;

use App\Enums\PrazoRecebimentoMaquininhaEnum;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PrazoRecebimentoMaquininhaEnumTest extends TestCase
{
    /** @return array<string, array{0: PrazoRecebimentoMaquininhaEnum, 1: string, 2: string}> */
    public static function vendas(): array
    {
        return [
            'na hora, mesmo de madrugada' => [PrazoRecebimentoMaquininhaEnum::NaHora, '2026-10-10 23:59:00', '2026-10-10'],
            'mesmo dia, venda às 22h em ponto' => [PrazoRecebimentoMaquininhaEnum::MesmoDia, '2026-10-10 22:00:00', '2026-10-10'],
            'mesmo dia, venda depois das 22h' => [PrazoRecebimentoMaquininhaEnum::MesmoDia, '2026-10-10 22:00:01', '2026-10-11'],
            'próximo dia útil, venda na quarta' => [PrazoRecebimentoMaquininhaEnum::ProximoDiaUtil, '2026-10-07 20:00:00', '2026-10-08'],
            'próximo dia útil pula fim de semana e feriado' => [PrazoRecebimentoMaquininhaEnum::ProximoDiaUtil, '2026-10-09 20:00:00', '2026-10-13'],
            'próximo dia útil pula o Carnaval' => [PrazoRecebimentoMaquininhaEnum::ProximoDiaUtil, '2027-02-05 20:00:00', '2027-02-10'],
            'próximo dia útil pula a Sexta-feira Santa' => [PrazoRecebimentoMaquininhaEnum::ProximoDiaUtil, '2027-03-25 20:00:00', '2027-03-29'],
        ];
    }

    #[DataProvider('vendas')]
    public function test_data_prevista_de_recebimento(PrazoRecebimentoMaquininhaEnum $prazo, string $venda, string $esperada): void
    {
        $this->assertSame($esperada, $prazo->dataPrevista(Carbon::parse($venda))->format('Y-m-d'));
    }
}
