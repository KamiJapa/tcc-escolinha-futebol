# GestorFC

Sistema escolar para organizar alunos, responsáveis, turmas, matrículas e presença. O projeto usa PHP, HTML, CSS, JavaScript e MySQL.

## Como iniciar no XAMPP

1. Inicie o Apache e o MySQL pelo painel do XAMPP.
2. Abra o phpMyAdmin e importe `database.sql` para criar o banco e os dados de demonstração. **Atenção:** esse arquivo apaga e recria as tabelas deste sistema. Não o importe sobre dados que queira guardar.
3. Confira `config/database.php`. Neste computador, o MySQL usa a porta `3308` e o usuário `root` não tem senha. Se sua instalação usar outra configuração, ajuste as constantes desse arquivo.
4. Abra `http://localhost/tcc/login.php`.

## Contas de demonstração

Todas usam a senha `123456`:

- Administrador: `admin@teste.com`
- Secretaria: `secretaria@teste.com`
- Professor: `professor@teste.com`
- Responsável: `responsavel@teste.com`

O link **Cadastre-se** cria uma conta de responsável. Depois de criar a conta, entre com o e-mail e a senha escolhidos. A conta nova não recebe alunos automaticamente.

## Arquivos principais

- `config/database.php`: abre e reutiliza a conexão com MySQL usando PDO.
- `includes/auth.php`: controla a sessão, verifica permissões e confere a senha com `password_verify`.
- `includes/helpers.php`: reúne funções pequenas para escapar texto, proteger formulários contra CSRF e mostrar mensagens.
- `includes/layout.php`: desenha o cabeçalho, menu e estrutura das páginas internas.
- `assets/css/style.css`: cores, cartões, campos e estilos que completam a interface.
- `database.sql`: cria as tabelas e inclui dados para demonstração.

## Como o cadastro funciona

`cadastro.php` verifica os campos obrigatórios e se as senhas coincidem. A senha é convertida em hash com `password_hash`; o sistema não guarda a senha original. Em seguida, uma transação grava a conta em `TB_USUARIO` e o perfil de responsável em `TB_RESPONSAVEL`. Se qualquer gravação falhar, a transação é desfeita para não deixar metade do cadastro no banco.

## Para estudar o código

- As classes como `flex`, `grid`, `p-4` e `bg-emerald-950` são classes do Tailwind e controlam somente a aparência do HTML. Por exemplo, `grid` organiza os elementos em colunas e `p-4` adiciona espaço interno.
- O Tailwind é carregado pela CDN no navegador; é preciso estar conectado à internet para ver essas classes. O CSS próprio do projeto continua em `assets/css/style.css`.
- O arquivo `assets/css/style.css` contém CSS comum para cores, estados de foco, cartões e adaptações para celular.
- Em `login.php`, o `if ($_SERVER['REQUEST_METHOD'] === 'POST')` roda quando o formulário é enviado. `loginUser` procura a conta e compara a senha digitada com o hash guardado no banco.
- Em `includes/auth.php`, `loginUser` abre a conexão, consulta o usuário e usa `password_verify`. Se a senha estiver certa, salva o identificador e o perfil na sessão.
- Em `includes/layout.php`, `pageStart` desenha a estrutura compartilhada e `menuItem` só mostra links permitidos para o perfil conectado.
- Em `index.php`, cada `prepare` prepara uma consulta SQL; `execute` envia o código da escolinha como parâmetro; `fetchColumn` lê o resultado simples, como uma contagem.
- Em `cadastro.php`, `beginTransaction`, `commit` e `rollBack` garantem que a conta e o responsável sejam gravados juntos ou não sejam gravados.
- Em `login.php`, a função JavaScript `mostrarSenha` só alterna o campo entre os tipos `password` e `text`.
