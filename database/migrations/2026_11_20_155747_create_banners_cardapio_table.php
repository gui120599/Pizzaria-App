<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Banners do carrossel no topo do cardápio (Cardápio › Banners do cardápio):
 * imagem ou GIF para computador e para celular, ordem, ação ao tocar e
 * vigência no mesmo formato das promoções (janela única ou dias da semana
 * com horário, ex.: "Sextou" toda sexta a partir das 18h).
 *
 * As permissões entram aqui para chegarem à produção com o migrate:
 * Admin passa pelo Gate::before; o Gerente recebe todas.
 */
return new class extends Migration
{
    private const PERMISSOES = [
        'view_any:banner_cardapio', 'view:banner_cardapio', 'create:banner_cardapio',
        'update:banner_cardapio', 'delete:banner_cardapio', 'delete_any:banner_cardapio',
        'restore:banner_cardapio', 'reorder:banner_cardapio',
    ];

    public function up(): void
    {
        Schema::create('banners_cardapio', function (Blueprint $table) {
            $table->id();
            $table->string('banner_titulo');
            $table->string('banner_imagem');
            $table->string('banner_imagem_mobile')->nullable();
            $table->boolean('banner_ativo')->default(true);
            $table->unsignedInteger('banner_ordem')->default(0);
            $table->string('banner_acao', 20)->default('NENHUMA');
            $table->foreignId('banner_categoria_id')->nullable()->constrained('categorias')->nullOnDelete();
            $table->foreignId('banner_produto_id')->nullable()->constrained('produtos')->nullOnDelete();
            $table->string('banner_url', 500)->nullable();
            $table->boolean('banner_exibir_delivery')->default(true);
            $table->boolean('banner_exibir_mesa')->default(true);
            $table->boolean('banner_recorrente')->default(false);
            $table->dateTime('banner_inicio')->nullable();
            $table->dateTime('banner_fim')->nullable();
            $table->json('banner_dias_semana')->nullable();
            $table->time('banner_hora_inicio')->nullable();
            $table->time('banner_hora_fim')->nullable();
            $table->date('banner_data_final_recorrencia')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        foreach (self::PERMISSOES as $nome) {
            Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']);
        }

        Role::query()->where('name', 'Gerente')->where('guard_name', 'web')->first()?->givePermissionTo(self::PERMISSOES);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('banners_cardapio');

        Permission::whereIn('name', self::PERMISSOES)->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
