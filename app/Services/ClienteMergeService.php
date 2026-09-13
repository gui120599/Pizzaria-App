<?php

namespace App\Services;

use App\Models\Cliente;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Unifica clientes cadastrados em duplicidade: reatribui tudo que aponta para
 * os cadastros "perdedores" para o cadastro "principal" escolhido pelo
 * usuário, completa dados vazios do principal com o que os perdedores tinham,
 * e move os perdedores para a lixeira (soft delete).
 */
class ClienteMergeService
{
    /** Tabelas com FK para clientes.id que precisam ser reatribuídas no merge. */
    private const TABELAS_FK = [
        ['tabela' => 'pedidos', 'coluna' => 'pedido_cliente_id'],
        ['tabela' => 'vendas', 'coluna' => 'venda_cliente_id'],
        ['tabela' => 'sessao_mesas', 'coluna' => 'sessao_mesa_cliente_id'],
        ['tabela' => 'sessao_mesa_clientes', 'coluna' => 'smc_cliente_id'],
        ['tabela' => 'itens_pedidos', 'coluna' => 'item_pedido_cliente_id'],
        ['tabela' => 'prestadores', 'coluna' => 'cliente_id'],
        ['tabela' => 'lancamentos', 'coluna' => 'cliente_id'],
    ];

    /** Campos do vencedor preenchidos com dado do perdedor só se estiverem vazios. */
    private const CAMPOS_PREENCHIVEIS = [
        'cliente_cpf', 'cliente_cnpj', 'cliente_rg', 'cliente_celular', 'cliente_email',
        'cliente_endereco', 'cliente_numero_endereco', 'cliente_bairro', 'cliente_cidade',
        'cliente_uf_estado', 'cliente_cep', 'cliente_data_nascimento', 'cliente_foto',
        'cliente_limite_credito',
    ];

    /** @param  Collection<int, Cliente>  $perdedores */
    public function unificar(Cliente $principal, Collection $perdedores): void
    {
        $idsPerdedores = $perdedores->pluck('id')
            ->reject(fn (int $id): bool => $id === $principal->id)
            ->values();

        if ($idsPerdedores->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($principal, $perdedores, $idsPerdedores): void {
            foreach (self::TABELAS_FK as $fk) {
                DB::table($fk['tabela'])
                    ->whereIn($fk['coluna'], $idsPerdedores)
                    ->update([$fk['coluna'] => $principal->id]);
            }

            // sessao_mesa_clientes não tem unique(sessao, cliente): reatribuir pode
            // gerar 2 linhas do mesmo cliente na mesma sessão (se um perdedor já
            // era convidado extra da mesma sessão que o vencedor).
            $this->deduplicarSessaoMesaClientes();

            $preenchimento = [];
            foreach (self::CAMPOS_PREENCHIVEIS as $campo) {
                if (filled($principal->{$campo})) {
                    continue;
                }
                foreach ($perdedores as $perdedor) {
                    if (filled($perdedor->{$campo})) {
                        $preenchimento[$campo] = $perdedor->{$campo};
                        break;
                    }
                }
            }
            if ($preenchimento !== []) {
                $principal->update($preenchimento);
            }

            Cliente::whereIn('id', $idsPerdedores)->get()->each->delete();

            Log::info('cliente.unificado', [
                'principal_id' => $principal->id,
                'perdedores_ids' => $idsPerdedores->all(),
                'usuario_id' => Auth::id(),
            ]);
        });
    }

    private function deduplicarSessaoMesaClientes(): void
    {
        $duplicados = DB::table('sessao_mesa_clientes')
            ->select('smc_sessao_mesa_id', 'smc_cliente_id', DB::raw('MIN(id) as manter_id'))
            ->groupBy('smc_sessao_mesa_id', 'smc_cliente_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicados as $grupo) {
            DB::table('sessao_mesa_clientes')
                ->where('smc_sessao_mesa_id', $grupo->smc_sessao_mesa_id)
                ->where('smc_cliente_id', $grupo->smc_cliente_id)
                ->where('id', '!=', $grupo->manter_id)
                ->delete();
        }
    }
}
