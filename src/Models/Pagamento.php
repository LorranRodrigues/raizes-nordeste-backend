<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class Pagamento extends Model
{
    protected string $table = 'pagamentos';
    protected array $fillable = [
        'pedido_id', 'metodo', 'valor', 'status', 'gateway_ref', 'payload_retorno',
    ];

    public function porGatewayRef(string $ref): ?array
    {
        return $this->firstWhere(['gateway_ref' => $ref]);
    }
}
