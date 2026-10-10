<?php

use App\Models\SessaoMesa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PIN da conta: o garçom informa a quem está na mesa, e o celular só entra
     * na conta pelo QR com ele. As contas abertas agora ganham um PIN já.
     */
    public function up(): void
    {
        Schema::table('sessao_mesas', function (Blueprint $table) {
            $table->string('sessao_mesa_pin', 4)->nullable()->after('sessao_mesa_status');
        });

        DB::table('sessao_mesas')
            ->where('sessao_mesa_status', 'ABERTA')
            ->pluck('id')
            ->each(fn (int $id) => DB::table('sessao_mesas')->where('id', $id)->update(['sessao_mesa_pin' => SessaoMesa::novoPin()]));
    }

    public function down(): void
    {
        Schema::table('sessao_mesas', function (Blueprint $table) {
            $table->dropColumn('sessao_mesa_pin');
        });
    }
};
