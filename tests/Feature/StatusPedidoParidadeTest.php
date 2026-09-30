<?php

namespace Tests\Feature;

use App\Enums\StatusPedidoEnum;
use App\Observers\PedidoObserver;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\TestCase;

/**
 * Guardas contra divergência silenciosa do vocabulário de status.
 *
 * StatusPedidoEnum duplica, por necessidade, duas listas que já existiam: o
 * enum da coluna `pedidos.pedido_status` no MySQL e
 * PedidoObserver::STATUSES_COM_BAIXA. Se alguém adicionar um status em um lugar
 * e esquecer o outro, nada quebra na hora — o pedido simplesmente desaparece do
 * painel, ou deixa de estornar estoque no cancelamento. Estes testes falham
 * antes disso chegar em produção.
 */
class StatusPedidoParidadeTest extends TestCase
{
    public function test_casos_do_enum_batem_com_o_enum_da_coluna_no_mysql(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('A introspecção do enum da coluna é específica do MySQL.');
        }

        $coluna = DB::select("SHOW COLUMNS FROM pedidos LIKE 'pedido_status'");

        $this->assertNotEmpty($coluna, 'Coluna pedido_status não encontrada.');

        preg_match_all("/'((?:[^']|'')*)'/", $coluna[0]->Type, $matches);

        $doBanco = array_map(fn (string $v) => str_replace("''", "'", $v), $matches[1]);
        $doEnum = array_column(StatusPedidoEnum::cases(), 'value');

        sort($doBanco);
        sort($doEnum);

        $this->assertSame(
            $doBanco,
            $doEnum,
            'StatusPedidoEnum divergiu do enum da coluna pedidos.pedido_status.',
        );
    }

    public function test_consome_estoque_bate_com_statuses_com_baixa_do_observer(): void
    {
        $constante = (new ReflectionClass(PedidoObserver::class))
            ->getConstant('STATUSES_COM_BAIXA');

        $this->assertIsArray($constante, 'PedidoObserver::STATUSES_COM_BAIXA não existe mais — revisar StatusPedidoEnum::consomeEstoque().');

        $doEnum = collect(StatusPedidoEnum::cases())
            ->filter(fn (StatusPedidoEnum $s) => $s->consomeEstoque())
            ->map(fn (StatusPedidoEnum $s) => $s->value)
            ->sort()
            ->values()
            ->all();

        $doObserver = collect($constante)->sort()->values()->all();

        $this->assertSame(
            $doObserver,
            $doEnum,
            'StatusPedidoEnum::consomeEstoque() divergiu de PedidoObserver::STATUSES_COM_BAIXA.',
        );
    }
}
