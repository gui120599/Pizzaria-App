<?php

namespace App\Services\Gestao\Acoes;

use App\Models\Produto;
use App\Models\ProdutoPausa;
use App\Models\User;
use App\Services\Gestao\PausaProdutoService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * "Acabou a massa de calabresa, pausa até amanhã": tira o produto do
 * cardápio online e/ou do atendimento/garçom e religa no horário pedido.
 * Também retoma antes da hora. Permissão: editar produto.
 */
class PausaProdutoAcao extends AcaoBase
{
    public function __construct(private readonly PausaProdutoService $pausas) {}

    public function permitido(User $usuario): bool
    {
        return $this->pode($usuario, 'update:produto');
    }

    public function preparar(array $entrada, User $usuario): array
    {
        $dados = Validator::make($entrada, [
            'acao' => ['required', Rule::in(['pausar', 'retomar'])],
            'produto_ids' => ['nullable', 'array', 'max:30', 'required_without:busca'],
            'produto_ids.*' => ['integer'],
            'busca' => ['nullable', 'string', 'min:2', 'max:100'],
            'canais' => ['nullable', 'array'],
            'canais.*' => [Rule::in(array_keys(ProdutoPausa::CAMPOS))],
            'ate' => ['nullable', 'date_format:Y-m-d H:i'],
            'motivo' => ['nullable', 'string', 'max:255'],
        ], [
            'produto_ids.required_without' => 'Informe produto_ids ou busca (nome do produto).',
            'ate.date_format' => 'Use ate no formato AAAA-MM-DD HH:MM.',
        ])->validate();

        $produtos = filled($dados['produto_ids'] ?? null)
            ? collect($dados['produto_ids'])->unique()->map(fn (int $id): Produto => $this->produtoPor($id, null, 'produto_ids'))
            : collect([$this->produtoPor(null, $dados['busca'] ?? null)]);

        $pausar = $dados['acao'] === 'pausar';
        $canais = array_values(array_unique($dados['canais'] ?? array_keys(ProdutoPausa::CAMPOS)));
        $ate = filled($dados['ate'] ?? null) ? Carbon::createFromFormat('Y-m-d H:i', $dados['ate']) : null;
        $alertas = [];

        if ($pausar && $ate !== null && $ate->isPast()) {
            $alertas[] = $this->alerta('ate_no_passado', 'O horário de volta ('.$ate->format('d/m H:i').') já passou.', bloqueia: true);
        }
        if ($pausar && $ate !== null && $ate->gt(now()->addDays(30))) {
            $alertas[] = $this->alerta('ate_longe', 'Pausa de mais de 30 dias: para tirar de vez, desmarque o produto do cardápio no painel.', bloqueia: true);
        }
        if ($pausar && $ate === null) {
            $alertas[] = $this->alerta('sem_horario', 'Sem horário de volta: o produto fica pausado até alguém pedir para retomar.');
        }

        $abertas = ProdutoPausa::query()->emAberto()->whereIn('produto_id', $produtos->pluck('id'))->get()->keyBy('produto_id');

        $linhas = $produtos->map(function (Produto $produto) use ($pausar, $canais, $abertas): array {
            $pausa = $abertas->get($produto->id);
            $ligados = collect($canais)->filter(fn (string $canal): bool => (bool) $produto->{ProdutoPausa::CAMPOS[$canal]})->values()->all();

            return [
                'produto_id' => $produto->id,
                'produto' => $produto->produto_descricao,
                'cardapio_online' => (bool) $produto->produto_cardapio,
                'atendimento_garcom' => (bool) $produto->produto_cardapio_garcom,
                'pausa_atual' => $pausa ? ['ate' => $pausa->pausado_ate?->format('Y-m-d H:i'), 'canais' => $pausa->canais] : null,
                'tem_efeito' => $pausar ? ($ligados !== [] || $pausa !== null) : $pausa !== null,
                'desliga' => $pausar ? array_map(ProdutoPausa::rotuloCanal(...), $ligados) : [],
                'religa' => ! $pausar && $pausa ? array_map(ProdutoPausa::rotuloCanal(...), $pausa->canais) : [],
            ];
        })->values();

        foreach ($linhas->where('tem_efeito', false) as $linha) {
            $alertas[] = $this->alerta('sem_efeito', $pausar
                ? "{$linha['produto']} já está fora desses canais (nada a pausar)."
                : "{$linha['produto']} não está pausado (nada a retomar).");
        }
        if ($linhas->where('tem_efeito', true)->isEmpty()) {
            $alertas[] = $this->alerta('nada_a_fazer', 'Nenhum produto muda com esta ação.', bloqueia: true);
        }

        $previa = [
            'acao' => $dados['acao'],
            'ate' => $ate?->format('Y-m-d H:i'),
            'canais' => array_map(ProdutoPausa::rotuloCanal(...), $canais),
            'produtos' => $linhas->all(),
            'alertas' => $alertas,
        ];
        $previa['resumo_texto'] = $this->resumo($previa);

        return [
            'entrada' => [...$dados, 'produto_ids' => $produtos->pluck('id')->all(), 'canais' => $canais],
            'previa' => $previa,
        ];
    }

    public function executar(array $entrada, array $previa, User $usuario): array
    {
        $ate = filled($entrada['ate'] ?? null) ? Carbon::createFromFormat('Y-m-d H:i', $entrada['ate']) : null;
        $feitos = [];

        foreach (Produto::query()->whereIn('id', $entrada['produto_ids'])->get() as $produto) {
            if ($entrada['acao'] === 'pausar') {
                $pausa = $this->pausas->pausar($produto, $entrada['canais'], $ate, $entrada['motivo'] ?? null, $usuario);
                $feitos[] = ['produto_id' => $produto->id, 'produto' => $produto->produto_descricao, 'pausa_id' => $pausa->id, 'volta_em' => $pausa->pausado_ate?->format('Y-m-d H:i')];

                continue;
            }

            $pausa = ProdutoPausa::query()->emAberto()->where('produto_id', $produto->id)->first();
            if ($pausa !== null) {
                $this->pausas->retomar($pausa, $usuario);
                $feitos[] = ['produto_id' => $produto->id, 'produto' => $produto->produto_descricao, 'retomado' => true];
            }
        }

        return ['produtos' => $feitos];
    }

    /** @param  array<string, mixed>  $previa */
    private function resumo(array $previa): string
    {
        $linhas = [$previa['acao'] === 'pausar'
            ? 'Pausar '.($previa['ate'] ? 'até '.Carbon::parse($previa['ate'])->format('d/m H:i') : 'sem horário de volta').':'
            : 'Retomar agora:'];

        foreach ($previa['produtos'] as $produto) {
            $efeito = $previa['acao'] === 'pausar'
                ? ($produto['desliga'] ? 'sai de '.implode(' e ', $produto['desliga']) : ($produto['pausa_atual'] ? 'já pausado — muda o horário de volta' : 'sem mudança'))
                : ($produto['religa'] ? 'volta para '.implode(' e ', $produto['religa']) : 'não está pausado');
            $linhas[] = "- {$produto['produto']}: {$efeito}";
        }

        foreach ($previa['alertas'] as $alerta) {
            $linhas[] = ($alerta['bloqueia'] ? '⛔ ' : '⚠️ ').$alerta['mensagem'];
        }

        return implode("\n", $linhas);
    }
}
