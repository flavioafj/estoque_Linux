PRAGMA foreign_keys = ON;
CREATE TABLE usuarios (id INTEGER PRIMARY KEY, nome_completo TEXT, email TEXT, perfil_id INTEGER);
CREATE TABLE categorias (id INTEGER PRIMARY KEY, nome TEXT, ativo INTEGER DEFAULT 1);
CREATE TABLE unidades_medida (id INTEGER PRIMARY KEY, sigla TEXT);
CREATE TABLE fornecedores (id INTEGER PRIMARY KEY AUTOINCREMENT, cnpj TEXT, razao_social TEXT, tipo TEXT, ativo INTEGER, criado_por INTEGER REFERENCES usuarios(id), criado_em TEXT, atualizado_em TEXT);
CREATE TABLE tipos_movimentacao (id INTEGER PRIMARY KEY, tipo TEXT, nome TEXT);
CREATE TABLE produtos (
 id INTEGER PRIMARY KEY AUTOINCREMENT, nome TEXT UNIQUE, codigo TEXT UNIQUE, codigo_barras TEXT,
 categoria_id INTEGER REFERENCES categorias(id), unidade_medida_id INTEGER REFERENCES unidades_medida(id),
 estoque_atual NUMERIC DEFAULT 0, estoque_minimo NUMERIC DEFAULT 0, estoque_maximo NUMERIC DEFAULT 100,
 preco_custo NUMERIC DEFAULT 0, preco_venda NUMERIC DEFAULT 0, margem_lucro NUMERIC DEFAULT 0,
 localizacao TEXT, fornecedor_principal_id INTEGER REFERENCES fornecedores(id), descricao TEXT,
 ativo INTEGER DEFAULT 1, criado_por INTEGER REFERENCES usuarios(id), foto_url TEXT, observacoes TEXT,
 criado_em TEXT DEFAULT CURRENT_TIMESTAMP, atualizado_em TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE inventarios (id INTEGER PRIMARY KEY AUTOINCREMENT, usuario_id INTEGER REFERENCES usuarios(id), data_inicio TEXT, data_conclusao TEXT, status TEXT, atualizado_em TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE movimentacoes (id INTEGER PRIMARY KEY AUTOINCREMENT, tipo_movimentacao_id INTEGER REFERENCES tipos_movimentacao(id), data_movimentacao TEXT,
 usuario_id INTEGER REFERENCES usuarios(id), fornecedor_id INTEGER REFERENCES fornecedores(id), observacoes TEXT, status TEXT,
 inventario_id INTEGER REFERENCES inventarios(id), valor_total NUMERIC DEFAULT 0, documento_numero TEXT, corrigida INTEGER DEFAULT 0, movimentacao_original_id INTEGER);
CREATE TABLE movimentacao_itens (id INTEGER PRIMARY KEY AUTOINCREMENT, movimentacao_id INTEGER REFERENCES movimentacoes(id), produto_id INTEGER REFERENCES produtos(id), quantidade NUMERIC, valor_unitario NUMERIC, valor_total NUMERIC);
CREATE TABLE estoques (id INTEGER PRIMARY KEY AUTOINCREMENT, produto_id INTEGER REFERENCES produtos(id), valor_unitario NUMERIC, data_entrada TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE notas_fiscais (id INTEGER PRIMARY KEY AUTOINCREMENT, movimentacao_id INTEGER REFERENCES movimentacoes(id), numero_nota TEXT UNIQUE, chave_acesso TEXT UNIQUE,
 data_emissao TEXT, fornecedor_id INTEGER REFERENCES fornecedores(id), valor_total NUMERIC, processado INTEGER, usuario_id INTEGER REFERENCES usuarios(id));
CREATE TABLE itens_nota (id INTEGER PRIMARY KEY AUTOINCREMENT, nome_xml TEXT UNIQUE, produto_id INTEGER REFERENCES produtos(id));
CREATE TABLE sincronizacao (id INTEGER PRIMARY KEY AUTOINCREMENT, tabela TEXT, registro_id INTEGER, acao TEXT, dados TEXT,
 sincronizado INTEGER DEFAULT 0, tentativas INTEGER DEFAULT 0, erro_mensagem TEXT, criado_em TEXT DEFAULT CURRENT_TIMESTAMP, sincronizado_em TEXT);
CREATE TABLE sync_recebimentos (origem_hash CHAR(64), evento_id BIGINT, evento_hash CHAR(64), aplicado_em TEXT, confirmado_em TEXT, PRIMARY KEY(origem_hash,evento_id));
CREATE TABLE auditoria (id INTEGER PRIMARY KEY AUTOINCREMENT, tabela TEXT, registro_id INTEGER, acao TEXT, dados_anteriores TEXT, dados_novos TEXT, usuario_id INTEGER, ip_address TEXT, user_agent TEXT);
CREATE TABLE alertas_estoque (id INTEGER PRIMARY KEY, produto_id INTEGER REFERENCES produtos(id), tipo_alerta TEXT, mensagem TEXT, lido INTEGER, usuario_leitura_id INTEGER, criado_em TEXT);
-- Notificações são uma integração separada: a view vazia evita e-mails nos testes.
CREATE VIEW vw_produtos_estoque_critico AS SELECT * FROM produtos WHERE 0;
INSERT INTO usuarios VALUES (1, 'PYTEST_BASE_USUARIO', 'pytest@example.invalid', 1);
INSERT INTO categorias VALUES (1, 'PYTEST_BASE_CATEGORIA', 1);
INSERT INTO unidades_medida VALUES (1, 'UN');
INSERT INTO fornecedores (id, cnpj, razao_social, tipo, ativo, criado_por) VALUES (1, '50902071001120', 'PYTEST_BASE_FORNECEDOR', 'PJ', 1, 1);
INSERT INTO tipos_movimentacao VALUES (1,'ENTRADA','Compra NF-e'), (3,'ENTRADA','Ajuste +'), (5,'SAIDA','Venda'), (7,'SAIDA','Ajuste -');
