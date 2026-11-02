<?php

namespace App\Support\Push;

use App\Enums\EventoPushGarcomEnum;
use App\Enums\PedidoOrigemEnum;
use App\Enums\TipoChamadoMesaEnum;
use App\Filament\Garcom\Pages\AtenderMesa;
use App\Filament\Garcom\Pages\AtenderRetirada;
use App\Models\MesaChamado;
use App\Models\Pedido;
use Illuminate\Support\Str;

/**
 * O que avisar no celular do garçom: texto, destino do toque e quem é o dono
 * do assunto. A chave identifica a ocorrência (idempotência por aparelho) e a
 * tag agrupa as notificações da mesma mesa: a nova substitui a anterior.
 * Lembrete é o mesmo assunto de novo (chave com sufixo `:lembrete:n`, mesma tag).
 */
final readonly class EventoGarcom
{
    public function __construct(
        public EventoPushGarcomEnum $evento,
        public string $chave,
        public string $tag,
        public string $titulo,
        public string $corpo,
        public string $url,
        public ?int $donoId = null,
        public bool $sempreTodosEmTurno = false,
        public bool $lembrete = false,
    ) {}

    /**
     * @param  int|null  $lembrete  número do lembrete (1, 2, …); nulo = primeiro aviso
     * @param  int  $esperaMin  há quantos minutos o assunto aguarda (só nos lembretes)
     */
    public static function chamado(MesaChamado $chamado, ?int $lembrete = null, int $esperaMin = 0): self
    {
        $chamado->loadMissing('mesa:id,mesa_nome', 'sessaoMesa:id,sessao_mesa_usuario_id');

        $mesa = $chamado->mesa?->mesa_nome ?? 'Mesa';
        $chave = self::chaveDe("chamado:{$chamado->id}", $lembrete);
        $tag = "mesa-{$chamado->mc_mesa_id}";
        $dono = $chamado->sessaoMesa?->sessao_mesa_usuario_id;
        $url = $chamado->mc_sessao_mesa_id ? self::urlDaMesa($chamado->mc_sessao_mesa_id) : '/garcom';
        $espera = fn (string $novo, string $comEspera): string => $lembrete === null ? $novo : $comEspera;

        return match ($chamado->mc_tipo) {
            TipoChamadoMesaEnum::CHAMAR_GARCOM => new self(EventoPushGarcomEnum::CHAMOU_GARCOM, $chave, $tag, $espera("🔔 {$mesa} está chamando o garçom", "🔔 {$mesa} chamando há {$esperaMin} min"), 'Toque para abrir a mesa.', $url, $dono, false, $lembrete !== null),
            TipoChamadoMesaEnum::PEDIR_CONTA => new self(EventoPushGarcomEnum::PEDIU_CONTA, $chave, $tag, $espera("💳 {$mesa} pediu a conta", "💳 {$mesa} pediu a conta há {$esperaMin} min"), 'Toque para abrir a mesa.', $url, $dono, false, $lembrete !== null),
            TipoChamadoMesaEnum::ABRIR_MESA => new self(EventoPushGarcomEnum::ABRIR_MESA, $chave, "abrir-{$chamado->mc_mesa_id}", $espera("🪑 {$mesa} quer abrir a mesa", "🪑 {$mesa} quer abrir a mesa há {$esperaMin} min"), 'Toque para ver o mapa de mesas.', '/garcom', null, true, $lembrete !== null),
        };
    }

    /** Pedido do QR parado esperando o garçom aprovar. */
    public static function aprovacao(Pedido $pedido, ?int $lembrete = null, int $esperaMin = 0): self
    {
        $pedido->loadMissing('sessaoMesa.mesa:id,mesa_nome');

        $sessao = $pedido->sessaoMesa;
        $mesa = $sessao?->mesa?->mesa_nome ?? 'Mesa';

        return new self(
            EventoPushGarcomEnum::APROVAR_PEDIDO,
            self::chaveDe("aprovar:{$pedido->id}", $lembrete),
            'mesa-'.($sessao?->sessao_mesa_mesa_id ?? $pedido->id),
            $lembrete === null ? "📝 {$mesa} fez um pedido para aprovar" : "📝 {$mesa} aguarda aprovação há {$esperaMin} min",
            'Toque para aprovar ou recusar.',
            $pedido->pedido_sessao_mesa_id ? self::urlDaMesa($pedido->pedido_sessao_mesa_id) : '/garcom',
            $sessao?->sessao_mesa_usuario_id,
            false,
            $lembrete !== null,
        );
    }

    /** Rodada de mesa ou retirada lançada pelo garçom pronta para servir. */
    public static function pronto(Pedido $pedido): self
    {
        $pedido->loadMissing('sessaoMesa.mesa:id,mesa_nome', 'cliente:id,cliente_nome');

        if ($pedido->pedido_sessao_mesa_id !== null) {
            $mesa = $pedido->sessaoMesa?->mesa?->mesa_nome ?? 'Mesa';

            return new self(
                EventoPushGarcomEnum::PEDIDO_PRONTO,
                "pronto:{$pedido->id}",
                'mesa-'.($pedido->sessaoMesa?->sessao_mesa_mesa_id ?? $pedido->id),
                "🍕 Pedido pronto — {$mesa} (#{$pedido->id})",
                'Toque para abrir a mesa.',
                self::urlDaMesa($pedido->pedido_sessao_mesa_id),
                $pedido->pedido_usuario_garcom_id,
            );
        }

        $cliente = $pedido->cliente?->cliente_nome ? Str::of($pedido->cliente->cliente_nome)->before(' ')->toString() : null;

        return new self(
            EventoPushGarcomEnum::PEDIDO_PRONTO,
            "pronto:{$pedido->id}",
            "retirada-{$pedido->id}",
            "🍕 Pedido pronto — Retirada #{$pedido->id}",
            $cliente ? "Cliente: {$cliente}. Toque para abrir." : 'Toque para abrir.',
            self::caminho(AtenderRetirada::getUrl(['pedido' => $pedido->id], panel: 'garcom')),
            $pedido->pedido_usuario_garcom_id,
        );
    }

    public static function ehRetiradaDoGarcom(Pedido $pedido): bool
    {
        return $pedido->pedido_sessao_mesa_id === null && $pedido->pedido_origem === PedidoOrigemEnum::GARCOM;
    }

    /** Assunto sem o sufixo de lembrete (`chamado:12`). */
    public function chaveBase(): string
    {
        return PendenciaPush::chaveBase($this->chave);
    }

    /** URL do toque, com a chave do assunto para a página dizer "já atendido" se for o caso. */
    public function urlComAviso(): string
    {
        return $this->url.'?aviso='.rawurlencode($this->chaveBase());
    }

    private static function chaveDe(string $base, ?int $lembrete): string
    {
        return $lembrete === null ? $base : "{$base}:lembrete:{$lembrete}";
    }

    private static function urlDaMesa(int $sessaoId): string
    {
        return self::caminho(AtenderMesa::getUrl(['sessao' => $sessaoId], panel: 'garcom'));
    }

    private static function caminho(string $url): string
    {
        return (string) parse_url($url, PHP_URL_PATH);
    }
}
