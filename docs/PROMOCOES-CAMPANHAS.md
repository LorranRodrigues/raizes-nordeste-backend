# Promoções e Campanhas — Regras de Negócio

Documento da regra de promoções/campanhas da rede "Raízes do Nordeste".

## 1. Descontos progressivos de fidelidade (IMPLEMENTADO)

Benefício automático por faixa de saldo de pontos, aplicado no momento do pedido
quando o cliente opta por `usar_pontos`:

| Saldo de pontos | Desconto |
|---|---|
| ≥ 100 | 5% |
| ≥ 300 | 10% |
| ≥ 600 | 15% |

Implementado em [`src/Support/Fidelidade.php`](../src/Support/Fidelidade.php)
e aplicado em `POST /api/pedidos`. O desconto é gravado no campo `desconto` do
pedido (rastreável e auditável).

## 2. Resgate de pontos (IMPLEMENTADO)

`POST /api/clientes/{id}/fidelidade/resgate` — o cliente troca pontos por um
voucher de desconto. Cada ponto vale **R$ 0,10**; resgate mínimo de **100 pontos**.
Gera um lançamento `DEBITO` no livro-razão de pontos.

## 3. Itens sazonais / campanhas regionais (IMPLEMENTADO via cardápio)

Produtos sazonais (ex.: Canjica Junina, no período junino) são marcados com
`sazonal = 1` no catálogo e habilitados por unidade no cardápio local
(`unidade_produtos.disponivel`). Isso permite **campanhas regionais e por época**
sem alterar o catálogo global da franqueadora.

## 4. Cupons de campanha (DOCUMENTADO — evolução futura)

Modelo proposto para campanhas segmentadas (ex.: "Café da Manhã 20% OFF",
aniversariante do mês, cliente frequente):

```
campanhas
  id, nome, tipo (PERCENTUAL | VALOR_FIXO | BRINDE),
  valor, cupom (VARCHAR único, opcional),
  publico_alvo (TODOS | ANIVERSARIANTE | FREQUENTE | SEGMENTO),
  inicio, fim, ativa,
  unidade_id (NULL = rede toda)
pedido_campanha
  pedido_id, campanha_id, desconto_aplicado
```

**Como aplicar (regra):**
1. Na criação do pedido, opcionalmente informar `cupom`.
2. A aplicação valida: campanha ativa, dentro do período, unidade elegível e
   público-alvo (ex.: idade do cliente para "aniversariante", contagem de pedidos
   no mês para "frequente").
3. O desconto da campanha **não acumula** com o desconto de fidelidade — aplica-se
   o **maior** dos dois (regra de não-cumulatividade), evitando margem negativa.
4. Registrar o vínculo em `pedido_campanha` para auditoria e relatório de ROI.

**Justificativa de priorização:** o MVP entrega os mecanismos de desconto que
exercitam o fluxo crítico (fidelidade + sazonalidade). Cupons de campanha foram
modelados e documentados, mas deixados como evolução para manter o foco no fluxo
Pedido → Pagamento → Status exigido como obrigatório.
