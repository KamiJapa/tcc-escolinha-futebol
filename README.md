# GestorFC

Sistema de gestão administrativa, financeira e esportiva de escolinhas de futebol. Organiza alunos, responsáveis, turmas, matrículas, treinos, chamadas, avaliações, jogos, metas e lançamentos financeiros. O projeto usa PHP, HTML, CSS, JavaScript e MySQL.

## Como iniciar no XAMPP

1. Inicie o Apache e o MySQL pelo painel do XAMPP.
2. Abra o phpMyAdmin e importe `database.sql` para criar o banco e os dados de demonstração. **Atenção:** esse arquivo apaga e recria as tabelas deste sistema. Não o importe sobre dados que queira guardar.
3. Confira `config/database.php`. O padrão é `127.0.0.1:3306`, banco `bdescolinha`, usuário `root` e senha vazia para XAMPP local. Ajuste as constantes ou as variáveis `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`. Não use credenciais de demonstração em uma instalação pública.
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
- `includes/notificacoes_helpers.php`: cria avisos para os usuários relacionados e calcula o contador de não lidas, sempre dentro da escolinha.
- `includes/layout.php`: desenha o cabeçalho, menu e estrutura das páginas internas.
- `notificacoes.php`: mostra, filtra e permite atualizar o estado de leitura dos avisos da conta conectada.
- `assets/css/style.css`: cores, cartões, campos e estilos que completam a interface.
- `database.sql`: cria as tabelas e inclui dados para demonstração.

### Atualizar um banco já existente

Para manter os dados atuais, não reimporte `database.sql`. Faça backup e execute `php scripts/migrate.php` no terminal. O atualizador identifica as migrações antigas ainda ausentes e adiciona o financeiro. Para um banco que já possua todas as tabelas da versão anterior deste repositório, basta importar `migration_financeiro.sql`. Nunca execute novamente as migrações antigas já aplicadas. Em caso de migração parcialmente aplicada, confira o schema antes de repetir; alterações DDL não são revertidas como uma transação comum.

## Como o cadastro funciona

`cadastro.php` verifica os campos obrigatórios e se as senhas coincidem. A senha é convertida em hash com `password_hash`; o sistema não guarda a senha original. Em seguida, uma transação grava a conta em `TB_USUARIO` e o perfil de responsável em `TB_RESPONSAVEL`. Se qualquer gravação falhar, a transação é desfeita para não deixar metade do cadastro no banco.

## Painel, cadastros rápidos e camisas

- No painel, os cartões mostram contagens calculadas no banco. Cada tipo de usuário vê apenas os dados permitidos para seu perfil.
- Os botões **Ações rápidas** abrem uma janela com o formulário que já existe em cada tela. O `iframe` do JavaScript mostra essa página sem o menu lateral, e o PHP continua usando os mesmos formulários e validações.
- `includes/layout.php` verifica `?mini=1` para desenhar a página compacta. `includes/helpers.php`, na função `go`, mantém esse parâmetro depois de salvar ou mostrar um erro.
- Em `login.php`, os botões de teste chamam `usarConta(email)`. Essa função preenche os dois campos com a conta escolhida e a senha de demonstração.
- Em `alunos.php`, antes de inserir ou alterar, a consulta `SELECT` verifica se o responsável já tem um aluno com o mesmo nome e a mesma data de nascimento. `TRIM` remove espaços nas pontas e `LOWER` ignora diferenças entre maiúsculas e minúsculas.
- Para alterar camisas, use **Camisas dos alunos**. `camisas.php` lista o elenco, impede atribuir um número já ocupado e troca os números em uma transação. Assim, se uma das duas alterações falhar, o banco desfaz a troca inteira.
- A chamada pode ser aberta a partir do botão do painel; depois de escolher uma aula, marque as presenças e salve. A gravação atualiza a presença existente ou insere uma nova para aquela aula e aluno.

## Para estudar o código

- As classes como `flex`, `grid`, `p-4` e `bg-emerald-950` são classes do Tailwind e controlam somente a aparência do HTML. Por exemplo, `grid` organiza os elementos em colunas e `p-4` adiciona espaço interno.
- O Tailwind está compilado em `assets/css/tailwind.css`; a interface não depende de CDN ou de internet. O CSS próprio continua em `assets/css/style.css`.
- O arquivo `assets/css/style.css` contém CSS comum para cores, estados de foco, cartões e adaptações para celular.
- Em `login.php`, o `if ($_SERVER['REQUEST_METHOD'] === 'POST')` roda quando o formulário é enviado. `loginUser` procura a conta e compara a senha digitada com o hash guardado no banco.
- Em `includes/auth.php`, `loginUser` abre a conexão, consulta o usuário e usa `password_verify`. Se a senha estiver certa, salva o identificador e o perfil na sessão.
- Em `includes/layout.php`, `pageStart` desenha a estrutura compartilhada e `menuItem` só mostra links permitidos para o perfil conectado.
- Em `dashboard.php`, cada `prepare` prepara uma consulta SQL; `execute` envia o código da escolinha como parâmetro; `fetchColumn` lê o resultado simples, como uma contagem.
- Em `cadastro.php`, `beginTransaction`, `commit` e `rollBack` garantem que a conta e o responsável sejam gravados juntos ou não sejam gravados.
- Em `login.php`, a função JavaScript `mostrarSenha` só alterna o campo entre os tipos `password` e `text`.

### Área do responsável

`area_responsavel.php` centraliza o acompanhamento dos filhos: perfil, camisa,
overall (última avaliação), atributos (média histórica), evolução, frequência,
agenda, jogos, gols, assistências, metas, observações e notificações da conta.
O próximo treino ganha prioridade no celular. Valores ausentes aparecem como
sem registro, sem gerar estatísticas fictícias.

O servidor deriva os filhos da conta autenticada e da escolinha. IDs não
vinculados retornam 404; a área não aceita alterações. As rotas antigas de
acompanhamento redirecionam responsáveis para essa visão, mantendo os demais
perfis nas telas existentes. Relacionamentos de turmas, avaliações e jogos são
filtrados pela escola, inclusive quando existem vínculos inconsistentes.
Não há migração adicional: utiliza as tabelas e migrações já existentes.

Teste de regressão (em um banco de teste com o schema aplicado):

```powershell
$env:TEST_DB_DSN = 'mysql:host=127.0.0.1;dbname=bdescolinha;charset=utf8mb4'
php tests/responsavel_scope.php
```

As fixtures do teste são revertidas por transação. O teste cobre dois filhos,
outro responsável, outra escola, conta sem vínculo, relações inconsistentes,
estatísticas reais e estados sem dados.

## Requisitos e apresentação local

- PHP 8.1 ou superior com PDO MySQL e mbstring; validação desta revisão em PHP 8.4 e MariaDB 11.8.
- Apache/XAMPP com MariaDB/MySQL. O nome do banco é `bdescolinha` em minúsculas, inclusive no Linux.
- Copie a pasta para `htdocs/tcc`, inicie Apache e MySQL, importe `database.sql` apenas em uma instalação nova e acesse `http://localhost/tcc/`.
- Alternativa: `php -S 127.0.0.1:8080` na pasta do projeto, com o banco já iniciado. O servidor embutido é apenas para desenvolvimento.
- Nenhum npm, React ou framework PHP é necessário para executar. O CSS já está pronto.
- O `.htaccess` bloqueia downloads dos SQL e arquivos internos no Apache quando `AllowOverride` está habilitado. Não exponha o servidor embutido à internet.

## Financeiro

Administrador e secretaria acessam **Financeiro** no menu. Registre receitas ou despesas, selecione o aluno quando aplicável, informe o valor e o vencimento. Mensalidades exigem aluno e competência, com uma cobrança por aluno/mês. Nenhum valor é presumido: a escolinha informa seu preço.

Os lançamentos pendentes podem ser editados, pagos ou cancelados. A baixa exige uma data até hoje; um pagamento pode ser desfeito. O filtro **Atrasado** deriva do vencimento e do estado pendente. Os totais consideram o mês de vencimento, não o mês de caixa. Cancelados não entram nos totais. O sistema registra baixas manuais, sem integração com banco, PIX, boleto ou pagamento parcial. Alunos com histórico financeiro devem ser inativados em vez de excluídos.

## Vínculo do responsável

Crie uma conta com perfil Responsável em **Usuários** e selecione essa conta em **Responsáveis → Editar → Conta de acesso**. A lista só oferece contas da mesma escolinha e ainda não vinculadas. O cadastro público cria conta e responsável juntos, sem associar automaticamente filhos existentes. Assim, conhecer um CPF ou e-mail não concede acesso a uma família.

## Testes

1. Sintaxe: execute `php -l arquivo.php` nos arquivos PHP.
2. Isolamento de famílias: configure `TEST_DB_DSN` e execute `php tests/responsavel_scope.php`, como no exemplo acima.
3. Integração HTTP: **somente com banco de demonstração descartável**, importe `database.sql`, inicie o servidor, defina `TEST_BASE_URL=http://127.0.0.1:8080` e execute `python tests/http_smoke.py`. Esse teste usa a biblioteca padrão do Python, modifica registros de demonstração e desativa o professor ao final. Reimporte o banco de teste antes de repetir. Nunca execute sobre dados reais.

O teste HTTP verifica login dos cinco perfis, páginas principais, CSRF, matrículas, chamada, avaliações, financeiro, camisas, jogos, metas, vínculo de responsáveis e revogação de sessão. Não substitui revisão visual em navegador.

Para recompilar o CSS após adicionar classes Tailwind:

```sh
npm exec --yes --package=tailwindcss@3.4.17 -- tailwindcss -i assets/css/tailwind.input.css -o assets/css/tailwind.css --content './*.php,./includes/*.php' --minify
```

## Roteiro de demonstração

1. Administrador: responsável, aluno, turma e matrícula.
2. Professor: plano de treino, chamada, avaliação e súmula de jogo.
3. Aluno: perfil, evolução e meta pessoal (crie o acesso em Alunos).
4. Responsável: acompanhe somente os filhos vinculados.
5. Secretaria: registre uma mensalidade e sua baixa, consulte os totais.

Revisão visual em celular/desktop e teste no XAMPP da apresentação permanecem necessários. A execução em ambiente Linux não certifica a configuração do computador da escola.
