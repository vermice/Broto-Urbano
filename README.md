# Broto Urbano

Loja virtual de produtos para jardinagem e cultivo em casa, criada para o desafio do 4º bimestre. A aplicação usa PHP, Bootstrap, MySQL/MariaDB, PDO e sessões. Inclui catálogo, busca e filtro, cadastro e login, carrinho, checkout demonstrativo e área administrativa.

## Requisitos

- XAMPP com Apache, MySQL e PHP 8.1 ou superior
- Extensões PHP `pdo_mysql` e `mbstring` habilitadas
- Navegador e Git

## Instalação local

1. Inicie Apache e MySQL pelo painel do XAMPP.
2. Crie o banco importando `database/schema.sql` pelo phpMyAdmin.
3. Copie `.env.example` para `.env` e ajuste as credenciais do seu MySQL.
4. Coloque a pasta do projeto em `C:\xampp\htdocs\ecommerce-leo` (ou clone o repositório diretamente nessa pasta).
5. Abra `http://localhost/ecommerce-leo/`.
6. Para habilitar a área administrativa, crie um administrador pelo terminal:

   ```bash
   cd C:\xampp\htdocs\ecommerce-leo
   C:\xampp\php\php.exe scripts\criar_admin.php admin@exemplo.com "UmaSenhaForte123" "Administrador"
   ```

   O comando usa `password_hash()` e não grava senhas em texto puro. Acesse `/index.php?page=admin` depois de entrar com a conta criada.

As imagens do catálogo são carregadas do Unsplash e precisam de conexão com a internet. O restante da aplicação é local.

## Funcionalidades

- Produtos e categorias carregados do banco, pesquisa e filtro por categoria.
- Detalhes do produto e estoque disponível.
- Carrinho na sessão, quantidades validadas contra o estoque e remoção de itens.
- Cadastro/login com senha criptografada, proteção CSRF e queries preparadas.
- Checkout simulado com PIX, boleto ou cartão; pedido, itens e baixa de estoque são feitos em transação.
- Área administrativa protegida por perfil, com CRUD de produtos e consulta de pedidos.

O pagamento é apenas uma simulação acadêmica: não há cobrança real nem integração com gateway, então podemos selecionar qualquer forma de pagamento.

## Arquivos principais

- `index.php`: rotas e páginas da loja.
- `includes/`: configuração, acesso ao banco e funções compartilhadas.
- `database/schema.sql`: tabelas relacionais e catálogo inicial.
- `scripts/criar_admin.php`: criação segura de uma conta administrativa.
