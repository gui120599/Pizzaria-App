<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Título de taxa (MDR) aponta para o recebível de adquirente que o gerou,
     * para o estorno da liquidação achar e desfazer a despesa.
     */
    public function up(): void
    {
        Schema::table('lancamentos', function (Blueprint $table) {
            $table->foreignId('recebivel_id')
                ->nullable()
                ->constrained('lancamentos', indexName: 'lanc_recebivel_fk')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lancamentos', function (Blueprint $table) {
            $table->dropForeign('lanc_recebivel_fk');
            $table->dropColumn('recebivel_id');
        });
    }
};
