<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class Categoria extends Model
{
    protected string $table = 'categorias';
    protected array $fillable = ['nome', 'descricao', 'ativa'];
}
