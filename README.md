# API Backend — Rede "Raízes do Nordeste"

API REST em **PHP puro (8.2)** + **MySQL/MariaDB**, desenvolvida sobre o XAMPP,
para o estudo de caso da disciplina de Projeto Multidisciplinar — **Trilha Back-end**.

Cobre a jornada multicanal de pedidos (APP/TOTEM/BALCAO/PICKUP/WEB), a gestão da
franquia pela matriz, o programa de fidelidade com conformidade **LGPD**, o
pagamento **desacoplado** via gateway externo (mock) e a **auditoria** de
operações sensíveis. Fluxo crítico entregue ponta a ponta:
**Pedido → Pagamento (mock) → Atualização de status**.

---

## 1. Requisitos

| Item | Versão |
|---|---|
| PHP | 8.1+ (testado em 8.2) |
| Banco | MySQL 8 ou MariaDB 10.4+ (XAMPP) |
| Servidor | Apache (XAMPP) ou servidor embutido do PHP |
| Dependências | Nenhuma (PHP puro, sem Composer) |

## 2. Configuração (variáveis de ambiente)

```bash
cp .env.example .env   # ajuste DB_*, AUTH_SECRET etc. se necessário
```

As variáveis são carregadas automaticamente pelo `bootstrap.php`. Os padrões já
funcionam para o XAMPP local (root sem senha). Ao usar o servidor embutido,
defina `APP_BASE_PATH=` (vazio).

## 3. Banco de dados (migration + seed)

```bash
# cria o schema (DER físico) — 13 tabelas
/c/xampp/mysql/bin/mysql -u root < database/schema.sql

# popula dados de demonstração (unidades, equipe, catálogo, pedidos pagos)
/c/xampp/php/php cli/seed.php
```

## 4. Iniciar a API

```bash
# Opção A — Apache (projeto dentro de htdocs):
#   http://localhost/lorran/apiBackendUninter/public/api/health

# Opção B — servidor embutido do PHP (defina APP_BASE_PATH= no .env):
/c/xampp/php/php -S 127.0.0.1:8000 -t public public/index.php
#   http://127.0.0.1:8000/api/health
```

## 5. Documentação da API (Swagger/OpenAPI)

- **Swagger UI:** `GET /docs` → `http://localhost/lorran/apiBackendUninter/public/docs`
- **Especificação:** `GET /openapi.json` (arquivo também versionado em
  [`docs/openapi.json`](docs/openapi.json))
- Regenerar o arquivo: `php cli/gerar-openapi.php`

## 6. Credenciais (seed)

| Papel | E-mail | Senha |
|---|---|---|
| Matriz | matriz@raizes.com | senha123 |
| Gerente (Recife) | gerente.recife@raizes.com | senha123 |
| Atendente (Recife) | atendente.recife@raizes.com | senha123 |
| Cozinheiro (Recife) | cozinha.recife@raizes.com | senha123 |

## 7. Testes

- **Coleção Postman:** [`docs/RaizesDoNordeste.postman_collection.json`](docs/RaizesDoNordeste.postman_collection.json)
  (faça o *Login* primeiro — o token é salvo automaticamente).
- **Plano de testes (T01…):** [`docs/PLANO-DE-TESTES.md`](docs/PLANO-DE-TESTES.md)
- Ordem sugerida: Auth → Cadastros → Cardápio → Pedido → Pagamento (webhook) → Relatórios.

## 8. Principais endpoints

| Método | Rota | Descrição | Acesso |
|---|---|---|---|
| GET | `/api/health` | Healthcheck | Público |
| GET | `/docs`, `/openapi.json` | Documentação Swagger/OpenAPI | Público |
| POST | `/api/auth/login` | Login, retorna token | Público |
| GET | `/api/auth/me` | Funcionário autenticado | Token |
| GET/POST/PUT/DELETE | `/api/regioes` `/api/unidades` `/api/categorias` `/api/produtos` | Cadastros (paginados) | Leitura: token · Escrita: Matriz |
| GET | `/api/unidades/{id}/cardapio` | Cardápio público (só vendável) | Público |
| POST | `/api/unidades/{id}/cardapio` | Incluir/atualizar item | Gerente/Matriz |
| PATCH | `/api/unidades/{id}/cardapio/{produtoId}/estoque` | Ajustar estoque | Gerente/Matriz |
| POST | `/api/clientes` | Cadastro (exige consentimento) | Público |
| GET | `/api/clientes/{id}` | Consulta (auditada) | Token |
| GET | `/api/clientes/{id}/fidelidade` | Saldo + extrato | Token |
| POST | `/api/clientes/{id}/fidelidade/resgate` | Resgate de pontos | Token |
| GET | `/api/clientes/{id}/dados-pessoais` | Exportar dados (LGPD) | Gerente/Matriz |
| POST | `/api/clientes/{id}/anonimizar` | Anonimizar (LGPD) | Gerente/Matriz |
| POST | `/api/pedidos` | Criar pedido (`canalPedido` obrigatório) | Atendente/Gerente/Matriz |
| GET | `/api/pedidos?canalPedido=APP&page=1&limit=10` | Listar (paginado, filtros) | Token |
| PATCH | `/api/pedidos/{id}/status` | Avançar/cancelar (máquina de estados) | Conforme transição |
| POST | `/api/pedidos/{id}/pagamentos` | Solicitar cobrança (mock) | Atendente/Gerente/Matriz |
| POST | `/api/pagamentos/webhook` | Confirmação do gateway (HMAC) | Externo (assinado) |
| GET | `/api/relatorios/vendas` `/financeiro` `/produtos-mais-vendidos` | Relatórios | Matriz |
| GET | `/api/auditoria` | Trilha de auditoria | Matriz |

### Padrão de resposta

```jsonc
// Sucesso
{ "success": true, "data": { ... }, "meta": { "page": 1, "limit": 10, "total": 42, "totalPages": 5 } }

// Erro (padronizado)
{
  "success": false,
  "error": "ESTOQUE_INSUFICIENTE",
  "message": "Estoque insuficiente para o produto 1.",
  "details": [ { "field": "itens.produto_id", "issue": "..." } ],
  "timestamp": "2026-06-29T21:59:49-03:00",
  "path": "/api/pedidos",
  "requestId": "a1b2c3d4e5f6a7b8"
}
```

Status codes usados: `200, 201, 204, 400, 401, 403, 404, 409, 422`.

## 9. Documentação complementar

| Documento | Conteúdo |
|---|---|
| [database/MODELO-DADOS.md](database/MODELO-DADOS.md) | DER (Mermaid) + entidades |
| [docs/DIAGRAMAS.md](docs/DIAGRAMAS.md) | Casos de uso, classes, sequência, descrição de feature |
| [docs/ARQUITETURA.md](docs/ARQUITETURA.md) | Camadas Domain/Application/Infra/API |
| [docs/PLANO-DE-TESTES.md](docs/PLANO-DE-TESTES.md) | Cenários de teste (positivos e negativos) |
| [docs/PROMOCOES-CAMPANHAS.md](docs/PROMOCOES-CAMPANHAS.md) | Regras de fidelidade/promoções |

## 10. Segurança e LGPD

- Senhas com **bcrypt**; nunca retornadas em respostas.
- Token assinado (HMAC-SHA256) + autorização por papéis (RBAC) em todos os endpoints sensíveis.
- **LGPD**: consentimento explícito e versionado, acesso a dados pessoais auditado,
  anonimização (esquecimento) e exportação (portabilidade).
- **Auditoria** de operações sensíveis (cancelamento, desconto, ajuste de estoque, acesso a dados).

## 11. Estrutura de pastas

```
config/        Configuração (lê variáveis de ambiente / .env)
database/      schema.sql + DER (MODELO-DADOS.md)
cli/           Utilitários (seed, criar-funcionario, gerar-openapi)
docs/          Swagger (openapi.json), Postman, plano de testes, diagramas
public/        Front controller + .htaccess
routes/        Definição de rotas
src/Core/      Router, Request, Response, Model, Database, Validator, Controller, HttpException
src/Middleware/AuthMiddleware
src/Models/    Domínio (Pedido, Cliente, Produto, Pagamento, ...)
src/Controllers/ Interface REST
src/Support/   Token, Fidelidade, GatewaySimulado, Pagination, OpenApi
```

## 12. Evidências para correção

- **Repositório:** _(inserir link público do GitHub aqui)_
- **Swagger:** rota `/docs` (ver seção 5) — local
- **Coleção Postman:** [`docs/RaizesDoNordeste.postman_collection.json`](docs/RaizesDoNordeste.postman_collection.json)
- **DER:** [`database/MODELO-DADOS.md`](database/MODELO-DADOS.md)
