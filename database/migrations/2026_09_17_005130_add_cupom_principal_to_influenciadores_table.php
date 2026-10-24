<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('influenciadores', function (Blueprint $table) {
            // Cupom que o link /r/{slug} já preenche no cardápio.
            $table->foreignId('influenciador_cupom_principal_id')->nullable()
                ->constrained('cupons', indexName: 'influ_cupom_principal_fk')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('influenciadores', function (Blueprint $table) {
            $table->dropForeign('influ_cupom_principal_fk');
            $table->dropColumn('influenciador_cupom_principal_id');
        });
    }
};
