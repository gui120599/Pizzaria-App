<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Foto do cupom/recibo da compra avulsa, enviada pelo link assinado. */
    public function up(): void
    {
        Schema::create('compra_anexos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compra_id')->constrained('compras', 'id', 'canexo_compra_fk')->cascadeOnDelete();
            $table->string('caminho');
            $table->string('mime', 60);
            $table->unsignedInteger('tamanho');
            $table->foreignId('enviado_por')->nullable()->constrained('users', 'id', 'canexo_user_fk')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compra_anexos');
    }
};
