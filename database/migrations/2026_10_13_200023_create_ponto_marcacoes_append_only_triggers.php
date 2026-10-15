<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Trigger opcional que impede UPDATE/DELETE em ponto_marcacoes no próprio
     * MySQL. Só roda com config('ponto.trigger_append_only') ligado
     * (PONTO_TRIGGER_APPEND_ONLY): exige o privilégio TRIGGER e, com binlog,
     * SUPER ou log_bin_trust_function_creators. Desligado, não faz nada — a
     * proteção fica no model + `ponto:verificar-integridade`.
     *
     * Para ligar depois: ativar a flag e rodar
     * `php artisan migrate:refresh --path=database/migrations/2026_10_13_200023_create_ponto_marcacoes_append_only_triggers.php`.
     */
    public function up(): void
    {
        if (! config('ponto.trigger_append_only') || DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER ponto_marcacoes_bloqueia_update BEFORE UPDATE ON ponto_marcacoes
            FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ponto_marcacoes e append-only: use ajuste de ponto'
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER ponto_marcacoes_bloqueia_delete BEFORE DELETE ON ponto_marcacoes
            FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ponto_marcacoes e append-only: use ajuste de ponto'
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS ponto_marcacoes_bloqueia_update');
        DB::unprepared('DROP TRIGGER IF EXISTS ponto_marcacoes_bloqueia_delete');
    }
};
