-- =============================================================================
--  Rede "Raízes do Nordeste" — Esquema do banco de dados (DER físico)
--  SGBD: MySQL 8 / MariaDB (XAMPP)  |  Engine: InnoDB  |  Charset: utf8mb4
--
--   - Catálogo global (produtos) + override por unidade (unidade_produtos):
--     a franqueadora padroniza o cardápio, mas cada unidade ajusta preço,
--     disponibilidade e estoque local incluindo produtos sazonais .
--   - Pagamento desacoplado: a tabela 'pagamentos' guarda apenas o resultado
--     reportado por um gateway externo (a rede não processa cartão).
--   - LGPD: consentimento é registrado de forma versionada e auditável;
--     'auditoria' registra operações sensíveis (cancelamento, desconto, ajuste).
--   - Snapshots em pedido_itens (nome/preço no momento da venda) garantem
--     que relatórios históricos não mudem se o catálogo mudar depois.
-- =============================================================================

CREATE DATABASE IF NOT EXISTS `raizes_nordeste`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `raizes_nordeste`;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `auditoria`;
DROP TABLE IF EXISTS `pontos_fidelidade`;
DROP TABLE IF EXISTS `pagamentos`;
DROP TABLE IF EXISTS `pedido_itens`;
DROP TABLE IF EXISTS `pedidos`;
DROP TABLE IF EXISTS `unidade_produtos`;
DROP TABLE IF EXISTS `produtos`;
DROP TABLE IF EXISTS `categorias`;
DROP TABLE IF EXISTS `consentimentos_lgpd`;
DROP TABLE IF EXISTS `clientes`;
DROP TABLE IF EXISTS `funcionarios`;
DROP TABLE IF EXISTS `unidades`;
DROP TABLE IF EXISTS `regioes`;
SET FOREIGN_KEY_CHECKS = 1;

-- -----------------------------------------------------------------------------
-- Regiões — usadas para consolidar vendas por região na matriz.
-- -----------------------------------------------------------------------------
CREATE TABLE `regioes` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `nome`       VARCHAR(80)  NOT NULL,
    `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_regioes_nome` (`nome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Unidades (franquias). 'tipo' diferencia cozinha completa de operação reduzida.
-- -----------------------------------------------------------------------------
CREATE TABLE `unidades` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `regiao_id`  INT UNSIGNED NOT NULL,
    `nome`       VARCHAR(120) NOT NULL,
    `tipo`       ENUM('COMPLETA','REDUZIDA') NOT NULL DEFAULT 'COMPLETA',
    `cidade`     VARCHAR(80)  NOT NULL,
    `estado`     CHAR(2)      NOT NULL,
    `endereco`   VARCHAR(200) NULL,
    `telefone`   VARCHAR(20)  NULL,
    `ativa`      TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_unidades_regiao` (`regiao_id`),
    CONSTRAINT `fk_unidades_regiao`
        FOREIGN KEY (`regiao_id`) REFERENCES `regioes` (`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Funcionários — acesso ao sistema com papéis (RBAC).
--   ATENDENTE/COZINHEIRO/GERENTE pertencem a uma unidade;
--   MATRIZ tem visão consolidada (unidade_id NULL).
-- A senha é armazenada apenas como hash (password_hash / bcrypt).
-- -----------------------------------------------------------------------------
CREATE TABLE `funcionarios` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `unidade_id`  INT UNSIGNED NULL,
    `nome`        VARCHAR(120) NOT NULL,
    `email`       VARCHAR(160) NOT NULL,
    `senha_hash`  VARCHAR(255) NOT NULL,
    `papel`       ENUM('ATENDENTE','COZINHEIRO','GERENTE','MATRIZ') NOT NULL,
    `ativo`       TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_funcionarios_email` (`email`),
    KEY `idx_funcionarios_unidade` (`unidade_id`),
    CONSTRAINT `fk_funcionarios_unidade`
        FOREIGN KEY (`unidade_id`) REFERENCES `unidades` (`id`)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Clientes (programa de fidelidade). Coleta mínima de dados pessoais (LGPD).
-- 'pontos_saldo' é um cache do saldo; a fonte da verdade é 'pontos_fidelidade'.
-- -----------------------------------------------------------------------------
CREATE TABLE `clientes` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `nome`            VARCHAR(120) NOT NULL,
    `email`           VARCHAR(160) NOT NULL,
    `telefone`        VARCHAR(20)  NULL,
    `data_nascimento` DATE         NULL,
    `pontos_saldo`    INT          NOT NULL DEFAULT 0,
    `ativo`           TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_clientes_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Consentimentos LGPD — registro versionado e imutável por finalidade.
-- Cada decisão do cliente (conceder/revogar) gera uma nova linha, formando
-- uma trilha auditável de consentimento (art. 8º e 9º da LGPD).
-- -----------------------------------------------------------------------------
CREATE TABLE `consentimentos_lgpd` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `cliente_id`   INT UNSIGNED NOT NULL,
    `finalidade`   ENUM('FIDELIDADE','MARKETING','PERSONALIZACAO') NOT NULL,
    `concedido`    TINYINT(1)   NOT NULL,
    `versao_termo` VARCHAR(20)  NOT NULL DEFAULT '1.0',
    `ip_origem`    VARCHAR(45)  NULL,
    `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_consent_cliente_finalidade` (`cliente_id`, `finalidade`),
    CONSTRAINT `fk_consent_cliente`
        FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Categorias do cardápio (ex.: Tapiocas, Cuscuz, Bebidas, Cafés).
-- -----------------------------------------------------------------------------
CREATE TABLE `categorias` (
    `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `nome`      VARCHAR(80)  NOT NULL,
    `descricao` VARCHAR(200) NULL,
    `ativa`     TINYINT(1)   NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_categorias_nome` (`nome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Produtos — catálogo global da franqueadora (preço-base de referência).
-- 'sazonal' marca itens disponíveis só em épocas específicas (ex.: junino).
-- -----------------------------------------------------------------------------
CREATE TABLE `produtos` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `categoria_id` INT UNSIGNED NOT NULL,
    `nome`         VARCHAR(120) NOT NULL,
    `descricao`    VARCHAR(255) NULL,
    `preco_base`   DECIMAL(10,2) NOT NULL,
    `sazonal`      TINYINT(1)   NOT NULL DEFAULT 0,
    `ativo`        TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_produtos_categoria` (`categoria_id`),
    CONSTRAINT `fk_produtos_categoria`
        FOREIGN KEY (`categoria_id`) REFERENCES `categorias` (`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Cardápio por unidade (override local do catálogo global).
--   preco_local NULL  => usa produtos.preco_base
--   disponivel        => liga/desliga o item naquela unidade (sazonalidade,
--                        cozinha reduzida, falta de insumo)
--   estoque           => controle de estoque local
-- -----------------------------------------------------------------------------
CREATE TABLE `unidade_produtos` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `unidade_id`  INT UNSIGNED NOT NULL,
    `produto_id`  INT UNSIGNED NOT NULL,
    `preco_local` DECIMAL(10,2) NULL,
    `disponivel`  TINYINT(1)   NOT NULL DEFAULT 1,
    `estoque`     INT          NOT NULL DEFAULT 0,
    `updated_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_unidade_produto` (`unidade_id`, `produto_id`),
    KEY `idx_up_produto` (`produto_id`),
    CONSTRAINT `fk_up_unidade`
        FOREIGN KEY (`unidade_id`) REFERENCES `unidades` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_up_produto`
        FOREIGN KEY (`produto_id`) REFERENCES `produtos` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Pedidos — multicanal (app, totem, balcão, pick-up) com máquina de status.
-- cliente_id é opcional (pedido anônimo no balcão/totem é permitido).
-- funcionario_id registra quem operou (rastreabilidade).
-- -----------------------------------------------------------------------------
CREATE TABLE `pedidos` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `codigo`         VARCHAR(20)  NOT NULL,
    `unidade_id`     INT UNSIGNED NOT NULL,
    `cliente_id`     INT UNSIGNED NULL,
    `funcionario_id` INT UNSIGNED NULL,
    `canal`          ENUM('APP','TOTEM','BALCAO','PICKUP','WEB') NOT NULL,
    `status`         ENUM('RECEBIDO','EM_PREPARO','PRONTO','ENTREGUE','CANCELADO')
                     NOT NULL DEFAULT 'RECEBIDO',
    `subtotal`       DECIMAL(10,2) NOT NULL DEFAULT 0,
    `desconto`       DECIMAL(10,2) NOT NULL DEFAULT 0,
    `total`          DECIMAL(10,2) NOT NULL DEFAULT 0,
    `observacao`     VARCHAR(255) NULL,
    `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_pedidos_codigo` (`codigo`),
    KEY `idx_pedidos_unidade_status` (`unidade_id`, `status`),
    KEY `idx_pedidos_cliente` (`cliente_id`),
    KEY `idx_pedidos_created` (`created_at`),
    CONSTRAINT `fk_pedidos_unidade`
        FOREIGN KEY (`unidade_id`) REFERENCES `unidades` (`id`)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_pedidos_cliente`
        FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT `fk_pedidos_funcionario`
        FOREIGN KEY (`funcionario_id`) REFERENCES `funcionarios` (`id`)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Itens do pedido — guardam SNAPSHOT de nome e preço no momento da venda,
-- para preservar a integridade histórica dos relatórios.
-- -----------------------------------------------------------------------------
CREATE TABLE `pedido_itens` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `pedido_id`      BIGINT UNSIGNED NOT NULL,
    `produto_id`     INT UNSIGNED NULL,
    `nome_produto`   VARCHAR(120) NOT NULL,
    `preco_unitario` DECIMAL(10,2) NOT NULL,
    `quantidade`     INT          NOT NULL,
    `subtotal`       DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_itens_pedido` (`pedido_id`),
    KEY `idx_itens_produto` (`produto_id`),
    CONSTRAINT `fk_itens_pedido`
        FOREIGN KEY (`pedido_id`) REFERENCES `pedidos` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_itens_produto`
        FOREIGN KEY (`produto_id`) REFERENCES `produtos` (`id`)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Pagamentos — resultado reportado pelo gateway externo (arquitetura
-- desacoplada). 'gateway_ref' é o identificador da transação no parceiro;
-- 'payload_retorno' guarda a resposta bruta para auditoria/conciliação.
-- -----------------------------------------------------------------------------
CREATE TABLE `pagamentos` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `pedido_id`       BIGINT UNSIGNED NOT NULL,
    `metodo`          ENUM('PIX','CARTAO_CREDITO','CARTAO_DEBITO','DINHEIRO') NOT NULL,
    `valor`           DECIMAL(10,2) NOT NULL,
    `status`          ENUM('PENDENTE','APROVADO','RECUSADO','ESTORNADO')
                      NOT NULL DEFAULT 'PENDENTE',
    `gateway_ref`     VARCHAR(80)  NULL,
    `payload_retorno` TEXT         NULL,
    `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_pagamentos_gateway_ref` (`gateway_ref`),
    KEY `idx_pagamentos_pedido` (`pedido_id`),
    CONSTRAINT `fk_pagamentos_pedido`
        FOREIGN KEY (`pedido_id`) REFERENCES `pedidos` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Pontos de fidelidade — livro-razão (ledger) de crédito/débito de pontos.
-- O saldo do cliente é a soma deste ledger (e é espelhado em clientes.pontos_saldo).
-- -----------------------------------------------------------------------------
CREATE TABLE `pontos_fidelidade` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `cliente_id` INT UNSIGNED NOT NULL,
    `pedido_id`  BIGINT UNSIGNED NULL,
    `tipo`       ENUM('CREDITO','DEBITO') NOT NULL,
    `pontos`     INT          NOT NULL,
    `descricao`  VARCHAR(160) NULL,
    `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_pontos_cliente` (`cliente_id`),
    CONSTRAINT `fk_pontos_cliente`
        FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT `fk_pontos_pedido`
        FOREIGN KEY (`pedido_id`) REFERENCES `pedidos` (`id`)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Auditoria — trilha de operações sensíveis exigidas pela matriz
-- (cancelamentos, descontos, ajustes, acessos a dados pessoais).
-- Registra antes/depois em JSON para rastreabilidade e conformidade (LGPD).
-- -----------------------------------------------------------------------------
CREATE TABLE `auditoria` (
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `funcionario_id`  INT UNSIGNED NULL,
    `acao`            VARCHAR(60)  NOT NULL,
    `entidade`        VARCHAR(60)  NOT NULL,
    `entidade_id`     VARCHAR(40)  NULL,
    `descricao`       VARCHAR(255) NULL,
    `dados_anteriores` JSON        NULL,
    `dados_novos`     JSON         NULL,
    `ip_origem`       VARCHAR(45)  NULL,
    `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_auditoria_entidade` (`entidade`, `entidade_id`),
    KEY `idx_auditoria_funcionario` (`funcionario_id`),
    KEY `idx_auditoria_created` (`created_at`),
    CONSTRAINT `fk_auditoria_funcionario`
        FOREIGN KEY (`funcionario_id`) REFERENCES `funcionarios` (`id`)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
