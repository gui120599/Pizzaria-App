<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private string $permission = 'conceder_cortesia:pedido';

    public function up(): void
    {
        Schema::table('itens_pedidos', function (Blueprint $table) {
            $table->boolean('item_pedido_cortesia')->default(false);
            $table->string('item_pedido_cortesia_motivo')->nullable();
            $table->foreignId('item_pedido_cortesia_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('item_pedido_cortesia_em')->nullable();
        });

        Schema::table('itens_vendas', function (Blueprint $table) {
            $table->boolean('item_venda_cortesia')->default(false);
            // Quanto seria cobrado: o relatório de cortesias mostra o que deixou de faturar.
            $table->decimal('item_venda_valor_tabela', 10, 2)->nullable();
        });

        Permission::firstOrCreate(['name' => $this->permission, 'guard_name' => 'web']);
        Role::where('name', 'Gerente')->where('guard_name', 'web')->first()?->givePermissionTo($this->permission);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('itens_vendas', function (Blueprint $table) {
            $table->dropColumn(['item_venda_cortesia', 'item_venda_valor_tabela']);
        });

        Schema::table('itens_pedidos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('item_pedido_cortesia_user_id');
            $table->dropColumn(['item_pedido_cortesia', 'item_pedido_cortesia_motivo', 'item_pedido_cortesia_em']);
        });

        Permission::where('name', $this->permission)->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
