<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prazos_pagamento', function (Blueprint $table) {
            $table->id();
            $table->string('prazo_pagamento_nome');
            $table->boolean('prazo_pagamento_ativo')->default(true);
            $table->text('prazo_pagamento_observacoes')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prazos_pagamento');
    }
};
