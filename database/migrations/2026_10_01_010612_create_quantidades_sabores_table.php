<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Opções de quantidade de sabores por categoria ("Sabor único", "Meia a
     * meia", "3 sabores"), com o percentual de cada posição. Substitui o teto
     * único categoria_max_sabores — o backfill cria as opções de 1 até o teto
     * antigo com percentuais iguais e só então remove a coluna.
     */
    public function up(): void
    {
        Schema::create('quantidades_sabores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quantidade_sabor_categoria_id')->constrained('categorias')->cascadeOnDelete();
            $table->string('quantidade_sabor_descricao', 60);
            $table->unsignedTinyInteger('quantidade_sabor_quantidade');
            $table->json('quantidade_sabor_percentuais');
            $table->unsignedInteger('quantidade_sabor_ordem')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['quantidade_sabor_categoria_id', 'quantidade_sabor_quantidade'], 'unq_qtd_sabor_categoria');
        });

        Schema::table('categorias', function (Blueprint $table) {
            $table->boolean('categoria_herda_sabores')->default(false)->after('categoria_permite_sabores');
        });

        $agora = now();

        DB::table('categorias')
            ->where('categoria_permite_sabores', true)
            ->get(['id', 'categoria_max_sabores'])
            ->each(function (object $categoria) use ($agora): void {
                $max = max(1, (int) $categoria->categoria_max_sabores);

                for ($n = 1; $n <= $max; $n++) {
                    DB::table('quantidades_sabores')->insert([
                        'quantidade_sabor_categoria_id' => $categoria->id,
                        'quantidade_sabor_descricao' => match ($n) {
                            1 => 'Sabor único',
                            2 => 'Meia a meia',
                            default => "{$n} sabores",
                        },
                        'quantidade_sabor_quantidade' => $n,
                        'quantidade_sabor_percentuais' => json_encode(self::percentuaisIguais($n)),
                        'quantidade_sabor_ordem' => $n,
                        'created_at' => $agora,
                        'updated_at' => $agora,
                    ]);
                }
            });

        Schema::table('categorias', function (Blueprint $table) {
            $table->dropColumn('categoria_max_sabores');
        });
    }

    public function down(): void
    {
        Schema::table('categorias', function (Blueprint $table) {
            $table->unsignedTinyInteger('categoria_max_sabores')->default(2)->after('categoria_permite_sabores');
        });

        DB::table('quantidades_sabores')
            ->whereNull('deleted_at')
            ->selectRaw('quantidade_sabor_categoria_id, MAX(quantidade_sabor_quantidade) as maximo')
            ->groupBy('quantidade_sabor_categoria_id')
            ->get()
            ->each(fn (object $linha) => DB::table('categorias')
                ->where('id', $linha->quantidade_sabor_categoria_id)
                ->update(['categoria_max_sabores' => max(2, (int) $linha->maximo)]));

        Schema::table('categorias', function (Blueprint $table) {
            $table->dropColumn('categoria_herda_sabores');
        });

        Schema::dropIfExists('quantidades_sabores');
    }

    /**
     * Cópia congelada de QuantidadeSabor::percentuaisIguais() — migration não
     * deve depender de código de model que pode mudar depois.
     *
     * @return array<int, float>
     */
    private static function percentuaisIguais(int $quantidade): array
    {
        $base = round(100 / $quantidade, 2);
        $percentuais = array_fill(0, $quantidade - 1, $base);
        $percentuais[] = round(100 - array_sum($percentuais), 2);

        return $percentuais;
    }
};
