# Diagramas — Rede "Raízes do Nordeste" (Back-end)

Todos os diagramas estão em **Mermaid**: cole cada bloco em https://mermaid.live
para exportar a imagem (PNG/SVG) e anexar ao PDF.

---

## 1. Diagrama de Casos de Uso

Atores: **Cliente** (App/Web/Totem), **Atendente** (Balcão), **Cozinha**,
**Gerente/Administrador**, **Gateway de Pagamento** (sistema externo).

```mermaid
graph LR
    Cliente([Cliente App/Web/Totem])
    Atendente([Atendente - Balcão])
    Cozinha([Cozinha])
    Gerente([Gerente / Administrador])
    Gateway([Gateway de Pagamento])

    subgraph Sistema["API Raízes do Nordeste"]
        UC1((Consultar cardápio da unidade))
        UC2((Realizar pedido))
        UC3((Solicitar pagamento))
        UC4((Acompanhar/atualizar status))
        UC5((Gerir cardápio e estoque))
        UC6((Gerir cadastros da rede))
        UC7((Programa de fidelidade))
        UC8((Consultar relatórios e auditoria))
        UC9((Autenticar))
    end

    Cliente --- UC1
    Cliente --- UC2
    Cliente --- UC3
    Cliente --- UC7
    Atendente --- UC2
    Atendente --- UC3
    Atendente --- UC4
    Atendente --- UC9
    Cozinha --- UC4
    Cozinha --- UC9
    Gerente --- UC5
    Gerente --- UC6
    Gerente --- UC8
    Gerente --- UC9
    UC3 -. confirma .-> Gateway
    Gateway -. webhook .-> UC4
```

> Observação: `UC2 Realizar pedido` inclui `«include»` a validação de estoque;
> `UC3 Solicitar pagamento` dispara, de forma assíncrona, a atualização de
> status (`UC4`) quando o gateway confirma via webhook.

---

## 2. Descrição de Feature (fluxo crítico): Realizar Pedido + Solicitar Pagamento

**Atores:** Cliente/Atendente (criação), Gateway de Pagamento (confirmação),
Cozinha (preparo).

**Pré-condições:**
- Funcionário autenticado (token válido) com papel autorizado.
- Unidade ativa; itens existentes no cardápio da unidade, disponíveis e com estoque.

**Fluxo principal:**
1. Cliente/Atendente envia `POST /api/pedidos` com `canalPedido`, `itens` e, opcionalmente, `cliente_id`.
2. O sistema valida o canal e os itens; abre uma **transação**.
3. Para cada item: confere disponibilidade e faz **baixa de estoque atômica** (`estoque >= quantidade`).
4. Grava **snapshot** de nome/preço por item e calcula `subtotal`, `desconto` (fidelidade) e `total`.
5. Persiste o pedido com status `RECEBIDO` e confirma a transação → responde **201**.
6. `POST /api/pedidos/{id}/pagamentos` solicita a cobrança ao **gateway externo (mock)** → pagamento `PENDENTE` + `gateway_ref`.
7. O gateway notifica `POST /api/pagamentos/webhook` (assinado por HMAC).
8. Em `APROVADO`: pagamento vira `APROVADO`, pedido avança para `EM_PREPARO` e o cliente recebe pontos de fidelidade.
9. Cozinha/Atendente avançam o status: `EM_PREPARO → PRONTO → ENTREGUE`.

**Pós-condições:**
- Pedido persistido com itens, totais e histórico de status.
- Estoque debitado; pontos creditados (se aprovado e cliente identificado).
- Registros de auditoria para criação, pagamento e mudanças de status.

**Exceções e regras de negócio:**
- **Estoque insuficiente** → `409 ESTOQUE_INSUFICIENTE` e **rollback** total.
- **Produto indisponível/unidade inexistente** → `422`/`404`.
- **Canal ausente/ inválido** → `422 VALIDACAO`.
- **Pagamento negado** (`RECUSADO`) → pedido permanece sem avançar; mensagem coerente.
- **Idempotência do webhook**: reenvio da mesma confirmação **não** reprocessa nem duplica pontos (checa o status atual da transação).
- **Não-cumulatividade**: desconto de fidelidade e campanha não se somam (aplica o maior).

---

## 3. Diagrama de Classes (visão de domínio)

```mermaid
classDiagram
    class Regiao { +int id; +string nome }
    class Unidade { +int id; +string nome; +Tipo tipo; +string cidade; +bool ativa }
    class Funcionario { +int id; +string nome; +string email; +Papel papel }
    class Cliente { +int id; +string nome; +string email; +int pontos_saldo }
    class Consentimento { +int id; +Finalidade finalidade; +bool concedido }
    class Categoria { +int id; +string nome }
    class Produto { +int id; +string nome; +decimal preco_base; +bool sazonal }
    class UnidadeProduto { +int id; +decimal preco_local; +bool disponivel; +int estoque }
    class Pedido { +int id; +string codigo; +Canal canalPedido; +Status status; +decimal total }
    class PedidoItem { +int id; +string nome_produto; +decimal preco_unitario; +int quantidade }
    class Pagamento { +int id; +Metodo metodo; +decimal valor; +StatusPg status; +string gateway_ref }
    class PontoFidelidade { +int id; +Tipo tipo; +int pontos }
    class Auditoria { +int id; +string acao; +string entidade; +json dados }

    Regiao "1" --> "*" Unidade
    Unidade "1" --> "*" Funcionario
    Unidade "1" --> "*" UnidadeProduto
    Produto "1" --> "*" UnidadeProduto
    Categoria "1" --> "*" Produto
    Unidade "1" --> "*" Pedido
    Cliente "0..1" --> "*" Pedido
    Funcionario "0..1" --> "*" Pedido
    Pedido "1" --> "*" PedidoItem
    Pedido "1" --> "*" Pagamento
    Cliente "1" --> "*" Consentimento
    Cliente "1" --> "*" PontoFidelidade
    Funcionario "0..1" --> "*" Auditoria
```

---

## 4. Diagrama de Sequência — Pedido → Pagamento → Status

```mermaid
sequenceDiagram
    actor Cliente
    participant API
    participant DB as Banco
    participant Gateway as Gateway (mock)
    actor Cozinha

    Cliente->>API: POST /api/pedidos (itens, canalPedido)
    API->>DB: BEGIN; baixa estoque atômica; snapshot; total
    alt estoque insuficiente
        DB-->>API: falha
        API-->>Cliente: 409 ESTOQUE_INSUFICIENTE (rollback)
    else ok
        DB-->>API: pedido RECEBIDO
        API-->>Cliente: 201 Pedido criado
    end
    Cliente->>API: POST /api/pedidos/{id}/pagamentos (metodo)
    API->>Gateway: solicitar cobrança
    Gateway-->>API: gateway_ref (PENDENTE)
    API-->>Cliente: 201 PENDENTE
    Gateway->>API: POST /api/pagamentos/webhook (HMAC, APROVADO)
    API->>DB: pagamento APROVADO; pedido EM_PREPARO; +pontos
    API-->>Gateway: 200 processado
    Cozinha->>API: PATCH /api/pedidos/{id}/status (PRONTO, ENTREGUE)
    API->>DB: valida máquina de estados; auditoria
    API-->>Cozinha: 200 status atualizado
```
