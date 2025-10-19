```
estoque-sorveteria/
│
├── config/
│   ├── database.php         # Configuração do banco de dados
│   ├── config.php           # Configurações gerais do sistema
│   └── constants.php        # Constantes do sistema
│
├── database/
│   ├── schema.sql           # Script de criação do banco
│   ├── migrations/          # Scripts de migração
│   └── seeds/              # Dados iniciais
│
├── src/
│   ├── Controllers/        # Controladores MVC
│   │   ├── AuthController.php
│   │   ├── BaseController.php
│   │   └── DashboardController.php
│   │
│   ├── Models/             # Modelos de dados
│   │   ├── Database.php
│   │   ├── User.php
│   │   └── BaseModel.php
│   │
│   ├── Helpers/            # Funções auxiliares
│   │   ├── Session.php
│   │   ├── Validator.php
│   │   └── Utils.php
│   │
│   └── Middleware/         # Middlewares
│       └── Auth.php
│
├── public/
│   ├── index.php           # Ponto de entrada
│   ├── login.php           # Página de login
│   ├── dashboard.php       # Dashboard principal
│   ├── logout.php          # Script de logout
│   │
│   ├── assets/
│   │   ├── css/
│   │   │   ├── style.css
│   │   │   └── responsive.css
│   │   ├── js/
│   │   │   ├── main.js
│   │   │   └── auth.js
│   │   └── images/
│   │       └── logo.png
│   │
│   └── .htaccess           # Configurações Apache
│
├── templates/
│   ├── header.php          # Cabeçalho padrão
│   ├── footer.php          # Rodapé padrão
│   ├── navigation.php      # Menu de navegação
│   └── alerts.php          # Sistema de alertas
│
├── logs/
│   ├── error.log           # Log de erros
│   └── access.log          # Log de acessos
│
├── temp/                   # Arquivos temporários
├── backups/               # Backups locais
│
├── .env.example           # Exemplo de variáveis de ambiente
├── .gitignore            # Arquivos ignorados pelo Git
├── README.md             # Documentação
└── composer.json         # Dependências PHP (opcional)
```