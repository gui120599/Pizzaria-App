<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotente: pula colunas/índices já existentes (uma execução anterior
        // pode ter aplicado parte do schema antes de falhar no índice do morph).
        Schema::table('movimentacao_produtos', function (Blueprint $table) {
            if (! Schema::hasColumn('movimentacao_produtos', 'mov_custo_unitario')) {
                $table->decimal('mov_custo_unitario', 12, 4)->default(0)->after('mov_quantidade');
            }
            if (! Schema::hasColumn('movimentacao_produtos', 'mov_custo_total')) {
                $table->decimal('mov_custo_total', 12, 2)->default(0)->after('mov_custo_unitario');
            }
            if (! Schema::hasColumn('movimentacao_produtos', 'mov_origem')) {
                $table->string('mov_origem')->nullable()->after('mov_tipo');
            }
            if (! Schema::hasColumn('movimentacao_produtos', 'mov_saldo_apos')) {
                $table->decimal('mov_saldo_apos', 12, 3)->nullable()->after('mov_origem');
            }
            if (! Schema::hasColumn('movimentacao_produtos', 'mov_data')) {
                $table->dateTime('mov_data')->nullable()->after('mov_saldo_apos');
            }
            if (! Schema::hasColumn('movimentacao_produtos', 'mov_centro_custo_id')) {
                $table->foreignId('mov_centro_custo_id')->nullable()->after('mov_user_id')
                    ->constrained('centros_custo')->nullOnDelete();
            }
            if (! Schema::hasColumn('movimentacao_produtos', 'mov_lote_id')) {
                $table->foreignId('mov_lote_id')->nullable()->after('mov_centro_custo_id')
                    ->constrained('estoque_lotes')->nullOnDelete();
            }
            // Colunas do morph sem o índice automático (cujo nome padrão passa
            // de 64 chars e estoura o limite do MySQL). O índice é criado abaixo
            // com nome curto.
            if (! Schema::hasColumn('movimentacao_produtos', 'mov_referencia_type')) {
                $table->string('mov_referencia_type')->nullable();
                $table->unsignedBigInteger('mov_referencia_id')->nullable();
            }
        });

        // Garante o índice do morph com nome curto.
        $indice = 'mov_referencia_idx';
        $existe = collect(DB::select('SHOW INDEX FROM movimentacao_produtos'))
            ->contains(fn ($i) => $i->Key_name === $indice);
        if (! $existe) {
            Schema::table('movimentacao_produtos', function (Blueprint $table) use ($indice) {
                $table->index(['mov_referencia_type', 'mov_referencia_id'], $indice);
            });
        }

        // Aumenta a precisão da quantidade (era decimal(10,2)) para suportar g/ml.
        Schema::table('movimentacao_produtos', function (Blueprint $table) {
            $table->decimal('mov_quantidade', 12, 3)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('movimentacao_produtos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mov_centro_custo_id');
            $table->dropConstrainedForeignId('mov_lote_id');
            $table->dropIndex('mov_referencia_idx');
            $table->dropColumn([
                'mov_referencia_type',
                'mov_referencia_id',
                'mov_custo_unitario',
                'mov_custo_total',
                'mov_origem',
                'mov_saldo_apos',
                'mov_data',
            ]);
        });

        Schema::table('movimentacao_produtos', function (Blueprint $table) {
            $table->decimal('mov_quantidade', 10, 2)->change();
        });
    }
};
