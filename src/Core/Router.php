<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Roteador simples baseado em padrões de URL.
 *
 * Suporta parâmetros nomeados no formato {nome} (ex.: /pedidos/{id}).
 * A action pode ser:
 *   - um callable, ou
 *   - "Classe@metodo" (resolvido sob o namespace App\Controllers).
 */
final class Router
{
    /** @var array<int, array{method:string, pattern:string, action:mixed, middlewares:array}> */
    private array $routes = [];
    private string $basePath;

    public function __construct()
    {
        $config = require __DIR__ . '/../../config/config.php';
        $this->basePath = rtrim($config['app']['base_path'], '/');
    }

    public function get(string $path, mixed $action, array $middlewares = []): void
    {
        $this->add('GET', $path, $action, $middlewares);
    }

    public function post(string $path, mixed $action, array $middlewares = []): void
    {
        $this->add('POST', $path, $action, $middlewares);
    }

    public function put(string $path, mixed $action, array $middlewares = []): void
    {
        $this->add('PUT', $path, $action, $middlewares);
    }

    public function patch(string $path, mixed $action, array $middlewares = []): void
    {
        $this->add('PATCH', $path, $action, $middlewares);
    }

    public function delete(string $path, mixed $action, array $middlewares = []): void
    {
        $this->add('DELETE', $path, $action, $middlewares);
    }

    private function add(string $method, string $path, mixed $action, array $middlewares): void
    {
        $this->routes[] = [
            'method' => $method,
            'pattern' => $this->compile($path),
            'action' => $action,
            'middlewares' => $middlewares,
        ];
    }

    /** Converte "/pedidos/{id}" em uma regex com grupos nomeados. */
    private function compile(string $path): string
    {
        $path = '/' . trim($path, '/');
        $regex = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?<$1>[^/]+)', $path);
        return '#^' . $regex . '$#';
    }

    public function dispatch(string $method, string $uri): void
    {
        // Remove o caminho-base (ex.: /lorran/apiBackendUninter/public).
        if ($this->basePath !== '' && str_starts_with($uri, $this->basePath)) {
            $uri = substr($uri, strlen($this->basePath));
        }
        $uri = '/' . trim($uri, '/');
        if ($uri === '/') {
            Response::success(['service' => 'API Raízes do Nordeste', 'status' => 'online']);
        }

        $allowedMethods = [];

        foreach ($this->routes as $route) {
            if (!preg_match($route['pattern'], $uri, $matches)) {
                continue;
            }
            if ($route['method'] !== $method) {
                $allowedMethods[] = $route['method'];
                continue;
            }

            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            $request = new Request($params);

            // Executa middlewares (auth, papéis etc.) antes do controller.
            foreach ($route['middlewares'] as $middleware) {
                (new $middleware())->handle($request);
            }

            $this->run($route['action'], $request);
            return;
        }

        if ($allowedMethods !== []) {
            header('Allow: ' . implode(', ', array_unique($allowedMethods)));
            Response::error('METODO_NAO_PERMITIDO', 'Método não permitido para esta rota.', 405);
        }

        Response::error('NAO_ENCONTRADO', 'Rota não encontrada: ' . $uri, 404);
    }

    private function run(mixed $action, Request $request): void
    {
        if (is_callable($action)) {
            $action($request);
            return;
        }

        if (is_string($action) && str_contains($action, '@')) {
            [$class, $method] = explode('@', $action, 2);
            $fqcn = 'App\\Controllers\\' . $class;
            if (!class_exists($fqcn)) {
                throw new HttpException("Controller não encontrado: {$fqcn}", 500);
            }
            (new $fqcn())->{$method}($request);
            return;
        }

        throw new HttpException('Ação de rota inválida.', 500);
    }
}
