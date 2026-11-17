# GestorFC

Sistema escolar para organizar alunos, responsáveis, turmas, matrículas e presença. O projeto usa PHP, HTML, CSS, JavaScript e MySQL.

## Como iniciar no XAMPP

1. Inicie o Apache e o MySQL pelo painel do XAMPP.
2. Abra o phpMyAdmin e importe `database.sql` para criar o banco e os dados de demonstração. **Atenção:** esse arquivo apaga e recria as tabelas deste sistema. Não o importe sobre dados que queira guardar.
3. Use MariaDB em 127.0.0.1:3308 com o banco bdescolinha. Confira a porta no painel do XAMPP.
4. Abra `http://localhost/tcc/login.php`.

### Configuração do ambiente

O PHP lê `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` e `DB_PASS` do ambiente.
Os padrões para o XAMPP local são 127.0.0.1, 3308, bdescolinha, root e senha vazia.
Consulte `.env.example` para os nomes das variáveis. Defina-as no terminal antes de
iniciar o servidor PHP ou use `SetEnv` na configuração local do Apache e reinicie o
Apache. O projeto lê o ambiente do processo.

## Contas de demonstração

Em uma instalação local com os dados de exemplo, defina `APP_DEMO_ACCOUNTS=true` no
ambiente do servidor para exibir os botões que preenchem as credenciais na tela de
login. A tela oculta os botões e as credenciais por padrão. Em instalações
públicas, mantenha o modo desligado e use um banco sem contas de demonstração.

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

Para manter os dados atuais, não reimporte `database.sql`. Execute uma vez `migration_notificacoes.sql` no banco usado pelo sistema. Ela cria a tabela de notificações por escolinha e conta. Se o banco ainda não tiver `TB_FINANCEIRO`, execute também `migration_financeiro.sql` para instalar a estrutura usada pelo módulo financeiro.

Execute `migration_login_tentativas.sql` e `migration_camisa_ativa.sql` no banco
selecionado. A migração de camisas lista duplicatas ativas por escolinha e retorna
erro quando precisa de revisão manual. Resolva os números duplicados e reaplique.
Os registros atuais são preservados.

## Segurança e regras de cadastro

- Três falhas de login, cada uma a menos de 15 minutos da anterior, bloqueiam
  novas tentativas por 15 minutos. O contador usa e-mail normalizado e o IP da
  conexão. Um login bem-sucedido reinicia a contagem. No MariaDB, a aplicação
  limpa até 100 registros cuja última falha ocorreu há mais de 24 horas. Isso
  ocorre em cerca de uma a cada 50 tentativas com e-mail válido, sem esperar por
  bloqueios de linhas.
- A sessão autenticada expira após 30 minutos de inatividade. Se houver outras
  aplicações PHP no mesmo servidor, configure um diretório de sessões próprio
  para o GestorFC para evitar coleta antecipada por outra aplicação.
- O botão **Sair** envia um formulário POST protegido por token CSRF.
- Número de camisa opcional de 0 a 99, exclusivo entre alunos ativos da mesma
  escolinha. Inativar libera o número. Reativar exige número disponível.
- A área do responsável mostra os quatro avisos mais recentes. A página de
  notificações mantém a lista completa.
- O CSP permite recursos locais e vincula scripts inline ao nonce da resposta.
  Gráficos, confirmações e trocas de filtro usam JavaScript local.

### Testes da autenticação

Use um servidor de teste em `http://127.0.0.1:PORTA` com banco e diretório de
sessões descartáveis. Importe `database.sql` somente no banco de teste e defina
`TEST_BASE_URL` para o servidor com `APP_DEMO_ACCOUNTS` ausente.
`TEST_SESSION_DIR` deve apontar para o diretório de sessões do servidor
(handler padrão de arquivos do PHP). Os testes usam o administrador da carga
de demonstração. Para o teste do modo demo, forneça duas URLs locais em
`TEST_DEMO_FALSE_URL` e `TEST_DEMO_TRUE_URL`, com `APP_DEMO_ACCOUNTS=false` e
`APP_DEMO_ACCOUNTS=true`, respectivamente.

```text
php tests/test_session_timeout.php
php tests/test_logout_csrf.php
php tests/test_demo_accounts_toggle.php
php tests/test_rate_limit_purge.php
```

Para o teste da limpeza, configure `TEST_DB_DSN` no formato
`mysql:host=127.0.0.1;port=33367;dbname=gestorfc_codex_test_exemplo;charset=utf8mb4`,
com o esquema carregado nesse banco descartável. Use `TEST_DB_USER` e
`TEST_DB_PASS` conforme a instância de teste.

## Como o cadastro funciona

`cadastro.php` verifica os campos obrigatórios e se as senhas coincidem. A senha é convertida em hash com `password_hash`; o sistema não guarda a senha original. Em seguida, uma transação grava a conta em `TB_USUARIO` e o perfil de responsável em `TB_RESPONSAVEL`. Se qualquer gravação falhar, a transação é desfeita para não deixar metade do cadastro no banco.

## Painel, cadastros rápidos e camisas

- No painel, os cartões mostram contagens calculadas no banco. Cada tipo de usuário vê apenas os dados permitidos para seu perfil.
- Os botões **Ações rápidas** abrem uma janela com o formulário que já existe em cada tela. O `iframe` do JavaScript mostra essa página sem o menu lateral, e o PHP continua usando os mesmos formulários e validações.
- `includes/layout.php` verifica `?mini=1` para desenhar a página compacta. `includes/helpers.php`, na função `go`, mantém esse parâmetro depois de salvar ou mostrar um erro.
- Em `alunos.php`, antes de inserir ou alterar, a consulta `SELECT` verifica se o responsável já tem um aluno com o mesmo nome e a mesma data de nascimento. `TRIM` remove espaços nas pontas e `LOWER` ignora diferenças entre maiúsculas e minúsculas.
- Para alterar camisas, use **Camisas dos alunos**. `camisas.php` lista o elenco, impede atribuir um número já ocupado e troca os números em uma transação. Assim, se uma das duas alterações falhar, o banco desfaz a troca inteira.
- A chamada pode ser aberta a partir do botão do painel; depois de escolher uma aula, marque as presenças e salve. A gravação atualiza a presença existente ou insere uma nova para aquela aula e aluno.

## Para estudar o código

- As classes como `flex`, `grid`, `p-4` e `bg-emerald-950` são classes do Tailwind e controlam somente a aparência do HTML. Por exemplo, `grid` organiza os elementos em colunas e `p-4` adiciona espaço interno.
- O Tailwind compilado roda a partir de assets/css/tailwind.css. O CSS próprio fica em assets/css/style.css.
- O arquivo `assets/css/style.css` contém CSS comum para cores, estados de foco, cartões e adaptações para celular.
- Em `login.php`, o `if ($_SERVER['REQUEST_METHOD'] === 'POST')` roda quando o formulário é enviado. `loginUser` procura a conta e compara a senha digitada com o hash guardado no banco.
- Em `includes/auth.php`, `loginUser` abre a conexão, consulta o usuário e usa `password_verify`. Se a senha estiver certa, salva o identificador e o perfil na sessão.
- Em `includes/layout.php`, `pageStart` desenha a estrutura compartilhada e `menuItem` só mostra links permitidos para o perfil conectado.
- Em `index.php`, cada `prepare` prepara uma consulta SQL; `execute` envia o código da escolinha como parâmetro; `fetchColumn` lê o resultado simples, como uma contagem.
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
$env:TEST_DB_DSN = 'mysql:host=127.0.0.1;port=33367;dbname=gestorfc_codex_test_exemplo;charset=utf8mb4'
php tests/responsavel_scope.php
```

As fixtures do teste são revertidas por transação. O teste cobre dois filhos,
outro responsável, outra escola, conta sem vínculo, relações inconsistentes,
estatísticas reais e estados sem dados.
