<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avaliacao_links', function (Blueprint $table) {
            $table->id();
            $table->string('avaliacao_link_nome');
            $table->string('avaliacao_link_url');
            $table->string('avaliacao_link_logo_url');
            $table->boolean('avaliacao_link_ativo')->default(true);
            $table->integer('avaliacao_link_ordem')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avaliacao_links');
    }
};
