<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class Unidade extends Model
{
    protected string $table = 'unidades';
    protected array $fillable = [
        'regiao_id', 'nome', 'tipo', 'cidade', 'estado', 'endereco', 'telefone', 'ativa',
    ];

    /** Lista unidades com o nome da região (join), para relatórios e listagem. */
    public function comRegiao(): array
    {
        return $this->query(
            'SELECT u.*, r.nome AS regiao_nome
               FROM unidades u
               JOIN regioes r ON r.id = u.regiao_id
              ORDER BY u.nome'
        );
    }
}
