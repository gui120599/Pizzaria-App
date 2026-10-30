<?php

namespace Tests\Feature;

use App\Models\OpcoesEntregas;
use App\Models\Pedido;
use App\Support\Pedido\EtapasAcompanhamento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EtapasAcompanhamentoTest extends TestCase
{
    use RefreshDatabase;

    private function pedido(string $status, bool $requerEndereco, array $extra = []): Pedido
    {
        $opcao = OpcoesEntregas::create([
            'opcaoentrega_nome' => 'Opção',
            'opcaoentrega_requer_endereco' => $requerEndereco,
            'opcaoentrega_valor_frete' => 0,
            'opcaoentrega_min_valor_frete' => 0,
        ]);

        return Pedido::create(array_merge([
            'pedido_status' => $status,
            'pedido_opcaoentrega_id' => $opcao->id,
        ], $extra));
    }

    /** @return list<string> */
    private function rotulos(Pedido $pedido): array
    {
        return array_column((new EtapasAcompanhamento($pedido))->etapas(), 'rotulo');
    }

    public function test_retirada_nao_tem_etapa_de_entrega(): void
    {
        $this->assertSame(
            ['Pedido recebido', 'Confirmado', 'Em preparo', 'Pronto para retirar', 'Retirado'],
            $this->rotulos($this->pedido('ABERTO', false)),
        );
    }

    public function test_delivery_com_estagio_de_transporte(): void
    {
        config(['pizzaria.pedidos.usa_estagio_transporte' => true]);

        $this->assertSame(
            ['Pedido recebido', 'Confirmado', 'Em preparo', 'Pronto', 'Saiu para entrega', 'Entregue'],
            $this->rotulos($this->pedido('ABERTO', true)),
        );
    }

    public function test_delivery_sem_estagio_de_transporte(): void
    {
        config(['pizzaria.pedidos.usa_estagio_transporte' => false]);

        $this->assertSame(
            ['Pedido recebido', 'Confirmado', 'Em preparo', 'Pronto', 'Entregue'],
            $this->rotulos($this->pedido('ABERTO', true)),
        );
    }

    public function test_delivery_que_ja_passou_pelo_transporte_mantem_a_etapa_mesmo_com_o_estagio_desligado(): void
    {
        config(['pizzaria.pedidos.usa_estagio_transporte' => false]);
        $pedido = $this->pedido('EM TRANSPORTE', true, ['pedido_datahora_transporte' => now()]);

        $this->assertContains('Saiu para entrega', $this->rotulos($pedido));
    }

    public function test_mesa_tem_tres_etapas(): void
    {
        $pedido = $this->pedido('PREPARANDO', false);
        // Só o atributo importa para o tipo; não precisa persistir a sessão.
        $pedido->pedido_sessao_mesa_id = 1;

        $this->assertSame(['Recebido', 'Em preparo', 'Servido'], $this->rotulos($pedido));
    }

    public function test_estados_e_horarios_reais(): void
    {
        $pedido = $this->pedido('PRONTO', false, [
            'pedido_datahora_abertura' => '2026-10-30 19:05:00',
            'pedido_datahora_preparo' => '2026-10-30 19:10:00',
            'pedido_datahora_pronto' => '2026-10-30 19:32:00',
        ]);

        $etapas = (new EtapasAcompanhamento($pedido))->etapas();

        $this->assertSame(['concluida', 'concluida', 'concluida', 'atual', 'pendente'], array_column($etapas, 'estado'));
        $this->assertSame('19:05', $etapas[1]['hora']->format('H:i'));
        $this->assertSame('19:10', $etapas[2]['hora']->format('H:i'));
        $this->assertSame('19:32', $etapas[3]['hora']->format('H:i'));
        $this->assertNull($etapas[4]['hora']);
    }

    public function test_finalizado_conclui_todas_as_etapas(): void
    {
        $pedido = $this->pedido('FINALIZADO', false, ['pedido_datahora_finalizado' => '2026-10-30 20:00:00']);
        $etapas = new EtapasAcompanhamento($pedido);

        $this->assertSame(['concluida'], array_values(array_unique(array_column($etapas->etapas(), 'estado'))));
        $this->assertTrue($etapas->concluido());
        $this->assertSame('Retirado', $etapas->etapaAtual()['rotulo']);
    }

    public function test_cancelado(): void
    {
        $etapas = new EtapasAcompanhamento($this->pedido('CANCELADO', false));

        $this->assertTrue($etapas->cancelado());
        $this->assertFalse($etapas->concluido());
    }

    public function test_destaque_da_retirada_pronta_e_do_delivery_a_caminho(): void
    {
        $pronta = new EtapasAcompanhamento($this->pedido('PRONTO', false, ['pedido_datahora_pronto' => '2026-10-30 19:32:00']));
        $this->assertSame('Pode vir buscar!', $pronta->destaque()['titulo']);
        $this->assertStringContainsString('19:32', $pronta->destaque()['descricao']);

        $caminho = new EtapasAcompanhamento($this->pedido('EM TRANSPORTE', true, ['pedido_datahora_transporte' => '2026-10-30 19:42:00']));
        $this->assertSame('Saiu para entrega às 19:42.', $caminho->destaque()['descricao']);
    }
}
