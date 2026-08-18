<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Request;

/**
 * Utilitário de paginação. Lê page/limit da query string com limites seguros
 * e produz os metadados padronizados retornados em "meta".
 */
final class Pagination
{
    private const LIMIT_PADRAO = 10;
    private const LIMIT_MAXIMO = 100;

    /** @return array{page:int, limit:int, offset:int} */
    public static function params(Request $request): array
    {
        $page = max(1, (int) $request->query('page', 1));
        $limit = (int) $request->query('limit', self::LIMIT_PADRAO);
        $limit = max(1, min(self::LIMIT_MAXIMO, $limit));
        return ['page' => $page, 'limit' => $limit, 'offset' => ($page - 1) * $limit];
    }

    /** Metadados de paginação para o envelope "meta". */
    public static function meta(int $total, int $page, int $limit): array
    {
        return [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'totalPages' => (int) ceil($total / max(1, $limit)),
        ];
    }
}
