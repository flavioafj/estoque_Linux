-- =============================================
-- SISTEMA DE CONTROLE DE ESTOQUE - SORVETERIA
-- Schema do Banco de Dados
-- =============================================

-- Criar banco de dados
CREATE DATABASE IF NOT EXISTS sorveteria_estoque
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE sorveteria_estoque;

-- =============================================
-- TABELA: Perfis de Usuário
-- =============================================
CREATE TABLE IF NOT EXISTS perfis (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nome VARCHAR(50) NOT NULL UNIQUE,
    descricao TEXT,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================
-- TABELA: Usuários
-- =============================================
CREATE TABLE IF NOT EXISTS usuarios (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nome_completo VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    usuario VARCHAR(50) NOT NULL UNIQUE,
    senha VARCHAR(255) NOT NULL,
    perfil_id INT NOT NULL,
    ativo BOOLEAN DEFAULT TRUE,
    ultimo_acesso DATETIME,
    token_recuperacao VARCHAR(100),
    token_expiracao DATETIME,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (perfil_id) REFERENCES perfis(id),
    INDEX idx_usuario (usuario),
    INDEX idx_email (email),
    INDEX idx_perfil (perfil_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================
-- TABELA: Categorias de Produtos
-- =============================================
CREATE TABLE IF NOT EXISTS categorias (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nome VARCHAR(100) NOT NULL,
    descricao TEXT,
    ativo BOOLEAN DEFAULT TRUE,
    criado_por INT,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (criado_por) REFERENCES usuarios(id),
    INDEX idx_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================
-- TABELA: Itens da nota
-- =============================================


CREATE TABLE IF NOT EXISTS itens_nota (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nome_xml VARCHAR(200) NOT NULL UNIQUE,
    produto_id INT NOT NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================
-- TABELA: Unidades de Medida
-- =============================================
CREATE TABLE IF NOT EXISTS unidades_medida (
    id INT PRIMARY KEY AUTO_INCREMENT,
    sigla VARCHAR(10) NOT NULL UNIQUE,
    descricao VARCHAR(50) NOT NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================
-- TABELA: Fornecedores
-- =============================================
CREATE TABLE IF NOT EXISTS fornecedores (
    id INT PRIMARY KEY AUTO_INCREMENT,
    razao_social VARCHAR(200) NOT NULL,
    nome_fantasia VARCHAR(200),
    cnpj VARCHAR(20) UNIQUE,
    cpf VARCHAR(14) UNIQUE,
    tipo ENUM('PJ', 'PF') NOT NULL DEFAULT 'PJ',
    email VARCHAR(100),
    telefone VARCHAR(20),
    celular VARCHAR(20),
    endereco VARCHAR(255),
    cidade VARCHAR(100),
    estado CHAR(2),
    cep VARCHAR(10),
    observacoes TEXT,
    ativo BOOLEAN DEFAULT TRUE,
    criado_por INT,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (criado_por) REFERENCES usuarios(id),
    INDEX idx_razao_social (razao_social),
    INDEX idx_cnpj (cnpj),
    INDEX idx_cpf (cpf)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================
-- TABELA: Produtos
-- =============================================
CREATE TABLE IF NOT EXISTS produtos (
    id INT PRIMARY KEY AUTO_INCREMENT,
    codigo VARCHAR(50) UNIQUE,
    codigo_barras VARCHAR(50),
    nome VARCHAR(200) NOT NULL,
    descricao TEXT,
    categoria_id INT,
    unidade_medida_id INT,
    estoque_minimo DECIMAL(10,3) DEFAULT 0,
    estoque_maximo DECIMAL(10,3),
    estoque_atual DECIMAL(10,3) DEFAULT 0,
    preco_custo DECIMAL(10,2),
    preco_venda DECIMAL(10,2),
    margem_lucro DECIMAL(5,2),
    localizacao VARCHAR(100),
    fornecedor_principal_id INT,
    ativo BOOLEAN DEFAULT TRUE,
    foto_url VARCHAR(500),
    observacoes TEXT,
    criado_por INT,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (categoria_id) REFERENCES categorias(id),
    FOREIGN KEY (unidade_medida_id) REFERENCES unidades_medida(id),
    FOREIGN KEY (fornecedor_principal_id) REFERENCES fornecedores(id),
    FOREIGN KEY (criado_por) REFERENCES usuarios(id),
    INDEX idx_codigo (codigo),
    INDEX idx_codigo_barras (codigo_barras),
    INDEX idx_nome (nome),
    INDEX idx_categoria (categoria_id),
    INDEX idx_fornecedor (fornecedor_principal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- =============================================
-- TABELA: Estoques (unidades individuais)
-- =============================================
CREATE TABLE IF NOT EXISTS estoques (
    id INT PRIMARY KEY AUTO_INCREMENT,
    produto_id INT NOT NULL,
    valor_unitario DECIMAL(10,2) NOT NULL,
    data_entrada TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE CASCADE,
    INDEX idx_produto (produto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================
-- TABELA: Tipos de Movimentação
-- =============================================
CREATE TABLE IF NOT EXISTS tipos_movimentacao (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nome VARCHAR(50) NOT NULL,
    tipo ENUM('ENTRADA', 'SAIDA') NOT NULL,
    descricao TEXT,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================
-- TABELA: Movimentações de Estoque
-- =============================================
CREATE TABLE IF NOT EXISTS movimentacoes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    tipo_movimentacao_id INT NOT NULL,
    documento_numero VARCHAR(50),
    data_movimentacao DATETIME NOT NULL,
    fornecedor_id INT,
    observacoes TEXT,
    valor_total DECIMAL(10,2),
    status ENUM('PENDENTE', 'PROCESSADO', 'CANCELADO') DEFAULT 'PROCESSADO',
    usuario_id INT NOT NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tipo_movimentacao_id) REFERENCES tipos_movimentacao(id),
    FOREIGN KEY (fornecedor_id) REFERENCES fornecedores(id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
    INDEX idx_data (data_movimentacao),
    INDEX idx_tipo (tipo_movimentacao_id),
    INDEX idx_documento (documento_numero),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabela para metadados de inventários
CREATE TABLE IF NOT EXISTS inventarios (
    id INT PRIMARY KEY AUTO_INCREMENT,
    usuario_id INT NOT NULL,
    data_inicio DATETIME NOT NULL,
    data_conclusao DATETIME,
    status ENUM('CONCLUIDO', 'CANCELADO') DEFAULT 'CONCLUIDO',
    observacoes TEXT,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
    INDEX idx_status (status),
    INDEX idx_data_conclusao (data_conclusao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================
-- TABELA: Itens da Movimentação
-- =============================================
CREATE TABLE IF NOT EXISTS movimentacao_itens (
    id INT PRIMARY KEY AUTO_INCREMENT,
    movimentacao_id INT NOT NULL,
    produto_id INT NOT NULL,
    quantidade DECIMAL(10,3) NOT NULL,
    valor_unitario DECIMAL(10,2),
    valor_total DECIMAL(10,2),
    lote VARCHAR(50),
    validade DATE,
    observacoes TEXT,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (movimentacao_id) REFERENCES movimentacoes(id) ON DELETE CASCADE,
    FOREIGN KEY (produto_id) REFERENCES produtos(id),
    INDEX idx_movimentacao (movimentacao_id),
    INDEX idx_produto (produto_id),
    INDEX idx_lote (lote)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================
-- TABELA: Notas Fiscais
-- =============================================
CREATE TABLE IF NOT EXISTS notas_fiscais (
    id INT PRIMARY KEY AUTO_INCREMENT,
    movimentacao_id INT,
    numero_nota VARCHAR(50) NOT NULL,
    serie VARCHAR(10),
    chave_acesso VARCHAR(50),
    data_emissao DATE,
    fornecedor_id INT,
    valor_total DECIMAL(10,2),
    arquivo_xml TEXT,
    arquivo_pdf_url VARCHAR(500),
    processado BOOLEAN DEFAULT FALSE,
    usuario_id INT,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (movimentacao_id) REFERENCES movimentacoes(id),
    FOREIGN KEY (fornecedor_id) REFERENCES fornecedores(id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
    INDEX idx_numero (numero_nota),
    INDEX idx_chave (chave_acesso)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================
-- TABELA: Alertas de Estoque
-- =============================================
CREATE TABLE IF NOT EXISTS alertas_estoque (
    id INT PRIMARY KEY AUTO_INCREMENT,
    produto_id INT NOT NULL,
    tipo_alerta ENUM('MINIMO', 'MAXIMO', 'VENCIMENTO') NOT NULL,
    mensagem TEXT,
    lido BOOLEAN DEFAULT FALSE,
    data_leitura DATETIME,
    usuario_leitura_id INT,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (produto_id) REFERENCES produtos(id),
    FOREIGN KEY (usuario_leitura_id) REFERENCES usuarios(id),
    INDEX idx_produto (produto_id),
    INDEX idx_tipo (tipo_alerta),
    INDEX idx_lido (lido)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================
-- TABELA: Log de Auditoria
-- =============================================
CREATE TABLE IF NOT EXISTS auditoria (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    tabela VARCHAR(50) NOT NULL,
    registro_id INT NOT NULL,
    acao ENUM('INSERT', 'UPDATE', 'DELETE') NOT NULL,
    dados_anteriores JSON,
    dados_novos JSON,
    usuario_id INT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
    INDEX idx_tabela (tabela),
    INDEX idx_registro (registro_id),
    INDEX idx_acao (acao),
    INDEX idx_usuario (usuario_id),
    INDEX idx_data (criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================
-- TABELA: Configurações do Sistema
-- =============================================
CREATE TABLE IF NOT EXISTS configuracoes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    chave VARCHAR(100) NOT NULL UNIQUE,
    valor TEXT,
    tipo VARCHAR(20) DEFAULT 'string',
    descricao TEXT,
    atualizado_por INT,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (atualizado_por) REFERENCES usuarios(id),
    INDEX idx_chave (chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================
-- TABELA: Sessões (para controle de login)
-- =============================================
CREATE TABLE IF NOT EXISTS sessoes (
    id VARCHAR(128) PRIMARY KEY,
    usuario_id INT NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    ultimo_acesso TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    dados TEXT,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    INDEX idx_usuario (usuario_id),
    INDEX idx_ultimo_acesso (ultimo_acesso)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================
-- TABELA: Sincronização (controle local/web)
-- =============================================
CREATE TABLE IF NOT EXISTS sincronizacao (
    id INT PRIMARY KEY AUTO_INCREMENT,
    tabela VARCHAR(50) NOT NULL,
    registro_id INT NOT NULL,
    acao VARCHAR(20) NOT NULL,
    dados JSON,
    sincronizado BOOLEAN DEFAULT FALSE,
    tentativas INT DEFAULT 0,
    erro_mensagem TEXT,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    sincronizado_em DATETIME,
    INDEX idx_tabela (tabela),
    INDEX idx_sincronizado (sincronizado),
    INDEX idx_criado (criado_em)
    INDEX idx_acao (acao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================
-- INSERIR DADOS INICIAIS
-- =============================================

-- Inserir perfis padrão
INSERT INTO perfis (nome, descricao) VALUES
('Administrador', 'Acesso total ao sistema'),
('Operador', 'Acesso limitado para registro de saídas'),
('Inventariador', 'Acesso ao módulo de inventário');

-- Inserir usuário administrador padrão (senha: admin123)
INSERT INTO usuarios (nome_completo, email, usuario, senha, perfil_id) VALUES
('Administrador do Sistema', 'admin@sorveteria.com', 'admin', '$2y$10$YKqFD1ksYSYFLTXZqrDJX.ZhFhXBxDXQRbO.vQ9ZvxF3X7HhzqEMy', 1);

-- Inserir unidades de medida padrão
INSERT INTO unidades_medida (sigla, descricao) VALUES
('UN', 'Unidade'),
('KG', 'Quilograma'),
('G', 'Grama'),
('L', 'Litro'),
('ML', 'Mililitro'),
('CX', 'Caixa'),
('PCT', 'Pacote'),
('FD', 'Fardo'),
('DZ', 'Dúzia');

-- Inserir tipos de movimentação padrão
INSERT INTO tipos_movimentacao (nome, tipo, descricao) VALUES
('Compra', 'ENTRADA', 'Entrada por compra de fornecedor'),
('Devolução de Cliente', 'ENTRADA', 'Entrada por devolução de cliente'),
('Ajuste de Inventário (+)', 'ENTRADA', 'Entrada por ajuste de inventário'),
('Transferência Entrada', 'ENTRADA', 'Entrada por transferência entre estoques'),
('Venda', 'SAIDA', 'Saída por venda ao cliente'),
('Devolução a Fornecedor', 'SAIDA', 'Saída por devolução ao fornecedor'),
('Ajuste de Inventário (-)', 'SAIDA', 'Saída por ajuste de inventário'),
('Transferência Saída', 'SAIDA', 'Saída por transferência entre estoques'),
('Perda/Quebra', 'SAIDA', 'Saída por perda ou quebra de produto'),
('Consumo Interno', 'SAIDA', 'Saída para consumo interno');

-- Inserir configurações padrão
INSERT INTO configuracoes (chave, valor, tipo, descricao) VALUES
('nome_empresa', 'Sorveteria', 'string', 'Nome da empresa'),
('alerta_estoque_minimo', 'true', 'boolean', 'Ativar alertas de estoque mínimo'),
('dias_backup', '7', 'integer', 'Intervalo de dias para backup automático'),
('timeout_sessao', '3600', 'integer', 'Tempo de timeout da sessão em segundos'),
('modo_sincronizacao', 'manual', 'string', 'Modo de sincronização (manual/automatico)'),
('url_sincronizacao', '', 'string', 'URL do servidor web para sincronização');

-- =============================================
-- CRIAR VIEWS ÚTEIS
-- =============================================

-- View de produtos com estoque crítico
CREATE OR REPLACE VIEW vw_produtos_estoque_critico AS
SELECT 
    p.id,
    p.codigo,
    p.nome,
    (SELECT COUNT(*) FROM estoques WHERE produto_id = p.id) AS estoque_atual,
    p.estoque_minimo,
    c.nome as categoria,
    f.nome_fantasia as fornecedor
FROM produtos p
LEFT JOIN categorias c ON p.categoria_id = c.id
LEFT JOIN fornecedores f ON p.fornecedor_principal_id = f.id
WHERE (SELECT COUNT(*) FROM estoques WHERE produto_id = p.id) <= p.estoque_minimo
    AND p.ativo = TRUE;

-- View de movimentações recentes
CREATE OR REPLACE VIEW vw_movimentacoes_recentes AS
SELECT 
    m.id,
    tm.nome as tipo,
    tm.tipo as categoria,
    m.documento_numero,
    m.data_movimentacao,
    m.valor_total,
    f.nome_fantasia as fornecedor,
    u.nome_completo as usuario
FROM movimentacoes m
JOIN tipos_movimentacao tm ON m.tipo_movimentacao_id = tm.id
LEFT JOIN fornecedores f ON m.fornecedor_id = f.id
JOIN usuarios u ON m.usuario_id = u.id
WHERE m.status = 'PROCESSADO'
ORDER BY m.data_movimentacao DESC
LIMIT 100;


ALTER TABLE movimentacoes
ADD COLUMN inventario_id INT,
ADD FOREIGN KEY (inventario_id) REFERENCES inventarios(id),
ADD INDEX idx_inventario (inventario_id);

ALTER TABLE movimentacoes 
ADD COLUMN corrigida BOOLEAN DEFAULT FALSE,
ADD COLUMN movimentacao_original_id INT NULL,
ADD FOREIGN KEY (movimentacao_original_id) REFERENCES movimentacoes(id);