<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categorias', function (Blueprint $table) {
            // nullOnDelete: apagar a categoria pai não deve levar as filhas junto —
            // elas só voltam a ser categorias de topo.
            $table->foreignId('categoria_pai_id')->nullable()->after('id')
                ->constrained('categorias')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('categorias', function (Blueprint $table) {
            $table->dropConstrainedForeignId('categoria_pai_id');
        });
    }
};
