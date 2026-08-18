<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/**
 * Funcionário do sistema (atendente, cozinheiro, gerente, matriz).
 */
final class Funcionario extends Model
{
    protected string $table = 'funcionarios';

    protected array $fillable = [
        'unidade_id',
        'nome',
        'email',
        'senha_hash',
        'papel',
        'ativo',
    ];

    public function findByEmail(string $email): ?array
    {
        return $this->firstWhere(['email' => $email]);
    }

    /** Remove campos sensíveis antes de devolver na API. */
    public static function publico(array $funcionario): array
    {
        unset($funcionario['senha_hash']);
        return $funcionario;
    }
}
