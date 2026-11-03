<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('user_freelancer')->default(false);
        });

        Schema::create('plantoes_freelancer', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pl_user_id')->constrained('users')->cascadeOnDelete();
            $table->date('pl_dia');
            $table->foreignId('pl_marcado_por_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['pl_user_id', 'pl_dia'], 'plantoes_freelancer_user_dia_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plantoes_freelancer');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('user_freelancer');
        });
    }
};
