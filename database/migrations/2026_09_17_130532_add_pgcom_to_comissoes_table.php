<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comissoes', function (Blueprint $table) {
            $table->foreignId('comissao_pgcom_id')->nullable()->after('comissao_status')
                ->constrained('pagamentos_comissao')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('comissoes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('comissao_pgcom_id');
        });
    }
};
