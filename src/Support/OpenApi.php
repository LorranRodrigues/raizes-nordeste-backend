<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Especificação OpenAPI 3.0 da API, montada em PHP para reaproveitar o
 * caminho-base da configuração. É servida em /openapi.json e renderizada
 * pelo Swagger UI em /docs. Também pode ser exportada para arquivo
 * (cli/gerar-openapi.php) como entregável do repositório.
 */
final class OpenApi
{
    public static function spec(): array
    {
        $config = require __DIR__ . '/../../config/config.php';
        $base = rtrim($config['app']['base_path'], '/');

        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'API Raízes do Nordeste',
                'version' => '1.0.0',
                'description' => "API REST (PHP puro + MySQL) da rede de lanchonetes \"Raízes do Nordeste\".\n\n"
                    . "Fluxo crítico: **Pedido → Pagamento (mock) → Atualização de status**.\n\n"
                    . "Autentique-se em `POST /api/auth/login` e use o token em `Authorization: Bearer <token>`.",
            ],
            'servers' => [
                ['url' => $base, 'description' => 'XAMPP (Apache) local'],
                ['url' => '', 'description' => 'Servidor embutido (php -S)'],
            ],
            'tags' => [
                ['name' => 'Auth', 'description' => 'Autenticação e identidade'],
                ['name' => 'Cadastros', 'description' => 'Regiões, unidades, categorias, produtos (matriz)'],
                ['name' => 'Cardápio', 'description' => 'Cardápio por unidade e estoque'],
                ['name' => 'Clientes & LGPD', 'description' => 'Clientes, fidelidade e privacidade'],
                ['name' => 'Pedidos', 'description' => 'Pedidos multicanal e máquina de status'],
                ['name' => 'Pagamentos', 'description' => 'Integração desacoplada (mock)'],
                ['name' => 'Relatórios', 'description' => 'Relatórios e auditoria (matriz)'],
            ],
            'components' => self::components(),
            'security' => [['bearerAuth' => []]],
            'paths' => self::paths(),
        ];
    }

    private static function components(): array
    {
        return [
            'securitySchemes' => [
                'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT-like (HMAC)'],
            ],
            'schemas' => [
                'Erro' => [
                    'type' => 'object',
                    'description' => 'Formato padronizado de erro.',
                    'properties' => [
                        'success' => ['type' => 'boolean', 'example' => false],
                        'error' => ['type' => 'string', 'example' => 'VALIDACAO'],
                        'message' => ['type' => 'string', 'example' => 'Dados inválidos.'],
                        'details' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'field' => ['type' => 'string', 'example' => 'email'],
                                    'issue' => ['type' => 'string', 'example' => 'E-mail inválido.'],
                                ],
                            ],
                        ],
                        'timestamp' => ['type' => 'string', 'format' => 'date-time'],
                        'path' => ['type' => 'string', 'example' => '/api/pedidos'],
                        'requestId' => ['type' => 'string', 'example' => 'a1b2c3d4e5f6a7b8'],
                    ],
                ],
                'Paginacao' => [
                    'type' => 'object',
                    'properties' => [
                        'page' => ['type' => 'integer', 'example' => 1],
                        'limit' => ['type' => 'integer', 'example' => 10],
                        'total' => ['type' => 'integer', 'example' => 42],
                        'totalPages' => ['type' => 'integer', 'example' => 5],
                    ],
                ],
                'Login' => [
                    'type' => 'object',
                    'required' => ['email', 'senha'],
                    'properties' => [
                        'email' => ['type' => 'string', 'example' => 'matriz@raizes.com'],
                        'senha' => ['type' => 'string', 'example' => 'senha123'],
                    ],
                ],
                'PedidoCriacao' => [
                    'type' => 'object',
                    'required' => ['unidade_id', 'canalPedido', 'itens'],
                    'properties' => [
                        'unidade_id' => ['type' => 'integer', 'example' => 1],
                        'canalPedido' => ['type' => 'string', 'enum' => ['APP', 'TOTEM', 'BALCAO', 'PICKUP', 'WEB'], 'example' => 'APP'],
                        'cliente_id' => ['type' => 'integer', 'nullable' => true, 'example' => 1],
                        'usar_pontos' => ['type' => 'boolean', 'example' => true],
                        'observacao' => ['type' => 'string', 'nullable' => true],
                        'itens' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'required' => ['produto_id', 'quantidade'],
                                'properties' => [
                                    'produto_id' => ['type' => 'integer', 'example' => 1],
                                    'quantidade' => ['type' => 'integer', 'example' => 2],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'responses' => [
                'Erro400' => ['description' => 'Requisição inválida', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Erro']]]],
                'Erro401' => ['description' => 'Não autenticado', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Erro']]]],
                'Erro403' => ['description' => 'Sem permissão', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Erro']]]],
                'Erro404' => ['description' => 'Não encontrado', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Erro']]]],
                'Erro409' => ['description' => 'Conflito / regra de negócio', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Erro']]]],
                'Erro422' => ['description' => 'Erro de validação', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Erro']]]],
            ],
        ];
    }

    /** Atalho para respostas de erro comuns. */
    private static function erros(array $codigos): array
    {
        $map = [];
        foreach ($codigos as $c) {
            $map[(string) $c] = ['$ref' => "#/components/responses/Erro{$c}"];
        }
        return $map;
    }

    private static function okJson(string $desc): array
    {
        return ['description' => $desc, 'content' => ['application/json' => ['schema' => ['type' => 'object']]]];
    }

    private static function paths(): array
    {
        return [
            '/api/health' => [
                'get' => [
                    'tags' => ['Auth'], 'summary' => 'Healthcheck (disponibilidade)',
                    'security' => [], 'responses' => ['200' => self::okJson('Serviço online')],
                ],
            ],
            '/api/auth/login' => [
                'post' => [
                    'tags' => ['Auth'], 'summary' => 'Autenticar e obter token', 'security' => [],
                    'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Login']]]],
                    'responses' => ['200' => self::okJson('Token emitido')] + self::erros([401, 422]),
                ],
            ],
            '/api/auth/me' => [
                'get' => ['tags' => ['Auth'], 'summary' => 'Funcionário autenticado',
                    'responses' => ['200' => self::okJson('Dados do token')] + self::erros([401])],
            ],

            '/api/unidades' => [
                'get' => ['tags' => ['Cadastros'], 'summary' => 'Listar unidades',
                    'responses' => ['200' => self::okJson('Lista de unidades')] + self::erros([401])],
                'post' => ['tags' => ['Cadastros'], 'summary' => 'Criar unidade (MATRIZ)',
                    'responses' => ['201' => self::okJson('Unidade criada')] + self::erros([401, 403, 422])],
            ],
            '/api/unidades/{id}' => [
                'get' => ['tags' => ['Cadastros'], 'summary' => 'Detalhe da unidade',
                    'parameters' => [self::pathId()], 'responses' => ['200' => self::okJson('Unidade')] + self::erros([401, 404])],
                'put' => ['tags' => ['Cadastros'], 'summary' => 'Atualizar unidade (MATRIZ)',
                    'parameters' => [self::pathId()], 'responses' => ['200' => self::okJson('Atualizada')] + self::erros([401, 403, 404, 422])],
                'delete' => ['tags' => ['Cadastros'], 'summary' => 'Desativar unidade (MATRIZ)',
                    'parameters' => [self::pathId()], 'responses' => ['200' => self::okJson('Desativada')] + self::erros([401, 403, 404])],
            ],
            '/api/produtos' => [
                'get' => ['tags' => ['Cadastros'], 'summary' => 'Listar produtos (paginado)',
                    'parameters' => [self::queryPage(), self::queryLimit(), ['name' => 'categoria_id', 'in' => 'query', 'schema' => ['type' => 'integer']]],
                    'responses' => ['200' => self::okJson('Lista paginada (data + meta)')] + self::erros([401])],
                'post' => ['tags' => ['Cadastros'], 'summary' => 'Criar produto no catálogo (MATRIZ)',
                    'responses' => ['201' => self::okJson('Produto criado')] + self::erros([401, 403, 422])],
            ],
            '/api/produtos/{id}' => [
                'get' => ['tags' => ['Cadastros'], 'summary' => 'Detalhe do produto', 'parameters' => [self::pathId()],
                    'responses' => ['200' => self::okJson('Produto')] + self::erros([401, 404])],
                'put' => ['tags' => ['Cadastros'], 'summary' => 'Atualizar produto (MATRIZ)', 'parameters' => [self::pathId()],
                    'responses' => ['200' => self::okJson('Atualizado')] + self::erros([401, 403, 404, 422])],
                'delete' => ['tags' => ['Cadastros'], 'summary' => 'Inativar produto (MATRIZ)', 'parameters' => [self::pathId()],
                    'responses' => ['200' => self::okJson('Inativado')] + self::erros([401, 403, 404])],
            ],

            '/api/unidades/{unidadeId}/cardapio' => [
                'get' => ['tags' => ['Cardápio'], 'summary' => 'Cardápio público (itens vendáveis)', 'security' => [],
                    'parameters' => [self::pathParam('unidadeId')], 'responses' => ['200' => self::okJson('Cardápio')] + self::erros([404])],
                'post' => ['tags' => ['Cardápio'], 'summary' => 'Incluir/atualizar item no cardápio (GERENTE/MATRIZ)',
                    'parameters' => [self::pathParam('unidadeId')], 'responses' => ['200' => self::okJson('Item do cardápio')] + self::erros([401, 403, 404, 422])],
            ],
            '/api/unidades/{unidadeId}/cardapio/{produtoId}/estoque' => [
                'patch' => ['tags' => ['Cardápio'], 'summary' => 'Ajustar estoque (GERENTE/MATRIZ)',
                    'parameters' => [self::pathParam('unidadeId'), self::pathParam('produtoId')],
                    'responses' => ['200' => self::okJson('Estoque ajustado')] + self::erros([401, 403, 404, 422])],
            ],

            '/api/clientes' => [
                'post' => ['tags' => ['Clientes & LGPD'], 'summary' => 'Cadastrar cliente (consentimento obrigatório)', 'security' => [],
                    'responses' => ['201' => self::okJson('Cliente criado')] + self::erros([409, 422])],
            ],
            '/api/clientes/{id}' => [
                'get' => ['tags' => ['Clientes & LGPD'], 'summary' => 'Consultar cliente (acesso auditado)', 'parameters' => [self::pathId()],
                    'responses' => ['200' => self::okJson('Cliente + fidelidade')] + self::erros([401, 403, 404])],
            ],
            '/api/clientes/{id}/fidelidade' => [
                'get' => ['tags' => ['Clientes & LGPD'], 'summary' => 'Extrato de fidelidade', 'parameters' => [self::pathId()],
                    'responses' => ['200' => self::okJson('Saldo + extrato')] + self::erros([401, 404])],
            ],
            '/api/clientes/{id}/fidelidade/resgate' => [
                'post' => ['tags' => ['Clientes & LGPD'], 'summary' => 'Resgatar pontos (mín. 100)', 'parameters' => [self::pathId()],
                    'responses' => ['200' => self::okJson('Voucher gerado')] + self::erros([401, 409, 422])],
            ],
            '/api/clientes/{id}/consentimentos' => [
                'post' => ['tags' => ['Clientes & LGPD'], 'summary' => 'Registrar/revogar consentimento', 'security' => [], 'parameters' => [self::pathId()],
                    'responses' => ['200' => self::okJson('Status de consentimentos')] + self::erros([404, 422])],
            ],
            '/api/clientes/{id}/dados-pessoais' => [
                'get' => ['tags' => ['Clientes & LGPD'], 'summary' => 'Exportar dados (portabilidade)', 'parameters' => [self::pathId()],
                    'responses' => ['200' => self::okJson('Dados pessoais')] + self::erros([401, 403, 404])],
            ],
            '/api/clientes/{id}/anonimizar' => [
                'post' => ['tags' => ['Clientes & LGPD'], 'summary' => 'Anonimizar (esquecimento)', 'parameters' => [self::pathId()],
                    'responses' => ['200' => self::okJson('Anonimizado')] + self::erros([401, 403, 404])],
            ],

            '/api/pedidos' => [
                'get' => ['tags' => ['Pedidos'], 'summary' => 'Listar pedidos (paginado, filtros)',
                    'parameters' => [self::queryPage(), self::queryLimit(),
                        ['name' => 'canalPedido', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['APP', 'TOTEM', 'BALCAO', 'PICKUP', 'WEB']]],
                        ['name' => 'status', 'in' => 'query', 'schema' => ['type' => 'string']],
                        ['name' => 'unidade_id', 'in' => 'query', 'schema' => ['type' => 'integer']]],
                    'responses' => ['200' => self::okJson('Lista paginada')] + self::erros([401])],
                'post' => ['tags' => ['Pedidos'], 'summary' => 'Criar pedido (fluxo crítico)',
                    'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/PedidoCriacao']]]],
                    'responses' => ['201' => self::okJson('Pedido criado')] + self::erros([401, 403, 404, 409, 422])],
            ],
            '/api/pedidos/{id}' => [
                'get' => ['tags' => ['Pedidos'], 'summary' => 'Detalhe do pedido', 'parameters' => [self::pathId()],
                    'responses' => ['200' => self::okJson('Pedido + itens + pagamento')] + self::erros([401, 403, 404])],
            ],
            '/api/pedidos/{id}/status' => [
                'patch' => ['tags' => ['Pedidos'], 'summary' => 'Avançar/cancelar status',
                    'parameters' => [self::pathId()],
                    'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['status' => ['type' => 'string', 'enum' => ['EM_PREPARO', 'PRONTO', 'ENTREGUE', 'CANCELADO']]]]]]],
                    'responses' => ['200' => self::okJson('Status alterado')] + self::erros([401, 403, 404, 422])],
            ],

            '/api/pedidos/{id}/pagamentos' => [
                'post' => ['tags' => ['Pagamentos'], 'summary' => 'Solicitar cobrança ao gateway (mock)', 'parameters' => [self::pathId()],
                    'responses' => ['201' => self::okJson('Cobrança PENDENTE + gateway_ref')] + self::erros([401, 403, 404, 409, 422])],
                'get' => ['tags' => ['Pagamentos'], 'summary' => 'Listar pagamentos do pedido', 'parameters' => [self::pathId()],
                    'responses' => ['200' => self::okJson('Pagamentos')] + self::erros([401, 404])],
            ],
            '/api/pagamentos/webhook' => [
                'post' => ['tags' => ['Pagamentos'], 'summary' => 'Webhook do gateway (assinado HMAC, idempotente)', 'security' => [],
                    'parameters' => [['name' => 'X-Webhook-Signature', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string']]],
                    'responses' => ['200' => self::okJson('Processado')] + self::erros([401, 404, 422])],
            ],

            '/api/relatorios/vendas' => [
                'get' => ['tags' => ['Relatórios'], 'summary' => 'Vendas por unidade/região (MATRIZ)',
                    'parameters' => [self::queryData('data_inicio'), self::queryData('data_fim')],
                    'responses' => ['200' => self::okJson('Vendas consolidadas')] + self::erros([401, 403])],
            ],
            '/api/relatorios/produtos-mais-vendidos' => [
                'get' => ['tags' => ['Relatórios'], 'summary' => 'Produtos mais consumidos (MATRIZ)',
                    'responses' => ['200' => self::okJson('Ranking')] + self::erros([401, 403])],
            ],
            '/api/relatorios/financeiro' => [
                'get' => ['tags' => ['Relatórios'], 'summary' => 'Resumo financeiro (MATRIZ)',
                    'responses' => ['200' => self::okJson('Faturamento por canal')] + self::erros([401, 403])],
            ],
            '/api/auditoria' => [
                'get' => ['tags' => ['Relatórios'], 'summary' => 'Trilha de auditoria (MATRIZ)',
                    'parameters' => [['name' => 'entidade', 'in' => 'query', 'schema' => ['type' => 'string']], ['name' => 'acao', 'in' => 'query', 'schema' => ['type' => 'string']]],
                    'responses' => ['200' => self::okJson('Registros de auditoria')] + self::erros([401, 403])],
            ],
        ];
    }

    private static function pathId(): array
    {
        return ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']];
    }

    private static function pathParam(string $name): array
    {
        return ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']];
    }

    private static function queryPage(): array
    {
        return ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 1]];
    }

    private static function queryLimit(): array
    {
        return ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 10, 'maximum' => 100]];
    }

    private static function queryData(string $name): array
    {
        return ['name' => $name, 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'date'], 'description' => 'AAAA-MM-DD'];
    }
}
