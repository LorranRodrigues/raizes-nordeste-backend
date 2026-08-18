<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class Regiao extends Model
{
    protected string $table = 'regioes';
    protected array $fillable = ['nome'];
}
