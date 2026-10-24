<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Log append-only de cliques no link rastreável /r/{slug}: sem
        // updated_at/softDeletes de propósito (nunca é editado nem "apagado").
        Schema::create('cliques_referencia', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clique_influenciador_id')->constrained('influenciadores')->cascadeOnDelete();
            $table->string('clique_ip_hash', 64);
            $table->string('clique_user_agent', 512)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['clique_influenciador_id', 'created_at'], 'cliques_ref_influenciador_data_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cliques_referencia');
    }
};
