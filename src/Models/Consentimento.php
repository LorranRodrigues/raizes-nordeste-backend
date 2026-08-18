<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/**
 * Consentimentos LGPD — cada decisão do cliente gera um registro imutável,
 * formando a trilha de consentimento por finalidade.
 */
final class Consentimento extends Model
{
    protected string $table = 'consentimentos_lgpd';
    protected array $fillable = ['cliente_id', 'finalidade', 'concedido', 'versao_termo', 'ip_origem'];

    public const FINALIDADES = ['FIDELIDADE', 'MARKETING', 'PERSONALIZACAO'];

    /**
     * Estado atual do consentimento por finalidade (última decisão de cada uma).
     * @return array<string,bool>
     */
    public function statusAtual(int $clienteId): array
    {
        $linhas = $this->query(
            'SELECT c1.finalidade, c1.concedido
               FROM consentimentos_lgpd c1
               JOIN (
                   SELECT finalidade, MAX(id) AS max_id
                     FROM consentimentos_lgpd
                    WHERE cliente_id = :cid
                    GROUP BY finalidade
               ) ult ON ult.max_id = c1.id',
            ['cid' => $clienteId]
        );

        $status = array_fill_keys(self::FINALIDADES, false);
        foreach ($linhas as $linha) {
            $status[$linha['finalidade']] = (int) $linha['concedido'] === 1;
        }
        return $status;
    }

    public function historico(int $clienteId): array
    {
        return $this->all(['cliente_id' => $clienteId], 'id DESC');
    }
}
