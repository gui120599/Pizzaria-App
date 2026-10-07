<?php

namespace App\Services;

use App\Exceptions\MesaIndisponivelException;
use App\Models\Mesa;
use App\Models\Pedido;
use App\Models\SessaoMesa;
use App\Services\MesaCliente\AprovacaoPedidoMesaService;
use App\Services\MesaCliente\MesaChamadoService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Ciclo de vida da sessão de mesa (abrir/fechar/reabrir/trocar mesa) — única
 * fonte de verdade pra essas transições, usada tanto pelo SessaoMesaController
 * (fluxo legado) quanto pelo SessaoMesaResource (Filament). Lançamento de itens/
 * pedidos do garçom continua fora daqui, sem mudança de comportamento.
 */
class SessaoMesaService
{
    /**
     * Abre a sessão travando a linha da mesa: dois garçons tocando na mesma
     * mesa livre ao mesmo tempo não criam duas sessões.
     *
     * @param  ?float  $taxaServicoPercentual  null = padrão de config('pizzaria.salao')
     *
     * @throws MesaIndisponivelException quando a mesa já está ocupada ou inativa.
     */
    public function abrir(
        int $mesaId,
        int $usuarioId,
        ?int $clienteId = null,
        int $pessoas = 1,
        ?float $taxaServicoPercentual = null,
    ): SessaoMesa {
        return DB::transaction(function () use ($mesaId, $usuarioId, $clienteId, $pessoas, $taxaServicoPercentual) {
            $mesa = Mesa::whereKey($mesaId)->lockForUpdate()->firstOrFail();

            if ($mesa->mesa_status === 'OCUPADA') {
                throw new MesaIndisponivelException("{$mesa->mesa_nome} já está ocupada por outra sessão.");
            }

            if ($mesa->mesa_status === 'INATIVA') {
                throw new MesaIndisponivelException("{$mesa->mesa_nome} está inativa.");
            }

            $sessaoMesa = SessaoMesa::create([
                'sessao_mesa_mesa_id' => $mesaId,
                'sessao_mesa_status' => 'ABERTA',
                'sessao_mesa_usuario_id' => $usuarioId,
                'sessao_mesa_cliente_id' => $clienteId,
                'sessao_mesa_pessoas' => max(1, $pessoas),
                'sessao_mesa_taxa_servico_percentual' => $taxaServicoPercentual ?? self::taxaServicoPadrao(),
            ]);

            $mesa->update([
                'mesa_status' => 'OCUPADA',
                'mesa_sessao_atual_id' => $sessaoMesa->id,
            ]);

            // Quem leu o QR com a mesa fechada e pediu para abrir foi atendido.
            app(MesaChamadoService::class)->atenderAberturaDaMesa($mesaId, $usuarioId);

            return $sessaoMesa;
        });
    }

    /** Percentual de taxa de serviço aplicado a uma conta nova. */
    public static function taxaServicoPadrao(): float
    {
        return config('pizzaria.salao.taxa_servico_padrao_ligada')
            ? (float) config('pizzaria.salao.taxa_servico_percentual')
            : 0.0;
    }

    /**
     * Fecha com pedidos ativos, ou cancela se não houver nenhum — sempre libera
     * a mesa. Antes, recusa os pedidos do QR que esperavam o garçom e tira da
     * fila os chamados não atendidos; o token dos celulares morre junto com a
     * conta (ParticipanteMesaService::resolver).
     */
    public function fechar(SessaoMesa $sessaoMesa): SessaoMesa
    {
        app(AprovacaoPedidoMesaService::class)->recusarPendentesDaSessao($sessaoMesa, auth()->user(), 'Mesa fechada.');
        app(MesaChamadoService::class)->encerrarDaSessao($sessaoMesa);

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
