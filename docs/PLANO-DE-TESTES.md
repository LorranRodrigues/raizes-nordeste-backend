# Plano de Testes — API Raízes do Nordeste

Validação reproduzível da API. Todos os cenários abaixo estão **executáveis** na
coleção [`RaizesDoNordeste.postman_collection.json`](RaizesDoNordeste.postman_collection.json),
organizada em pastas (Auth, Cadastros, Cardápio, Clientes & LGPD, Pedidos,
Pagamento, Relatórios, Erros).

## Como executar
1. Importe a coleção no Postman/Insomnia.
2. Rode **Auth → T01 Login válido (matriz)** (salva `{{token}}` automaticamente) e
   **T02 Login gerente** (salva `{{token_gerente}}` para o teste de permissão).
3. Pré-requisito: schema aplicado e `php cli/seed.php` executado.
4. Execute as pastas na ordem; o `pedido_id` e o `gateway_ref` são propagados
   entre as requisições por scripts.

## Cobertura (16 cenários — 10 positivos, 6 negativos)

| ID | Cenário | Endpoint | Pré-condição | Entrada | Esperado (status + response) | Evidência (Postman) |
|---|---|---|---|---|---|---|
| **T01** | Login válido | POST /api/auth/login | seed aplicado | email + senha | 200 + `data.token` | Auth/T01 Login válido |
| **T02** | Login gerente | POST /api/auth/login | seed | credenciais gerente | 200 + token | Auth/T02 Login gerente |
| **T03** | Criar produto (matriz) | POST /api/produtos | logado MATRIZ | categoria, nome, preço | 201 + produto | Cadastros/T03 Criar produto |
| **T04** | Listar produtos paginado | GET /api/produtos?page=1&limit=5 | logado | query page/limit | 200 + `data` + `meta` | Cadastros/T04 Listar produtos |
| **T05** | Cardápio público | GET /api/unidades/1/cardapio | unidade com itens | — | 200, só itens disponíveis com estoque | Cardápio/T05 Cardápio público |
| **T06** | Resgatar pontos | POST /api/clientes/1/fidelidade/resgate | cliente com saldo ≥100 | `{pontos:100}` | 200 + voucher + saldo atualizado | Clientes/T06 Resgatar pontos |
| **T07** | Criar pedido (fluxo crítico) | POST /api/pedidos | logado; estoque ok | canalPedido + itens | 201 + pedido (estoque debitado) | Pedidos/T07 Criar pedido |
| **T08** | Filtrar pedidos por canal | GET /api/pedidos?canalPedido=APP | logado | query canalPedido | 200 + lista do canal | Pedidos/T08 Listar por canal |
| **T09** | Avançar status | PATCH /api/pedidos/{id}/status | pedido RECEBIDO | `{status:EM_PREPARO}` | 200 + status atualizado | Pedidos/T09 Avançar status |
| **T10** | Solicitar pagamento (mock) | POST /api/pedidos/{id}/pagamentos | pedido pagável | `{metodo:PIX}` | 201 + PENDENTE + gateway_ref | Pagamento/T10 Solicitar |
| **T11** | Pagamento aprovado → atualiza status | POST /api/pagamentos/webhook | gateway_ref + HMAC | `{status:APROVADO}` | 200; pedido→EM_PREPARO; +pontos | Pagamento/T11 Webhook APROVADO |
| **T12** | Acesso sem token | GET /api/pedidos | — | sem Authorization | **401** NAO_AUTENTICADO | Erros/T12 Sem token |
| **T13** | Sem permissão (RBAC) | GET /api/relatorios/financeiro | logado GERENTE | token_gerente | **403** ACESSO_NEGADO | Erros/T13 Sem permissão |
| **T14** | Validação — canalPedido ausente | POST /api/pedidos | logado | sem canalPedido | **422** VALIDACAO + details[] | Erros/T14 Validação |
| **T15** | Regra — estoque insuficiente | POST /api/pedidos | logado | quantidade 999999 | **409** ESTOQUE_INSUFICIENTE (rollback) | Erros/T15 Estoque insuficiente |
| **T16** | Pagamento mock RECUSADO | POST /api/pagamentos/webhook | gateway_ref + HMAC | `{status:RECUSADO}` | 200; pagamento RECUSADO; pedido não avança | Erros/T16 Pagamento recusado |

## Cobertura obrigatória do roteiro

- **Autenticação/autorização:** T01 (login), T12 (401 sem token), T13 (403 sem permissão). ✅
- **Validação de dados:** T14 (campo obrigatório ausente, 422). ✅
- **Regra de negócio do fluxo:** T07 (201), T15 (409 estoque). Produto/unidade inexistente → 404 (ex.: `GET /api/produtos/9999`). ✅
- **Pagamento mock sucesso e falha:** T11 (APROVADO → status atualizado), T16 (RECUSADO → coerente). ✅
- **Logs/auditoria:** **IMPLEMENTADO**. Ações sensíveis (criação/cancelamento de pedido, mudança de status, acesso a dados pessoais) geram registro consultável em `GET /api/auditoria` (pasta Relatórios). Evidência: criar/cancelar um pedido e listar a auditoria. ✅

## Cenários complementares (também na coleção)

- Webhook sem assinatura → **401** (segurança da integração).
- Idempotência: reenviar T11 não duplica pontos (verificar saldo em `/fidelidade`).
- Cadastro de cliente sem consentimento → **422** (LGPD).

## Estratégia não funcional (recomendada)

| Foco | Métrica/alvo | Ferramenta |
|---|---|---|
| Desempenho em pico (cardápio público) | vazão sem erros | Apache JMeter |
| Concorrência de estoque | sem venda além do saldo (baixa atômica) | JMeter (threads paralelas) |
| Disponibilidade | `/api/health` < 200 ms | monitor/uptime |
| Segurança | headers, injeção, autorização | OWASP ZAP |
