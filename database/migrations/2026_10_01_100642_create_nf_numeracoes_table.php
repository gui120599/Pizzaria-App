<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nf_numeracoes', function (Blueprint $table) {
            $table->id();
            $table->string('emitente_cnpj', 14);
            $table->string('ambiente', 20);
            $table->unsignedSmallInteger('modelo');
            $table->unsignedSmallInteger('serie');
            $table->unsignedInteger('ultimo_numero')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['emitente_cnpj', 'ambiente', 'modelo', 'serie'], 'nf_numeracoes_sequencia_unique');
        });

        $this->semearSequenciaAtual();
    }

    public function down(): void
    {
        Schema::dropIfExists('nf_numeracoes');
    }

    /**
     * Até aqui o número da NFC-e era o id da venda (série 1). Continua a
     * sequência a partir do maior id já enviado à NFe.io — inclusive
     * rejeitados/cancelados: melhor uma lacuna de um número do que repetir
     * um número já usado.
     */
    private function semearSequenciaAtual(): void
    {
        $empresa = DB::table('empresas')->first();
        $cnpj = preg_replace('/\D/', '', (string) ($empresa->empresa_cnpj ?? ''));

        if ($cnpj === '') {
            return;
        }

        $ultimoNumero = (int) DB::table('vendas')
            ->where(fn ($query) => $query->whereNotNull('venda_status_nfe')->orWhereNotNull('venda_id_nfe'))
            ->max('id');

        DB::table('nf_numeracoes')->insert([
            'emitente_cnpj' => $cnpj,
            'ambiente' => $empresa->empresa_api_nfeio_ambiente ?? 'test',
            'modelo' => 65,
            'serie' => 1,
            'ultimo_numero' => $ultimoNumero,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
