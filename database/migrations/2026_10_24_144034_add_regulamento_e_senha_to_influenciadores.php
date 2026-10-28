<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Regulamento interno do programa, versionado: cada publicação é uma linha nova.
        Schema::create('regulamentos_influenciador', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('ri_versao')->unique();
            $table->longText('ri_texto');
            $table->timestamp('ri_publicado_em');
            $table->foreignId('ri_user_id')->nullable()->constrained('users', indexName: 'ri_user_fk')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('influenciadores', function (Blueprint $table) {
            $table->unsignedInteger('influenciador_regulamento_versao')->nullable();
            $table->timestamp('influenciador_regulamento_aceito_em')->nullable();
            $table->string('influenciador_regulamento_aceito_ip', 45)->nullable();
            // Senha temporária definida pelo Admin: força a troca no próximo acesso.
            $table->boolean('influenciador_deve_trocar_senha')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('influenciadores', function (Blueprint $table) {
            $table->dropColumn([
                'influenciador_regulamento_versao',
                'influenciador_regulamento_aceito_em',
                'influenciador_regulamento_aceito_ip',
                'influenciador_deve_trocar_senha',
            ]);
        });

        Schema::dropIfExists('regulamentos_influenciador');
    }
};
