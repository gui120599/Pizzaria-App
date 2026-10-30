<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categorias', function (Blueprint $table) {
            $table->string('categoria_formato_pizza')->default('REDONDA')->after('categoria_regra_preco_sabores');
        });

        // A regra de hoje (nome com "quadrad", sem acento) vira dado: a produção
        // segue igual depois do deploy.
        DB::table('categorias')
            ->select(['id', 'categoria_nome'])
            ->get()
            ->filter(fn ($c) => Str::contains(Str::lower(Str::ascii((string) $c->categoria_nome)), 'quadrad'))
            ->each(fn ($c) => DB::table('categorias')->where('id', $c->id)->update(['categoria_formato_pizza' => 'QUADRADA']));
    }

    public function down(): void
    {
        Schema::table('categorias', function (Blueprint $table) {
            $table->dropColumn('categoria_formato_pizza');
        });
    }
};
