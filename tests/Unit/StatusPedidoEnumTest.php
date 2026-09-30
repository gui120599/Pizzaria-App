<?php

namespace Tests\Unit;

use App\Enums\StatusPedidoEnum;
use App\Models\Pedido;
use Tests\TestCase;

class StatusPedidoEnumTest extends TestCase
{
    public function test_em_transporte_usa_o_literal_com_espaco_do_banco(): void
    {
        // O enum do MySQL grava 'EM TRANSPORTE'. Trocar por underscore não
        // levanta erro — só faz a coluna do painel vir sempre vazia.
        $this->assertSame('EM TRANSPORTE', StatusPedidoEnum::EM_TRANSPORTE->value);
    }

    public function test_avanca_um_degrau_por_vez_ate_entregue(): void
    {
        $this->assertSame(StatusPedidoEnum::ABERTO, StatusPedidoEnum::INICIADO->proximo(true));
        $this->assertSame(StatusPedidoEnum::PREPARANDO, StatusPedidoEnum::ABERTO->proximo(true));
        $this->assertSame(StatusPedidoEnum::PRONTO, StatusPedidoEnum::PREPARANDO->proximo(true));
        $this->assertSame(StatusPedidoEnum::ENTREGUE, StatusPedidoEnum::EM_TRANSPORTE->proximo(true));
    }

    public function test_status_terminais_nao_tem_proximo(): void
    {
        $this->assertNull(StatusPedidoEnum::ENTREGUE->proximo(true));
        $this->assertNull(StatusPedidoEnum::FINALIZADO->proximo(true));
        $this->assertNull(StatusPedidoEnum::CANCELADO->proximo(true));
    }

    public function test_de_pronto_o_fluxo_bifurca_conforme_o_pedido_exigir_entrega(): void
    {
        $this->assertSame(
            StatusPedidoEnum::EM_TRANSPORTE,
            StatusPedidoEnum::PRONTO->proximo(exigeEntrega: true),
        );

        // Pedido de mesa/balcão não passa por "em transporte".
        $this->assertSame(
            StatusPedidoEnum::ENTREGUE,
            StatusPedidoEnum::PRONTO->proximo(exigeEntrega: false),
        );
    }

    public function test_config_desligada_remove_o_estagio_de_transporte_ate_para_delivery(): void
    {
        config(['pizzaria.pedidos.usa_estagio_transporte' => false]);

        $this->assertSame(
            StatusPedidoEnum::ENTREGUE,
            StatusPedidoEnum::PRONTO->proximo(exigeEntrega: true),
        );

        $this->assertNotContains(StatusPedidoEnum::EM_TRANSPORTE->value, StatusPedidoEnum::valoresKanban());
    }

    public function test_colunas_do_kanban_seguem_a_ordem_do_fluxo(): void
    {
        config(['pizzaria.pedidos.usa_estagio_transporte' => true]);

        $this->assertSame(
            ['INICIADO', 'ABERTO', 'PREPARANDO', 'PRONTO', 'EM TRANSPORTE', 'ENTREGUE'],
            StatusPedidoEnum::valoresKanban(),
        );

        // ENTREGUE tem query própria (janela por data + limite), então fica
        // fora da lista de status "em andamento".
        $this->assertNotContains('ENTREGUE', StatusPedidoEnum::valoresEmAndamento());
    }

    public function test_recusa_pulo_de_etapa_e_retrocesso(): void
    {
        $this->assertFalse(StatusPedidoEnum::ABERTO->podeAvancarPara(StatusPedidoEnum::PRONTO, true));
        $this->assertFalse(StatusPedidoEnum::PRONTO->podeAvancarPara(StatusPedidoEnum::PREPARANDO, true));
        $this->assertTrue(StatusPedidoEnum::ABERTO->podeAvancarPara(StatusPedidoEnum::PREPARANDO, true));
    }

    public function test_status_terminais_nao_podem_ser_cancelados(): void
    {
        $this->assertFalse(StatusPedidoEnum::ENTREGUE->podeSerCancelado());
        $this->assertFalse(StatusPedidoEnum::FINALIZADO->podeSerCancelado());
        $this->assertFalse(StatusPedidoEnum::CANCELADO->podeSerCancelado());

        $this->assertTrue(StatusPedidoEnum::ABERTO->podeSerCancelado());
        $this->assertTrue(StatusPedidoEnum::EM_TRANSPORTE->podeSerCancelado());
    }

    public function test_edicao_de_conteudo_so_congela_no_cancelamento_mas_pede_permissao_de_pronto_em_diante(): void
    {
        $this->assertFalse(StatusPedidoEnum::CANCELADO->ehEditavelQuantoAoConteudo());
        $this->assertTrue(StatusPedidoEnum::ENTREGUE->ehEditavelQuantoAoConteudo());

        $this->assertFalse(StatusPedidoEnum::PREPARANDO->exigePermissaoAvancadaParaEditar());
        $this->assertTrue(StatusPedidoEnum::PRONTO->exigePermissaoAvancadaParaEditar());
        $this->assertTrue(StatusPedidoEnum::FINALIZADO->exigePermissaoAvancadaParaEditar());
    }

    public function test_campo_datahora_aponta_para_colunas_reais_do_schema(): void
    {
        // 'pedido_datahora_entrega', não '_entregue' — o nome errado passaria
        // silenciosamente como atributo fantasma.
        $this->assertSame('pedido_datahora_entrega', StatusPedidoEnum::ENTREGUE->campoDataHora());
        $this->assertSame('pedido_datahora_preparo', StatusPedidoEnum::PREPARANDO->campoDataHora());
        $this->assertSame('pedido_datahora_transporte', StatusPedidoEnum::EM_TRANSPORTE->campoDataHora());

        $colunas = (new Pedido)->getFillable();

        foreach (StatusPedidoEnum::cases() as $status) {
            $campo = $status->campoDataHora();

            if ($campo !== null) {
                $this->assertContains($campo, $colunas, "{$campo} não está no \$fillable de Pedido");
            }
        }
    }
}
