<?php

namespace App\Services;

use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\SessaoMesa;
use RuntimeException;

/**
 * Ciclo de vida da sessão de mesa (abrir/fechar/reabrir/trocar mesa) — única
 * fonte de verdade pra essas transições, usada tanto pelo SessaoMesaController
 * (fluxo legado) quanto pelo SessaoMesaResource (Filament). Lançamento de itens/
 * pedidos do garçom continua fora daqui, sem mudança de comportamento.
 */
class SessaoMesaService
{
    public function abrir(int $mesaId, int $usuarioId, ?int $clienteId = null): SessaoMesa
    {
        $sessaoMesa = SessaoMesa::create([
            'sessao_mesa_mesa_id' => $mesaId,
            'sessao_mesa_status' => 'ABERTA',
            'sessao_mesa_usuario_id' => $usuarioId,
            'sessao_mesa_cliente_id' => $clienteId,
        ]);

        Mesa::whereKey($mesaId)->update([
            'mesa_status' => 'OCUPADA',
            'mesa_sessao_atual_id' => $sessaoMesa->id,
        ]);

        return $sessaoMesa;
    }

    /** Fecha com pedidos ativos, ou cancela se não houver nenhum — sempre libera a mesa. */
    public function fechar(SessaoMesa $sessaoMesa): SessaoMesa
    {
        $temPedidosAtivos = Pedido::where('pedido_sessao_mesa_id', $sessaoMesa->id)
            ->where('pedido_status', '<>', 'CANCELADO')
            ->exists();

        $sessaoMesa->update([
            'sessao_mesa_status' => $temPedidosAtivos ? 'FECHADA' : 'CANCELADA',
        ]);

        $this->liberarMesaSeAindaForaDela($sessaoMesa);

        return $sessaoMesa->fresh();
    }

    /** @throws RuntimeException quando a mesa já está ocupada por outra sessão. */
    public function reabrir(SessaoMesa $sessaoMesa): SessaoMesa
    {
        $mesa = Mesa::find($sessaoMesa->sessao_mesa_mesa_id);

        if (! $mesa) {
            throw new RuntimeException('Mesa não encontrada!');
        }

        if ($mesa->mesa_status === 'OCUPADA') {
            throw new RuntimeException("Sessão não pode ser reaberta, pois já existe uma nova sessão aberta para a {$mesa->mesa_nome}.");
        }

        $sessaoMesa->update(['sessao_mesa_status' => 'ABERTA']);
        $mesa->update([
            'mesa_status' => 'OCUPADA',
            'mesa_sessao_atual_id' => $sessaoMesa->id,
        ]);

        return $sessaoMesa->fresh();
    }

    public function trocarMesa(SessaoMesa $sessaoMesa, int $novaMesaId): SessaoMesa
    {
        $mesaAntigaId = $sessaoMesa->sessao_mesa_mesa_id;

        $sessaoMesa->update(['sessao_mesa_mesa_id' => $novaMesaId]);

        $mesaAntiga = Mesa::find($mesaAntigaId);
        if ($mesaAntiga) {
            $dados = ['mesa_status' => 'LIBERADA'];
            if ((int) $mesaAntiga->mesa_sessao_atual_id === (int) $sessaoMesa->id) {
                $dados['mesa_sessao_atual_id'] = null;
            }
            $mesaAntiga->update($dados);
        }

        Mesa::findOrFail($novaMesaId)->update([
            'mesa_status' => 'OCUPADA',
            'mesa_sessao_atual_id' => $sessaoMesa->id,
        ]);

        return $sessaoMesa->fresh();
    }

    /** Só limpa mesa_sessao_atual_id se ainda apontar pra esta sessão — protege contra derrubar mesa já reocupada. */
    private function liberarMesaSeAindaForaDela(SessaoMesa $sessaoMesa): void
    {
        $mesa = Mesa::find($sessaoMesa->sessao_mesa_mesa_id);

        if (! $mesa) {
            return;
        }

        $dados = ['mesa_status' => 'LIBERADA'];
        if ((int) $mesa->mesa_sessao_atual_id === (int) $sessaoMesa->id) {
            $dados['mesa_sessao_atual_id'] = null;
        }
        $mesa->update($dados);
    }
}
