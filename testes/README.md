# Diretório de Testes Automatizados

Este diretório contém todos os arquivos relacionados aos testes automatizados do sistema de estoque, incluindo o fluxo completo de sincronização de entrada de Nota Fiscal (NFe) via XML.

## Testar saída direta

O teste `test_saida_direta.py::test_testar_saida_direta` acessa a URL informada,
entra com `0716`, preenche `1` no primeiro produto, clica em **Pegar** e aguarda
`pos_saida.php`, verificando **Saída Registrada** e o identificador da movimentação.
Cada execução registra uma saída real de uma unidade, sem desfazê-la.
Sem `SAIDA_DIRETA_URL`, o teste é ignorado para evitar retiradas involuntárias.

```bash
cd testes
SAIDA_DIRETA_URL=http://76.13.225.98 python3 -m pytest -v -s test_saida_direta.py
```

Usa as dependências de `requirements.txt` e Chrome/Chromium. O Selenium gerencia
o driver automaticamente. Variáveis opcionais: `SAIDA_DIRETA_USUARIO` (padrão
`0716`), `HEADLESS=false` para exibir o navegador, `CHROME_BINARY` e `CHROMEDRIVER`
para indicar os executáveis. Em contêineres que exigem essa opção, use
`CHROME_NO_SANDBOX=1`. Falhas salvam uma captura no diretório temporário do pytest.

## Estrutura de Arquivos

```
testes/
├── test_sync_nfe.py              # Teste principal com Selenium
├── test_config.py                # Configurações do teste
├── test_nfe.xml                 # Arquivo XML de teste para NFe
├── requirements.txt             # Dependências Python
├── README_TESTE_SYNC_NFE.md     # Documentação completa dos testes
├── contexto_teste_sincronizacao.md  # Contexto do projeto
├── run_test.sh                  # Script bash para execução
├── run_test.py                  # Script Python para execução
├── .gitignore_testes            # Gitignore específico para testes
└── README.md                    # Este arquivo
```

## Execução Rápida

### Opção 1: Script Bash
```bash
cd testes
chmod +x run_test.sh
./run_test.sh
```

### Opção 2: Script Python
```bash
cd testes
python run_test.py --all
```

### Opção 3: Pytest Direto
```bash
cd testes
pip install -r requirements.txt
pytest test_sync_nfe.py -v --html=report.html
```

## Documentação Completa

Para instruções detalhadas sobre instalação, configuração e execução, consulte:
- `README_TESTE_SYNC_NFE.md` - Guia completo do teste
- `contexto_teste_sincronizacao.md` - Contexto do projeto e fluxo

## Arquivos Gerados Durante a Execução

Os seguintes arquivos são criados durante a execução dos testes e **não devem** ser commitados:

- `test_sync_nfe.log` - Log da execução
- `test_run_*.log` - Logs de execuções específicas
- `report_*.html` - Relatórios HTML
- `screenshots/` - Screenshots de erro
- `__pycache__/` - Cache Python

## Configuração

Antes de executar os testes, ajuste as configurações em `test_config.py`:

```python
# URLs do sistema
BASE_URL = "http://76.13.225.98"

# Credenciais de teste
TEST_USERNAME = "Adm2"
TEST_PASSWORD = "adm2123"

# Navegador (firefox ou chrome)
BROWSER = "firefox"

# Timeouts (ajuste conforme velocidade da rede)
EXPLICIT_WAIT = 60
```

### Navegadores Suportados

O teste suporta tanto Firefox quanto Chrome. Se o navegador preferido não estiver disponível, ele tentará automaticamente usar o outro como fallback.

- **Firefox**: Recomendado para servidores (mais comum em ambientes Linux)
- **Chrome/Chromium**: Alternativa se Firefox não estiver disponível

## Fluxo Testado

O teste automatizado valida o seguinte fluxo:

1. **Login** → Autentica no sistema web
2. **Upload XML** → Envia arquivo XML de NFe
3. **Processamento** → Processa o XML e confirma entrada
4. **Sincronização** → Executa script de sincronização
5. **Validação** → Verifica sucesso de todas as etapas

## Executando Testes Específicos

```bash
# Apenas login
pytest test_sync_nfe.py::TestSyncNFe::test_login_sistema -v

# Apenas upload
pytest test_sync_nfe.py::TestSyncNFe::test_upload_xml_nfe -v

# Apenas confirmação
pytest test_sync_nfe.py::TestSyncNFe::test_confirmar_entrada_nfe -v

# Apenas sincronização
pytest test_sync_nfe.py::TestSyncNFe::test_executar_sincronizacao -v

# Fluxo completo
pytest test_sync_nfe.py::TestSyncNFe::test_fluxo_completo_sync_nfe -v
```

## Notas Importantes

- **Nunca execute em produção** sem testar em ambiente controlado
- Use sempre **dados de teste** (não use notas fiscais reais)
- **Limpe os dados de teste** após a execução
- **Monitore os logs** para identificar problemas
- **Ajuste os timeouts** se a rede for lenta
- **Verifique permissões** do diretório de screenshots

## Troubleshooting

### Problemas Comuns

1. **Erro "Nenhum navegador disponível"**
   - Instale Firefox ou Chrome: `yum install firefox` ou `apt-get install chromium-browser`
   - Verifique se o geckodriver ou chromedriver está instalado

2. **Timeout ao esperar elementos**
   - Aumente `EXPLICIT_WAIT` em `test_config.py`
   - Verifique se o servidor está acessível
   - Confira se as credenciais estão corretas

3. **Erro "Token inválido" na sincronização**
   - Este erro é esperado se não houver token de sessão válido
   - O teste continua, mas valida que a resposta foi recebida

4. **Teste trava na página registrar_entrada_nfe.php / Timeout infinito**
   - ✅ BUG CONHECIDO do Firefox Headless / Geckodriver existente desde 2019
   - O Firefox trava infinitamente quando recebe redirecionamento 302 com corpo vazio
   - Já existe workaround implementado no código do teste
   - Workaround aplicado: desativa timeout de carregamento da página temporariamente e verifica URL manualmente em loop
   - Se o problema persistir, recomenda-se alterar para BROWSER = "chrome" no test_config.py

5. **Falha no upload de XML**
   - Verifique se o arquivo `test_nfe.xml` existe no diretório `testes/`
   - Confira se o caminho está correto em `test_config.py`

### Logs e Debug

- **Logs detalhados**: `test_sync_nfe.log`
- **Screenshots de erro**: `screenshots/` (gerados automaticamente)
- **Relatório HTML**: Execute com `--html=report.html`

## Suporte

Em caso de dúvidas ou problemas:
1. Verifique os logs em `test_sync_nfe.log`
2. Consulte as screenshots em `screenshots/`
3. Leia a documentação em `README_TESTE_SYNC_NFE.md`
4. Revise o contexto em `contexto_teste_sincronizacao.md`
