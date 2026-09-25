SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS precificacao
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE precificacao;

-- ---------------------------------------------------------------------
-- USUÁRIOS
-- ---------------------------------------------------------------------
CREATE TABLE usuarios (
    id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    nome          VARCHAR(100)  NOT NULL,
    email         VARCHAR(150)  NOT NULL,
    senha_hash    VARCHAR(255)  NOT NULL,
    perfil        ENUM('ADMIN','OPERADOR') NOT NULL DEFAULT 'OPERADOR',
    ativo         TINYINT(1)    NOT NULL DEFAULT 1,
    criado_em     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_usuarios_email (email)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- SERVIÇOS
-- ---------------------------------------------------------------------
CREATE TABLE servicos (
    id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    nome          VARCHAR(100)  NOT NULL,
    descricao     VARCHAR(255)  NULL,
    valor_base    DECIMAL(12,2) NOT NULL,
    ativo         TINYINT(1)    NOT NULL DEFAULT 1,
    criado_em     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_servicos_nome (nome),
    CONSTRAINT ck_servicos_valor_base CHECK (valor_base >= 0)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- CATEGORIAS DE CLIENTE
-- ---------------------------------------------------------------------
CREATE TABLE categorias_cliente (
    id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    nome          VARCHAR(100)  NOT NULL,
    descricao     VARCHAR(255)  NULL,
    ativo         TINYINT(1)    NOT NULL DEFAULT 1,
    criado_em     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_categorias_nome (nome)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- REGIÕES
-- ---------------------------------------------------------------------
CREATE TABLE regioes (
    id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    nome          VARCHAR(100)  NOT NULL,
    fator_preco   DECIMAL(6,4)  NOT NULL DEFAULT 1.0000,
    ativo         TINYINT(1)    NOT NULL DEFAULT 1,
    criado_em     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_regioes_nome (nome),
    CONSTRAINT ck_regioes_fator CHECK (fator_preco > 0)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- FAIXAS DE UTILIZAÇÃO
-- ---------------------------------------------------------------------
CREATE TABLE faixas_utilizacao (
    id                    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    descricao             VARCHAR(100)  NOT NULL,
    quantidade_inicial    INT UNSIGNED  NOT NULL,
    quantidade_final      INT UNSIGNED  NULL,
    percentual_acrescimo  DECIMAL(6,2)  NOT NULL DEFAULT 0.00,
    ativo                 TINYINT(1)    NOT NULL DEFAULT 1,
    criado_em             DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT ck_faixas_intervalo
        CHECK (quantidade_final IS NULL OR quantidade_final >= quantidade_inicial),
    CONSTRAINT ck_faixas_percentual CHECK (percentual_acrescimo >= 0)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- REGRAS
-- ---------------------------------------------------------------------
CREATE TABLE regras (
    id               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    codigo           VARCHAR(20)   NOT NULL,
    nome             VARCHAR(150)  NOT NULL,
    descricao        VARCHAR(255)  NULL,
    prioridade       INT           NOT NULL DEFAULT 100,
    tipo_acao        ENUM('DESCONTO','ACRESCIMO')      NOT NULL,
    tipo_valor       ENUM('PERCENTUAL','VALOR_FIXO')   NOT NULL DEFAULT 'PERCENTUAL',
    valor            DECIMAL(12,4) NOT NULL,
    interromper      TINYINT(1)    NOT NULL DEFAULT 0,
    ativa            TINYINT(1)    NOT NULL DEFAULT 1,
    vigencia_inicio  DATE          NULL,
    vigencia_fim     DATE          NULL,
    criado_por       INT UNSIGNED  NULL,
    atualizado_por   INT UNSIGNED  NULL,
    criado_em        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_regras_codigo (codigo),
    KEY idx_regras_execucao (ativa, prioridade),         -- busca do motor de cálculo
    CONSTRAINT fk_regras_criado_por     FOREIGN KEY (criado_por)     REFERENCES usuarios (id),
    CONSTRAINT fk_regras_atualizado_por FOREIGN KEY (atualizado_por) REFERENCES usuarios (id),
    CONSTRAINT ck_regras_valor    CHECK (valor >= 0),
    CONSTRAINT ck_regras_vigencia CHECK (vigencia_fim IS NULL OR vigencia_inicio IS NULL
                                         OR vigencia_fim >= vigencia_inicio)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- CONDIÇÕES DAS REGRAS -- MOTOR
-- ---------------------------------------------------------------------
CREATE TABLE regra_condicoes (
    id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    regra_id     INT UNSIGNED  NOT NULL,
    campo        VARCHAR(30)   NOT NULL,
    operador     VARCHAR(20)   NOT NULL,
    valor        VARCHAR(255)  NOT NULL,
    valor_final  VARCHAR(255)  NULL,
    PRIMARY KEY (id),
    KEY idx_condicoes_regra (regra_id),
    CONSTRAINT fk_condicoes_regra FOREIGN KEY (regra_id)
        REFERENCES regras (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- HISTÓRICO DE REGRAS — auditoria
-- ---------------------------------------------------------------------
CREATE TABLE regras_historico (
    id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    regra_id          INT UNSIGNED  NOT NULL,
    usuario_id        INT UNSIGNED  NULL,
    acao              ENUM('CRIACAO','ALTERACAO','ATIVACAO','DESATIVACAO') NOT NULL,
    dados_anteriores  JSON          NULL,
    dados_novos       JSON          NULL,
    criado_em         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_historico_regra (regra_id, criado_em),
    CONSTRAINT fk_historico_regra   FOREIGN KEY (regra_id)   REFERENCES regras (id),
    CONSTRAINT fk_historico_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- CÁLCULOS — histórico de cada cálculo executado.
-- ---------------------------------------------------------------------
CREATE TABLE calculos (
    id                    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    usuario_id            INT UNSIGNED  NULL,
    servico_id            INT UNSIGNED  NOT NULL,
    categoria_id          INT UNSIGNED  NOT NULL,
    regiao_id             INT UNSIGNED  NOT NULL,
    quantidade            INT UNSIGNED  NOT NULL,
    valor_base            DECIMAL(12,2) NOT NULL,
    valor_unitario_final  DECIMAL(12,2) NOT NULL,
    valor_total           DECIMAL(14,2) NOT NULL,
    memoria               JSON          NOT NULL,
    criado_em             DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_calculos_data (criado_em),
    CONSTRAINT fk_calculos_usuario   FOREIGN KEY (usuario_id)   REFERENCES usuarios (id),
    CONSTRAINT fk_calculos_servico   FOREIGN KEY (servico_id)   REFERENCES servicos (id),
    CONSTRAINT fk_calculos_categoria FOREIGN KEY (categoria_id) REFERENCES categorias_cliente (id),
    CONSTRAINT fk_calculos_regiao    FOREIGN KEY (regiao_id)    REFERENCES regioes (id),
    CONSTRAINT ck_calculos_quantidade CHECK (quantidade > 0)
) ENGINE=InnoDB;