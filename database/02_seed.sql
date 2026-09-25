-- =====================================================================
-- Sistema de Precificação Parametrizável — Dados iniciais (MySQL 8)
-- ---------------------------------------------------------------------
-- Carrega os dados do enunciado (seção 3), as regras de negócio
-- iniciais (seção 4) e os usuários de acesso.
-- Executado após o 01_schema.sql (ordem alfabética).
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '-03:00';   -- datas da carga inicial no horário de Brasília
USE precificacao;

-- ---------------------------------------------------------------------
-- 1. USUÁRIOS
--    Senhas geradas com password_hash() do PHP (bcrypt):
--      admin@precificacao.local     / admin123     → perfil ADMIN
--      operador@precificacao.local  / operador123  → perfil OPERADOR
--    ⚠ Senhas de demonstração: trocar em produção.
-- ---------------------------------------------------------------------
INSERT INTO usuarios (id, nome, email, senha_hash, perfil) VALUES
(1, 'Administrador', 'admin@precificacao.local',
    '$2y$12$tSrQ2uJUoqWjKCMpe7DMVetVavw7TEFPz.qx16ARs6Q0/rdo/fhA6', 'ADMIN'),
(2, 'Operador',      'operador@precificacao.local',
    '$2y$12$w7xx8qif2OjNH/mXbCGUK.N.YRotdpkeyekZ39Rg/Pl3Dse/s6I92', 'OPERADOR');

-- ---------------------------------------------------------------------
-- 2. SERVIÇOS (ids iguais aos códigos do enunciado)
-- ---------------------------------------------------------------------
INSERT INTO servicos (id, nome, descricao, valor_base) VALUES
(1, 'Suporte Técnico', 'Atendimento e suporte técnico',       100.00),
(2, 'Consultoria',     'Consultoria especializada',           200.00),
(3, 'Desenvolvimento', 'Desenvolvimento de software sob demanda', 300.00);

-- ---------------------------------------------------------------------
-- 3. CATEGORIAS DE CLIENTE
-- ---------------------------------------------------------------------
INSERT INTO categorias_cliente (id, nome, descricao) VALUES
(1, 'Público',     'Órgãos e entidades da administração pública'),
(2, 'Privado',     'Empresas privadas'),
(3, 'Estratégico', 'Clientes com relacionamento estratégico');

-- ---------------------------------------------------------------------
-- 4. REGIÕES (fator aplicado automaticamente sobre o valor base)
-- ---------------------------------------------------------------------
INSERT INTO regioes (id, nome, fator_preco) VALUES
(1, 'Local',    1.0000),
(2, 'Regional', 1.1000),
(3, 'Nacional', 1.2500);

-- ---------------------------------------------------------------------
-- 5. FAIXAS DE UTILIZAÇÃO (quantidade_final NULL = "ou superior")
-- ---------------------------------------------------------------------
INSERT INTO faixas_utilizacao (id, descricao, quantidade_inicial, quantidade_final, percentual_acrescimo) VALUES
(1, '1 a 10',          1,   10,   0.00),
(2, '11 a 50',         11,  50,   5.00),
(3, '51 a 100',        51,  100,  10.00),
(4, '101 ou superior', 101, NULL, 20.00);

-- ---------------------------------------------------------------------
-- 6. REGRAS DE CÁLCULO
--    Prioridade: menor número executa primeiro (espaçamento de 10 em 10
--    permite inserir novas regras entre as existentes sem renumerar).
--
--    R001–R003: regras do enunciado (seção 4).
--    R004–R005: acréscimos das faixas 11–50 e 101+ (tabela da seção 3),
--               cadastrados como regras com condição FAIXA.
--               A faixa 51–100 NÃO tem regra própria porque a R003 já
--               representa exatamente esse acréscimo (evita dupla contagem).
-- ---------------------------------------------------------------------
INSERT INTO regras
    (id, codigo, nome, descricao, prioridade, tipo_acao, tipo_valor, valor, interromper, ativa, criado_por, atualizado_por)
VALUES
(1, 'R001', 'Desconto Estratégico acima de 50',
    'Cliente Estratégico com quantidade superior a 50',
    10, 'DESCONTO',  'PERCENTUAL',  5.0000, 0, 1, 1, 1),
(2, 'R002', 'Acréscimo Desenvolvimento Nacional',
    'Serviço de Desenvolvimento prestado na região Nacional',
    20, 'ACRESCIMO', 'PERCENTUAL', 15.0000, 0, 1, 1, 1),
(3, 'R003', 'Acréscimo quantidade entre 51 e 100',
    'Quantidade contratada entre 51 e 100 unidades',
    30, 'ACRESCIMO', 'PERCENTUAL', 10.0000, 0, 1, 1, 1),
(4, 'R004', 'Acréscimo faixa 11 a 50',
    'Faixa de utilização de 11 a 50 unidades',
    40, 'ACRESCIMO', 'PERCENTUAL',  5.0000, 0, 1, 1, 1),
(5, 'R005', 'Acréscimo faixa 101 ou superior',
    'Faixa de utilização a partir de 101 unidades',
    50, 'ACRESCIMO', 'PERCENTUAL', 20.0000, 0, 1, 1, 1);

-- ---------------------------------------------------------------------
-- 7. CONDIÇÕES DAS REGRAS (todas combinadas com E)
--    Para SERVICO/CATEGORIA/REGIAO/FAIXA, "valor" guarda o id do cadastro.
-- ---------------------------------------------------------------------
INSERT INTO regra_condicoes (regra_id, campo, operador, valor, valor_final) VALUES
-- R001: Categoria = Estratégico (3) E Quantidade > 50
(1, 'CATEGORIA',  'IGUAL', '3',  NULL),
(1, 'QUANTIDADE', 'MAIOR', '50', NULL),
-- R002: Região = Nacional (3) E Serviço = Desenvolvimento (3)
(2, 'REGIAO',     'IGUAL', '3',  NULL),
(2, 'SERVICO',    'IGUAL', '3',  NULL),
-- R003: Quantidade entre 51 e 100 (inclusive)
(3, 'QUANTIDADE', 'ENTRE', '51', '100'),
-- R004: Faixa = "11 a 50" (2)
(4, 'FAIXA',      'IGUAL', '2',  NULL),
-- R005: Faixa = "101 ou superior" (4)
(5, 'FAIXA',      'IGUAL', '4',  NULL);

-- ---------------------------------------------------------------------
-- 8. HISTÓRICO — registra a criação das regras iniciais (auditoria)
-- ---------------------------------------------------------------------
INSERT INTO regras_historico (regra_id, usuario_id, acao, dados_anteriores, dados_novos)
SELECT r.id, 1, 'CRIACAO', NULL,
       JSON_OBJECT('codigo', r.codigo, 'nome', r.nome, 'prioridade', r.prioridade,
                   'tipo_acao', r.tipo_acao, 'tipo_valor', r.tipo_valor,
                   'valor', r.valor, 'ativa', r.ativa, 'origem', 'carga inicial')
FROM regras r;