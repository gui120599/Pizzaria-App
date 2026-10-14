<?php

namespace App\Support\Cozinha;

use App\Models\User;
use Livewire\Wireable;

/**
 * A pessoa que está operando um dispositivo compartilhado (tablet da
 * cozinha), que pode ser diferente da conta logada (a da estação). No
 * painel do garçom o operador é sempre o próprio usuário logado.
 */
final readonly class Operador implements Wireable
{
    public function __construct(
        public ?int $userId,
        public ?int $colaboradorId,
        public string $nome,
    ) {}

    public static function doUsuario(User $user): self
    {
        return new self($user->id, null, $user->name_first ?: $user->name);
    }

    /** Usuário vinculado, cujos papéis valem para executar checklists. */
    public function usuario(): ?User
    {
        return $this->userId !== null ? User::find($this->userId) : null;
    }

    /** @return array{user_id: ?int, colaborador_id: ?int, nome: string} */
    public function toLivewire(): array
    {
        return ['user_id' => $this->userId, 'colaborador_id' => $this->colaboradorId, 'nome' => $this->nome];
    }

    /** @param  array{user_id: ?int, colaborador_id: ?int, nome: string}  $value */
    public static function fromLivewire($value): self
    {
        return new self($value['user_id'], $value['colaborador_id'], $value['nome']);
    }
}
