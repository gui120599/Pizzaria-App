<?php

namespace Tests\Unit;

use App\Enums\StatusPedidoEnum;
use App\Enums\UrgenciaPedidoEnum;
use App\Models\Pedido;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UrgenciaPedidoEnumTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-30 20:00:00');

        config(['pizzaria.pedidos.sla' => [
            'PREPARANDO' => ['atencao' => 20, 'atrasado' => 35],
        ]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Pedido em PREPARANDO há $minutos, sem tocar no banco. */
    private function pedidoPreparandoHa(int $minutos): Pedido
    {
        return new Pedido([
            'pedido_status' => StatusPedidoEnum::PREPARANDO->value,
            'pedido_datahora_preparo' => Carbon::now()->copy()->subMinutes($minutos),
        ]);
    }

    public function test_dentro_do_limite_fica_no_prazo(): void
    {
        $this->assertSame(
            UrgenciaPedidoEnum::NORMAL,
            UrgenciaPedidoEnum::paraPedido($this->pedidoPreparandoHa(19), StatusPedidoEnum::PREPARANDO),
        );
    }

    public function test_no_limite_de_atencao_acusa_atencao(): void
    {
        $this->assertSame(
            UrgenciaPedidoEnum::ATENCAO,
            UrgenciaPedidoEnum::paraPedido($this->pedidoPreparandoHa(20), StatusPedidoEnum::PREPARANDO),
        );
    }

    public function test_no_limite_de_atraso_acusa_atrasado(): void
    {
        $this->assertSame(
            UrgenciaPedidoEnum::ATRASADO,
            UrgenciaPedidoEnum::paraPedido($this->pedidoPreparandoHa(35), StatusPedidoEnum::PREPARANDO),
        );
    }

    public function test_relogio_conta_do_status_atual_nao_da_abertura(): void
    {
        // Aberto há 3 horas, mas entrou em PREPARANDO agora: está no prazo.
        $pedido = new Pedido([
            'pedido_status' => StatusPedidoEnum::PREPARANDO->value,
            'pedido_datahora_abertura' => Carbon::now()->copy()->subHours(3),
            'pedido_datahora_preparo' => Carbon::now()->copy()->subMinutes(2),
        ]);

        $this->assertSame(
            UrgenciaPedidoEnum::NORMAL,
            UrgenciaPedidoEnum::paraPedido($pedido, StatusPedidoEnum::PREPARANDO),
        );

        $this->assertSame(2, UrgenciaPedidoEnum::minutosNoStatus($pedido, StatusPedidoEnum::PREPARANDO));
    }

    public function test_status_sem_limite_configurado_nunca_acusa_urgencia(): void
    {
        $pedido = new Pedido([
            'pedido_status' => StatusPedidoEnum::PRONTO->value,
            'pedido_datahora_pronto' => Carbon::now()->copy()->subHours(5),
        ]);

        $this->assertSame(
            UrgenciaPedidoEnum::NORMAL,
            UrgenciaPedidoEnum::paraPedido($pedido, StatusPedidoEnum::PRONTO),
        );
    }

    public function test_sem_datahora_de_referencia_nao_calcula_urgencia(): void
    {
        $pedido = new Pedido(['pedido_status' => StatusPedidoEnum::PREPARANDO->value]);

        $this->assertNull(UrgenciaPedidoEnum::minutosNoStatus($pedido, StatusPedidoEnum::PREPARANDO));
        $this->assertSame(
            UrgenciaPedidoEnum::NORMAL,
            UrgenciaPedidoEnum::paraPedido($pedido, StatusPedidoEnum::PREPARANDO),
        );
    }
}
