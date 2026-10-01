<?php

namespace App\Console\Commands;

use App\Exceptions\NfeIoException;
use App\Services\Nfe\NfNumeracaoService;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Confere ou ajusta o último número usado na sequência da NFC-e — usado no
 * deploy para continuar a partir da última nota autorizada em produção.
 * Sem argumento, só mostra o próximo número.
 */
class NfeNumeracaoDefinir extends Command
{
    protected $signature = 'nfe:numeracao-definir
        {ultimo_numero? : Último número de NFC-e já usado (a próxima nota sai com este + 1)}
        {--serie=1 : Série da NFC-e}';

    protected $description = 'Mostra ou define o último número usado na sequência de NFC-e do emitente/ambiente atual';

    public function handle(NfNumeracaoService $numeracao): int
    {
        $serie = (int) $this->option('serie');
        $ultimoNumero = $this->argument('ultimo_numero');

        try {
            if ($ultimoNumero !== null) {
                if (! ctype_digit((string) $ultimoNumero)) {
                    $this->error('Informe um número inteiro não negativo.');

                    return self::FAILURE;
                }

                $numeracao->definirUltimoNumero((int) $ultimoNumero, $serie);
                $this->info("Sequência da série {$serie} ajustada: último número = {$ultimoNumero}.");
            }

            $this->line("Próxima NFC-e da série {$serie}: nº ".$numeracao->proximoNumero($serie));
        } catch (NfeIoException|InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
