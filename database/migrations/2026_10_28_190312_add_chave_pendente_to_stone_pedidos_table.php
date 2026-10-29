<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Chave do alvo cobrado (venda, pedido ou sessão de mesa), por origem. Não
     * usa COALESCE entre as colunas: o webhook grava stp_venda_id num
     * StonePedido de origem pedido/mesa depois de criado, e uma chave que
     * muda com isso poderia colidir com a de outro alvo no meio do pagamento.
     */
    private const CHAVE_DO_ALVO = "CONCAT(stp_origem, ':', CASE stp_origem WHEN 'venda' THEN stp_venda_id WHEN 'pedido' THEN stp_pedido_id ELSE stp_sessao_mesa_id END)";

    /** Direto pendente ou resultado desconhecido: o que só pode existir uma vez por alvo. */
    private const CONDICAO_PENDENTE = "(stp_modo = 'direto' AND stp_status IN ('aguardando', 'pago_parcial')) OR stp_status = 'indeterminado'";

    public function up(): void
    {
        $duplicados = DB::select(
            'SELECT chave, COUNT(*) AS total, GROUP_CONCAT(id) AS ids FROM (SELECT id, '.self::CHAVE_DO_ALVO.' AS chave FROM stone_pedidos WHERE '.self::CONDICAO_PENDENTE.') pendentes GROUP BY chave HAVING COUNT(*) > 1'
        );

        if ($duplicados !== []) {
            $lista = collect($duplicados)->map(fn ($linha): string => "{$linha->chave} (stone_pedidos {$linha->ids})")->implode('; ');

            throw new RuntimeException("Há mais de uma cobrança Stone pendente (Direto ou resultado desconhecido) para o mesmo alvo: {$lista}. Cancele ou feche as repetidas em Financeiro › Pedidos Stone e rode a migration de novo.");
        }

        Schema::table('stone_pedidos', function (Blueprint $table) {
            // Defesa em profundidade da regra "uma cobrança Direto pendente por
            // alvo, e um resultado desconhecido por alvo". Cobranças Listado
            // pendentes ficam com NULL e não colidem (o MySQL aceita vários NULL).
            $table->string('stp_chave_pendente', 40)
                ->nullable()
                ->virtualAs('IF('.self::CONDICAO_PENDENTE.', '.self::CHAVE_DO_ALVO.', NULL)')
                ->after('stp_fechado_em');
            // Nome curto: o padrão inferido estoura os 64 caracteres do MySQL.
            $table->unique('stp_chave_pendente', 'stp_chave_pend_uk');
        });
    }

    public function down(): void
    {
        Schema::table('stone_pedidos', function (Blueprint $table) {
            $table->dropUnique('stp_chave_pend_uk');
            $table->dropColumn('stp_chave_pendente');
        });
    }
};
