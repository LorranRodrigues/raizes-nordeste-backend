<?php

declare(strict_types=1);

/**
 * Registro central de rotas da API.
 *
 * A variável $router é injetada pelo front controller (public/index.php).
 * As rotas de cada domínio serão adicionadas conforme as tasks avançam.
 *
 * @var \App\Core\Router $router
 */

use App\Core\Response;

// ---------------------------------------------------------------------------
// Healthcheck — usado para monitoração de disponibilidade (requisito de
// alta disponibilidade do estudo de caso).
// ---------------------------------------------------------------------------
$router->get('/api/health', static function (): void {
    Response::success([
        'status' => 'ok',
        'service' => 'API Raízes do Nordeste',
        'time' => date('c'),
        'php' => PHP_VERSION,
    ]);
});

// ---------------------------------------------------------------------------
// Documentação (OpenAPI / Swagger UI) — pública
// ---------------------------------------------------------------------------
$router->get('/openapi.json', 'DocsController@spec');
$router->get('/docs', 'DocsController@ui');

// ---------------------------------------------------------------------------
// Autenticação
// ---------------------------------------------------------------------------
$router->post('/api/auth/login', 'AuthController@login');
$router->get('/api/auth/me', 'AuthController@me', [\App\Middleware\AuthMiddleware::class]);

// ---------------------------------------------------------------------------
// Cadastros base (catálogo da franqueadora). Leitura exige login; escrita é
// restrita à MATRIZ (validada dentro de cada controller).
// ---------------------------------------------------------------------------
$auth = [\App\Middleware\AuthMiddleware::class];

// Regiões
$router->get('/api/regioes', 'RegiaoController@index', $auth);
$router->get('/api/regioes/{id}', 'RegiaoController@show', $auth);
$router->post('/api/regioes', 'RegiaoController@store', $auth);
$router->put('/api/regioes/{id}', 'RegiaoController@update', $auth);
$router->delete('/api/regioes/{id}', 'RegiaoController@destroy', $auth);

// Unidades
$router->get('/api/unidades', 'UnidadeController@index', $auth);
$router->get('/api/unidades/{id}', 'UnidadeController@show', $auth);
$router->post('/api/unidades', 'UnidadeController@store', $auth);
$router->put('/api/unidades/{id}', 'UnidadeController@update', $auth);
$router->delete('/api/unidades/{id}', 'UnidadeController@destroy', $auth);

// Categorias
$router->get('/api/categorias', 'CategoriaController@index', $auth);
$router->post('/api/categorias', 'CategoriaController@store', $auth);
$router->put('/api/categorias/{id}', 'CategoriaController@update', $auth);

// Produtos (catálogo global)
$router->get('/api/produtos', 'ProdutoController@index', $auth);
$router->get('/api/produtos/{id}', 'ProdutoController@show', $auth);
$router->post('/api/produtos', 'ProdutoController@store', $auth);
$router->put('/api/produtos/{id}', 'ProdutoController@update', $auth);
$router->delete('/api/produtos/{id}', 'ProdutoController@destroy', $auth);

// ---------------------------------------------------------------------------
// Cardápio por unidade
// ---------------------------------------------------------------------------
// Público (cliente/app/totem): só itens disponíveis e com estoque.
$router->get('/api/unidades/{unidadeId}/cardapio', 'CardapioController@publico');
// Gestão (gerente/matriz): visão completa + manutenção do cardápio e estoque.
$router->get('/api/unidades/{unidadeId}/cardapio/gestao', 'CardapioController@index', $auth);
$router->post('/api/unidades/{unidadeId}/cardapio', 'CardapioController@upsert', $auth);
$router->patch('/api/unidades/{unidadeId}/cardapio/{produtoId}/estoque', 'CardapioController@ajustarEstoque', $auth);
$router->delete('/api/unidades/{unidadeId}/cardapio/{produtoId}', 'CardapioController@destroy', $auth);

// ---------------------------------------------------------------------------
// Clientes e fidelidade (LGPD)
// ---------------------------------------------------------------------------
// Cadastro via app é público, mas exige consentimento explícito no corpo.
$router->post('/api/clientes', 'ClienteController@store');
$router->post('/api/clientes/{id}/consentimentos', 'ClienteController@registrarConsentimento');
// Acesso a dados pessoais exige login e é auditado.
$router->get('/api/clientes/{id}', 'ClienteController@show', $auth);
$router->put('/api/clientes/{id}', 'ClienteController@update', $auth);
$router->get('/api/clientes/{id}/fidelidade', 'ClienteController@fidelidade', $auth);
$router->post('/api/clientes/{id}/fidelidade/resgate', 'ClienteController@resgatar', $auth);
$router->get('/api/clientes/{id}/consentimentos', 'ClienteController@consentimentos', $auth);
$router->get('/api/clientes/{id}/dados-pessoais', 'ClienteController@exportarDados', $auth);
$router->post('/api/clientes/{id}/anonimizar', 'ClienteController@anonimizar', $auth);

// ---------------------------------------------------------------------------
// Pedidos
// ---------------------------------------------------------------------------
$router->post('/api/pedidos', 'PedidoController@store', $auth);
$router->get('/api/pedidos', 'PedidoController@index', $auth);
$router->get('/api/pedidos/{id}', 'PedidoController@show', $auth);
$router->patch('/api/pedidos/{id}/status', 'PedidoController@alterarStatus', $auth);

// ---------------------------------------------------------------------------
// Pagamentos (arquitetura desacoplada)
// ---------------------------------------------------------------------------
$router->post('/api/pedidos/{id}/pagamentos', 'PagamentoController@solicitar', $auth);
$router->get('/api/pedidos/{id}/pagamentos', 'PagamentoController@index', $auth);
// Webhook do gateway: público, autenticado por assinatura HMAC (sem login).
$router->post('/api/pagamentos/webhook', 'PagamentoController@webhook');

// ---------------------------------------------------------------------------
// Relatórios e auditoria (matriz)
// ---------------------------------------------------------------------------
$router->get('/api/relatorios/vendas', 'RelatorioController@vendas', $auth);
$router->get('/api/relatorios/produtos-mais-vendidos', 'RelatorioController@produtosMaisVendidos', $auth);
$router->get('/api/relatorios/financeiro', 'RelatorioController@financeiro', $auth);
$router->get('/api/auditoria', 'RelatorioController@auditoria', $auth);
