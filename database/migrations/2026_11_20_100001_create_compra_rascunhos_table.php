<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rascunho de compra avulsa (Fase 5): a prévia calculada pelo sistema a
     * partir do que o Claude leu da foto/mensagem. Só vira compra quando o
     * mesmo usuário confirma; expira em 30 min.
     */
    public function up(): void
    {
        Schema::create('compra_rascunhos', function (Blueprint $table) {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->foreignId('user_id')->constrained('users', 'id', 'crasc_user_fk');
            $table->foreignId('prestador_id')->nullable()->constrained('prestadores', 'id', 'crasc_prestador_fk')->nullOnDelete();
            $table->json('fornecedor_novo')->nullable();
            $table->json('entrada');
            $table->json('previa');
            $table->string('status', 15)->default('aberto')->index();
            $table->dateTime('expira_em');
            $table->dateTime('confirmado_em')->nullable();
            $table->foreignId('compra_id')->nullable()->constrained('compras', 'id', 'crasc_compra_fk')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compra_rascunhos');
    }
};
