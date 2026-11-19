<?php

namespace App\Filament\Resources\Clientes\Actions;

use App\Filament\Resources\Clientes\Tables\Actions\UnificarClientesBulkAction;
use App\Models\Cliente;
use App\Support\Telefone;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Modal "Duplicidades por celular" da lista de Clientes: agrupa os cadastros
 * cujo celular é o mesmo número (App\Support\Telefone — com/sem máscara, +55,
 * zero do DDD, sem o nono dígito), mostra como cada um está gravado e deixa o
 * administrador escolher quais juntar e qual permanece. Só agrupa por número
 * idêntico depois de normalizado, nunca por semelhança; nada é unificado sem
 * a seleção e a confirmação explícitas. A unificação em si é a mesma da bulk
 * action (UnificarClientesBulkAction::unificar → ClienteMergeService).
 */
class DuplicidadesCelularAction
{
    /** Chave curta demais (ex.: "0") é lixo de digitação, não um telefone. */
    private const TAMANHO_MINIMO_CHAVE = 8;

    public static function make(): Action
    {
        return Action::make('duplicidadesCelular')
            ->label('Duplicidades por celular')
            ->icon('heroicon-o-device-phone-mobile')
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->hasRole('Admin') ?? false)
            ->modalHeading('Duplicidades por celular')
            ->modalDescription(
                'Cadastros com o mesmo número de celular, em qualquer formato. Confira nome, '.
                'endereço e pedidos antes de juntar: um mesmo número pode ser de pessoas diferentes '.
                '(família, trabalho).'
            )
            ->modalWidth('3xl')
            ->modalSubmitActionLabel('Unificar selecionados')
            ->schema([
                Text::make(fn (): string => self::resumo()),
                Select::make('grupo')
                    ->label('Número')
                    ->placeholder('Escolha um número com mais de um cadastro')
                    ->options(fn (): array => self::opcoesGrupos())
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        $clientes = self::clientesDoGrupo($state);
                        $set('clientes', $clientes->pluck('id')->map(fn (int $id): string => (string) $id)->all());
                        $set('cliente_principal_id', $clientes->sortByDesc('pedidos_count')->first()?->id);
                        $set('confirmacao', false);
                    }),
                CheckboxList::make('clientes')
                    ->label('Cadastros a juntar')
                    ->options(fn (Get $get): array => self::clientesDoGrupo($get('grupo'))
                        ->mapWithKeys(fn (Cliente $c): array => [$c->id => "#{$c->id} — {$c->cliente_nome}"])
                        ->all())
                    ->descriptions(fn (Get $get): array => self::clientesDoGrupo($get('grupo'))
                        ->mapWithKeys(fn (Cliente $c): array => [$c->id => self::detalhes($c)])
                        ->all())
                    ->visible(fn (Get $get): bool => filled($get('grupo')))
                    ->required()
                    ->minItems(2)
                    ->live(),
                Radio::make('cliente_principal_id')
                    ->label('Cadastro que permanece')
                    ->options(fn (Get $get): array => self::clientesDoGrupo($get('grupo'))
                        ->whereIn('id', array_map('intval', (array) $get('clientes')))
                        ->mapWithKeys(fn (Cliente $c): array => [$c->id => UnificarClientesBulkAction::descricaoOpcao($c)])
                        ->all())
                    ->visible(fn (Get $get): bool => count((array) $get('clientes')) >= 2)
                    ->required()
                    ->in(fn (Get $get): array => array_map('intval', (array) $get('clientes'))),
                Checkbox::make('confirmacao')
                    ->label('Confirmo que os cadastros marcados são da mesma pessoa. Os demais terão pedidos, vendas, sessões de mesa, lançamentos e prestadores passados para o cadastro que permanece e irão para a lixeira.')
                    ->visible(fn (Get $get): bool => count((array) $get('clientes')) >= 2)
                    ->accepted(),
            ])
            ->action(function (array $data): void {
                $ids = array_map('intval', (array) ($data['clientes'] ?? []));

                // Só cadastros do grupo escolhido: nada de fora do número entra na unificação.
                $records = self::clientesDoGrupo($data['grupo'] ?? null)->whereIn('id', $ids)->values();

                UnificarClientesBulkAction::unificar(new Collection($records->all()), (int) ($data['cliente_principal_id'] ?? 0));
            });
    }

    /** @return array<string, string> chave normalizada => "(62) 99999-9999 — 3 cadastros: Ana, …" */
    public static function opcoesGrupos(): array
    {
        return self::queryGrupos()
            ->selectRaw("cliente_celular_normalizado as chave, COUNT(*) as total, GROUP_CONCAT(cliente_nome ORDER BY id SEPARATOR ', ') as nomes")
            ->orderByDesc('total')
            ->orderBy('chave')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $grupo): array => [
                $grupo->chave => sprintf(
                    '%s — %d cadastros: %s%s',
                    Telefone::formatar($grupo->chave),
                    $grupo->total,
                    mb_strimwidth((string) $grupo->nomes, 0, 90, '…'),
                    Telefone::canonico($grupo->chave) === null ? ' (formato não reconhecido)' : '',
                ),
            ])
            ->all();
    }

    /** @return Collection<int, Cliente> */
    public static function clientesDoGrupo(?string $chave): Collection
    {
        if (blank($chave) || mb_strlen($chave) < self::TAMANHO_MINIMO_CHAVE) {
            return new Collection;
        }

        return Cliente::naoAvulsos()
            ->where('cliente_celular_normalizado', $chave)
            ->withCount('pedidos')
            ->withMax('pedidos', 'created_at')
            ->orderBy('id')
            ->get();
    }

    private static function resumo(): string
    {
        $grupos = DB::query()->fromSub(self::queryGrupos()->selectRaw('COUNT(*) as total')->toBase(), 'g');
        $numeros = (clone $grupos)->count();
        $cadastros = (int) $grupos->sum('total');

        return $numeros === 0
            ? 'Nenhum número de celular com mais de um cadastro.'
            : "{$numeros} número(s) de celular com mais de um cadastro, somando {$cadastros} cadastros.";
    }

    private static function queryGrupos(): Builder
    {
        return Cliente::naoAvulsos()
            ->whereNotNull('cliente_celular_normalizado')
            ->whereRaw('CHAR_LENGTH(cliente_celular_normalizado) >= ?', [self::TAMANHO_MINIMO_CHAVE])
            ->groupBy('cliente_celular_normalizado')
            ->havingRaw('COUNT(*) > 1');
    }

    private static function detalhes(Cliente $cliente): string
    {
        $ultimoPedido = $cliente->pedidos_max_created_at
            ? 'último pedido '.Carbon::parse($cliente->pedidos_max_created_at)->format('d/m/Y')
            : 'sem pedidos';

        $endereco = collect([$cliente->cliente_endereco, $cliente->cliente_numero_endereco, $cliente->cliente_bairro])
            ->filter()
            ->implode(', ');

        return collect([
            'gravado como "'.$cliente->cliente_celular.'"',
            $cliente->pedidos_count.' pedido(s), '.$ultimoPedido,
            $cliente->cliente_cpf ? 'CPF '.$cliente->cliente_cpf : null,
            $endereco !== '' ? $endereco : null,
            'cadastrado em '.$cliente->created_at?->format('d/m/Y'),
        ])->filter()->implode(' · ');
    }
}
