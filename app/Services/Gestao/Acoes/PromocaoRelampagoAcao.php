<?php

namespace App\Services\Gestao\Acoes;

use App\Models\Produto;
use App\Models\PromocaoRelampago;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * "Liga a promoção X hoje das 18h às 20h" / "encerra a promoção X". Só liga
 * ou desliga promoção já cadastrada (produtos e preços continuam no painel).
 * Permissão: editar promoção relâmpago.
 */
class PromocaoRelampagoAcao extends AcaoBase
{
    private const DIAS = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];

    public function permitido(User $usuario): bool
    {
        return $this->pode($usuario, 'update:promocao_relampago');
    }

    public function preparar(array $entrada, User $usuario): array
    {
        $dados = Validator::make($entrada, [
            'acao' => ['required', Rule::in(['ativar', 'encerrar'])],
            'promocao_id' => ['nullable', 'integer'],
            'busca' => ['nullable', 'string', 'max:100'],
            'inicio' => ['nullable', 'date_format:Y-m-d H:i'],
            'fim' => ['nullable', 'date_format:Y-m-d H:i'],
            'zerar_vendidos' => ['nullable', 'boolean'],
        ], [
            'inicio.date_format' => 'Use inicio no formato AAAA-MM-DD HH:MM.',
            'fim.date_format' => 'Use fim no formato AAAA-MM-DD HH:MM.',
        ])->validate();

        $promocao = $this->promocao($dados);
        $promocao->load('produtos');
        $ativar = $dados['acao'] === 'ativar';
        $alertas = [];
        $inicio = null;
        $fim = null;

        if ($ativar) {
            if ($promocao->produtos->isEmpty()) {
                $alertas[] = $this->alerta('sem_produtos', 'A promoção não tem produtos. Cadastre os produtos e preços no painel.', bloqueia: true);
            }

            if ($promocao->promocao_recorrente) {
                if ($promocao->promocao_ativa) {
                    $alertas[] = $this->alerta('ja_ativa', 'A promoção recorrente já está ligada e segue a própria agenda.', bloqueia: true);
                }
                if (filled($dados['inicio'] ?? null) || filled($dados['fim'] ?? null)) {
                    $alertas[] = $this->alerta('recorrente_ignora_horario', 'Promoção recorrente segue os dias e horários cadastrados: início/fim informados são ignorados. Para mudar a agenda, use o painel.');
                }
            } else {
                $inicio = filled($dados['inicio'] ?? null) ? Carbon::createFromFormat('Y-m-d H:i', $dados['inicio']) : now()->startOfMinute();
                $fim = filled($dados['fim'] ?? null) ? Carbon::createFromFormat('Y-m-d H:i', $dados['fim']) : null;

                if ($fim === null) {
                    throw ValidationException::withMessages(['fim' => 'Informe o fim da promoção (AAAA-MM-DD HH:MM).']);
                }
                if ($fim->lte($inicio) || $fim->isPast()) {
                    $alertas[] = $this->alerta('janela_invalida', 'O fim precisa ser depois do início e no futuro.', bloqueia: true);
                }
            }

            if ($promocao->esgotada() && ! ($dados['zerar_vendidos'] ?? false)) {
                $alertas[] = $this->alerta('esgotada', 'A promoção está com o teto esgotado: ela liga, mas não vende. Peça para zerar os vendidos se for uma nova rodada.');
            }
        } elseif (! $promocao->promocao_ativa) {
            $alertas[] = $this->alerta('ja_inativa', 'A promoção já está desligada.', bloqueia: true);
        }

        $previa = [
            'acao' => $dados['acao'],
            'promocao' => [
                'promocao_id' => $promocao->id,
                'nome' => $promocao->promocao_nome,
                'recorrente' => (bool) $promocao->promocao_recorrente,
                'agenda' => $this->agenda($promocao),
                'status_atual' => $promocao->status()->getLabel(),
                'teto' => $promocao->promocao_qtd_total,
                'vendidos' => (float) $promocao->promocao_qtd_vendida,
            ],
            'nova_janela' => $inicio ? ['inicio' => $inicio->format('Y-m-d H:i'), 'fim' => $fim?->format('Y-m-d H:i')] : null,
            'zerar_vendidos' => (bool) ($dados['zerar_vendidos'] ?? false),
            'produtos' => $promocao->produtos->map(fn (Produto $produto): array => [
                'produto' => $produto->produto_descricao,
                'preco_normal' => (float) $produto->produto_preco_venda,
                'preco_promocional' => (float) $produto->pivot->prp_preco_promocional,
            ])->all(),
            'alertas' => $alertas,
        ];
        $previa['resumo_texto'] = $this->resumo($previa);

        return ['entrada' => [...$dados, 'promocao_id' => $promocao->id], 'previa' => $previa];
    }

    public function executar(array $entrada, array $previa, User $usuario): array
    {
        $promocao = PromocaoRelampago::query()->lockForUpdate()->findOrFail($entrada['promocao_id']);

        DB::transaction(function () use ($promocao, $entrada, $previa): void {
            if ($entrada['acao'] === 'encerrar') {
                $promocao->update(['promocao_ativa' => false]);

                return;
            }

            $promocao->promocao_ativa = true;
            if (! $promocao->promocao_recorrente && $previa['nova_janela'] !== null) {
                $promocao->promocao_inicio = Carbon::createFromFormat('Y-m-d H:i', $previa['nova_janela']['inicio']);
                $promocao->promocao_fim = Carbon::createFromFormat('Y-m-d H:i', $previa['nova_janela']['fim']);
            }
            if ($previa['zerar_vendidos']) {
                $promocao->promocao_qtd_vendida = 0;
                $promocao->promocaoProdutos()->update(['prp_qtd_vendida' => 0]);
            }
            $promocao->save();
        });

        $promocao->refresh();

        return [
            'promocao_id' => $promocao->id,
            'nome' => $promocao->promocao_nome,
            'status' => $promocao->status()->getLabel(),
            'inicio' => $promocao->promocao_recorrente ? null : $promocao->promocao_inicio?->format('Y-m-d H:i'),
            'fim' => $promocao->promocao_recorrente ? null : $promocao->promocao_fim?->format('Y-m-d H:i'),
        ];
    }

    /**
     * @param  array<string, mixed>  $dados
     *
     * @throws ValidationException
     */
    private function promocao(array $dados): PromocaoRelampago
    {
        if (filled($dados['promocao_id'] ?? null)) {
            return PromocaoRelampago::query()->findOr($dados['promocao_id'], fn () => throw ValidationException::withMessages(['promocao_id' => 'Promoção não encontrada.']));
        }

        $busca = trim((string) ($dados['busca'] ?? ''));
        $candidatos = PromocaoRelampago::query()
            ->when($busca !== '', fn ($q) => $q->where('promocao_nome', 'like', "%{$busca}%"))
            ->orderBy('promocao_nome')->limit(20)->get();

        if ($busca !== '' && $candidatos->count() === 1) {
            return $candidatos->first();
        }

        $lista = $candidatos->map(fn (PromocaoRelampago $p): string => "{$p->id} = {$p->promocao_nome} ({$p->status()->getLabel()})")->implode('; ');

        throw ValidationException::withMessages(['promocao_id' => $candidatos->isEmpty()
            ? 'Nenhuma promoção relâmpago encontrada'.($busca !== '' ? " com \"{$busca}\"" : '').'.'
            : "Informe o promocao_id: {$lista}"]);
    }

    private function agenda(PromocaoRelampago $promocao): string
    {
        if (! $promocao->promocao_recorrente) {
            return $promocao->promocao_inicio && $promocao->promocao_fim
                ? $promocao->promocao_inicio->format('d/m H:i').' até '.$promocao->promocao_fim->format('d/m H:i')
                : 'sem janela';
        }

        $dias = collect($promocao->promocao_dias_semana ?? [])->map(fn ($dia): string => self::DIAS[(int) $dia] ?? (string) $dia)->implode(', ');
        $horas = $promocao->promocao_hora_inicio && $promocao->promocao_hora_fim
            ? ' das '.$promocao->promocao_hora_inicio->format('H:i').' às '.$promocao->promocao_hora_fim->format('H:i')
            : ' (dia inteiro)';

        return 'toda '.($dias ?: 'semana').$horas;
    }

    /** @param  array<string, mixed>  $previa */
    private function resumo(array $previa): string
    {
        $promocao = $previa['promocao'];
        $linhas = [$previa['acao'] === 'encerrar'
            ? "Encerrar a promoção \"{$promocao['nome']}\" (hoje: {$promocao['status_atual']})."
            : "Ligar a promoção \"{$promocao['nome']}\" ".($previa['nova_janela']
                ? 'de '.Carbon::parse($previa['nova_janela']['inicio'])->format('d/m H:i').' até '.Carbon::parse($previa['nova_janela']['fim'])->format('d/m H:i')
                : "na agenda: {$promocao['agenda']}").'.'];

        foreach ($previa['produtos'] as $produto) {
            $linhas[] = "- {$produto['produto']}: ".$this->moeda($produto['preco_normal']).' → '.$this->moeda($produto['preco_promocional']);
        }

        if ($previa['zerar_vendidos']) {
            $linhas[] = 'Zera os vendidos (nova rodada'.($promocao['teto'] ? " de {$promocao['teto']} unidades" : '').').';
        }

        foreach ($previa['alertas'] as $alerta) {
            $linhas[] = ($alerta['bloqueia'] ? '⛔ ' : '⚠️ ').$alerta['mensagem'];
        }

        return implode("\n", $linhas);
    }
}
