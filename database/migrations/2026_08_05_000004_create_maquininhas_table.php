<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maquininhas', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->string('operadora');  // usa App\Enums\OperadoraMaquininha
            // Referência futura pra API Stone Connect (ID do terminal na Stone).
            $table->string('identificador_externo')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maquininhas');
    }
};
