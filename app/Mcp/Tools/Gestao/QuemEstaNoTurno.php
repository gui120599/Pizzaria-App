<?php

namespace App\Mcp\Tools\Gestao;

use App\Models\Colaborador;
use App\Models\Feriado;
use App\Models\PontoMarcacao;
use App\Models\User;
use App\Services\Ponto\MarcacoesDoDia;
use App\Support\DiaOperacional;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('quem_esta_no_turno')]
#[Description('Equipe agora: quem está com o ponto aberto (como "Trabalhando agora" do Painel do Ponto) e quem está escalado para hoje mas ainda não bateu a entrada.')]
#[IsReadOnly]
class QuemEstaNoTurno extends FerramentaGestao
{
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function permitido(User $usuario): bool
    {
        return Gate::forUser($usuario)->allows('view_espelho:ponto');
    }

    protected function executar(Request $request): Response
    {
        $agora = CarbonImmutable::now();
        $hoje = DiaOperacional::de($agora);
        $marcacoes = app(MarcacoesDoDia::class);

        $trabalhando = Colaborador::query()
            ->ativos()
            ->whereIn('id', PontoMarcacao::query()
                ->select('colaborador_id')
                ->whereDate('data_referencia', $hoje->toDateString())
                ->groupBy('colaborador_id')
                ->havingRaw('COUNT(*) % 2 = 1'))
            ->withMax(['marcacoes as ultima_marcacao' => fn ($q) => $q->whereDate('data_referencia', $hoje->toDateString())], 'registrada_em')
            ->orderBy('nome')
            ->get();

        $semEntrada = [];
        if (! Feriado::ehFeriado($hoje)) {
            foreach (Colaborador::ativos($hoje)->orderBy('nome')->get() as $colaborador) {
                $escala = $colaborador->escalaVigente($hoje);
                $dia = $escala?->diaDe($hoje);
                $entrada = $dia?->trabalha() && ! $escala->tipo->semPrevisto() ? $dia->entradaEm($hoje) : null;

                if ($entrada !== null && $agora->greaterThan($entrada) && $agora->lessThan($dia->saidaEm($hoje))
                    && $marcacoes->efetivas($colaborador, $hoje)->isEmpty()) {
                    $semEntrada[] = ['colaborador' => $colaborador->nome, 'cargo' => $colaborador->cargo, 'entrada_prevista' => $entrada->format('H:i')];
                }
            }
        }

        return Response::json([
            'dia_operacional' => $hoje->toDateString(),
            'trabalhando_agora' => $trabalhando->map(fn (Colaborador $c): array => [
                'colaborador' => $c->nome,
                'cargo' => $c->cargo,
                'entrou_as' => $c->ultima_marcacao ? CarbonImmutable::parse($c->ultima_marcacao)->format('H:i') : null,
            ])->all(),
            'escalados_sem_entrada' => $semEntrada,
        ]);
    }
}
