<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

/**
 * Registro de auditoria de operações sensíveis (cancelamentos, descontos,
 * ajustes, acessos a dados pessoais). Exigência da matriz e da LGPD.
 */
final class Auditoria extends Model
{
    protected string $table = 'auditoria';

    protected array $fillable = [
        'funcionario_id',
        'acao',
        'entidade',
        'entidade_id',
        'descricao',
        'dados_anteriores',
        'dados_novos',
        'ip_origem',
    ];

    /**
     * Registra uma entrada de auditoria. Os dados antes/depois são guardados
     * em JSON para permitir reconstrução e prestação de contas.
     */
    public function registrar(
        ?int $funcionarioId,
        string $acao,
        string $entidade,
        int|string|null $entidadeId = null,
        ?string $descricao = null,
        ?array $anteriores = null,
        ?array $novos = null
    ): int {
        return $this->create([
            'funcionario_id' => $funcionarioId,
            'acao' => $acao,
            'entidade' => $entidade,
            'entidade_id' => $entidadeId !== null ? (string) $entidadeId : null,
            'descricao' => $descricao,
            'dados_anteriores' => $anteriores !== null ? json_encode($anteriores, JSON_UNESCAPED_UNICODE) : null,
            'dados_novos' => $novos !== null ? json_encode($novos, JSON_UNESCAPED_UNICODE) : null,
            'ip_origem' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }

    /** Consulta da trilha de auditoria, com filtros (entidade, ação, funcionário). */
    public function listar(array $filtros, int $limite = 100): array
    {
        $sql = 'SELECT a.*, f.nome AS funcionario_nome
                  FROM auditoria a
                  LEFT JOIN funcionarios f ON f.id = a.funcionario_id
                 WHERE 1=1';
        $params = [];
        foreach (['entidade' => 'a.entidade', 'acao' => 'a.acao', 'funcionario_id' => 'a.funcionario_id'] as $key => $col) {
            if (!empty($filtros[$key])) {
                $sql .= " AND {$col} = :{$key}";
                $params[$key] = $filtros[$key];
            }
        }
        $sql .= ' ORDER BY a.id DESC LIMIT ' . (int) $limite;
        return $this->query($sql, $params);
    }
}
