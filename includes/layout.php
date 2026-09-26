<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

function menuItem($url, $label, $icon, $roles, $activeUrls = [], $badge = null, $section = null) {
    if (!hasRole($roles)) {
        return;
    }

    $icons = [
        'dashboard' => '<rect x="3" y="3" width="8" height="8" rx="1.5"/><rect x="13" y="3" width="8" height="5" rx="1.5"/><rect x="13" y="10" width="8" height="11" rx="1.5"/><rect x="3" y="13" width="8" height="8" rx="1.5"/>',
        'profile' => '<circle cx="12" cy="8" r="3.5"/><path d="M5 20a7 7 0 0 1 14 0"/>',
        'training' => '<path d="M4 5h16v14H4z"/><path d="m10 9 5 3-5 3z"/>',
        'game' => '<path d="M7 7h10l3 10H4L7 7Z"/><path d="M9 11v4m-2-2h4m3-1h.01M16 14h.01"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>',
        'evaluation' => '<path d="M8 4h11v17H5V7z"/><path d="M8 4V3h7v1M8 11h7M8 15h7M8 18h4"/>',
        'growth' => '<path d="M4 19V5m0 14h17"/><path d="m7 15 4-4 3 2 6-7"/><path d="M16 6h4v4"/>',
        'presence' => '<path d="M20 7 10 17l-5-5"/><path d="M4 4h10"/>',
        'goal' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/>',
        'shirt' => '<path d="m8 4-5 3 2 5 3-1v9h8v-9l3 1 2-5-5-3a5 5 0 0 1-8 0Z"/>',
        'bell' => '<path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>',
        'students' => '<path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M20 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M20 8v6m3-3h-6"/>',
        'teams' => '<path d="m12 3 9 5-9 5-9-5 9-5Z"/><path d="m3 12 9 5 9-5M3 16l9 5 9-5"/>',
        'enrollments' => '<path d="M8 5h12M8 12h12M8 19h12M3 5h.01M3 12h.01M3 19h.01"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="m19.4 15 .1.1 1.4 1.1-1.4 2.4-1.7-.6a8 8 0 0 1-1.5.9l-.3 1.8h-2.8l-.3-1.8a8 8 0 0 1-1.5-.9l-1.7.6-1.4-2.4 1.4-1.1a7 7 0 0 1 0-1.8l-1.4-1.1 1.4-2.4 1.7.6a8 8 0 0 1 1.5-.9l.3-1.8h2.8l.3 1.8a8 8 0 0 1 1.5.9l1.7-.6 1.4 2.4-1.4 1.1a7 7 0 0 1 0 1.8Z"/>',
        'logout' => '<path d="M10 17l5-5-5-5M15 12H3"/><path d="M12 3h7a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-7"/>'
    ];
    $arquivo = basename($_SERVER['PHP_SELF']);
    $caminhoUrl = basename((string) parse_url($url, PHP_URL_PATH));
    $secaoAtual = $_GET['secao'] ?? null;
    $ativo = ($arquivo === $caminhoUrl && $secaoAtual === $section) || in_array($arquivo, $activeUrls, true);
    $classes = 'link-menu' . ($ativo ? ' ativo' : '');
    $ariaAtual = $ativo ? ' aria-current="page"' : '';
    $svg = '<svg class="icone-menu" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($icons[$icon] ?? '') . '</svg>';
    $badgeHtml = $badge === null ? '' : '<span class="badge-notificacoes" aria-label="' . (int) $badge . ' notificações">' . (int) $badge . '</span>';
    echo '<a class="' . $classes . '" href="' . e($url) . '"' . $ariaAtual . '>' . $svg . '<span class="texto-menu">' . e($label) . '</span>' . $badgeHtml . '</a>';
}

function menuIndisponivel($label, $icon, $badge = null) {
    $icons = [
        'profile' => '<circle cx="12" cy="8" r="3.5"/><path d="M5 20a7 7 0 0 1 14 0"/>',
        'game' => '<path d="M7 7h10l3 10H4L7 7Z"/><path d="M9 11v4m-2-2h4m3-1h.01M16 14h.01"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>',
        'evaluation' => '<path d="M8 4h11v17H5V7z"/><path d="M8 4V3h7v1M8 11h7M8 15h7M8 18h4"/>',
        'growth' => '<path d="M4 19V5m0 14h17"/><path d="m7 15 4-4 3 2 6-7"/><path d="M16 6h4v4"/>',
        'goal' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/>',
        'bell' => '<path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>'
    ];
    $svg = '<svg class="icone-menu" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($icons[$icon] ?? '') . '</svg>';
    $badgeHtml = $badge === null ? '' : '<span class="badge-notificacoes" aria-label="' . (int) $badge . ' notificações">' . (int) $badge . '</span>';
    echo '<span class="link-menu menu-indisponivel" aria-disabled="true" title="Recurso ainda não disponível">' . $svg . '<span class="texto-menu">' . e($label) . '</span>' . $badgeHtml . '</span>';
}

function pageStart($title) {
    checkLogin();

    // A mini tela usa a mesma página e o mesmo salvamento, sem repetir o menu.
    if (isset($_GET['mini'])) {
        $modoChamada = isset($_GET['chamadas']) ? ' chamadas' : '';
        $modoCamisas = $title === 'Números das camisas' ? ' camisas' : '';
        ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title) ?> - GestorFC</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="mini-pagina<?= $modoChamada . $modoCamisas ?> bg-slate-950 p-4 text-slate-100">
    <main class="conteudo-sistema">
        <h1 class="mb-5 text-xl font-bold text-slate-100"><?= e($title) ?></h1>
        <?php showFlash(); ?>
        <?php
        return;
    }

    $paginas = [
        'Alunos' => 'pagina-alunos',
        'Responsáveis' => 'pagina-recursos pagina-responsaveis',
        'Turmas' => 'pagina-recursos pagina-turmas',
        'Matrículas' => 'pagina-recursos pagina-matriculas',
        'Aulas e chamada' => 'pagina-recursos pagina-aulas',
        'Usuários' => 'pagina-recursos pagina-usuarios'
    ];
    $classePagina = isset($paginas[$title]) ? ' ' . $paginas[$title] : '';
    $acoesRapidas = [
        'Responsáveis' => [['Novo responsável', '♧', 'responsaveis.php?mini=1']],
        'Turmas' => [['Nova turma', '⚽', 'turmas.php?mini=1']],
        'Matrículas' => [['Nova matrícula', '↔', 'matriculas.php?mini=1']],
        'Aulas e chamada' => [
            ['Nova aula', '▣', 'aulas.php?mini=1'],
            ['Fazer chamada', '✓', 'aulas.php?mini=1&chamadas=1']
        ],
        'Usuários' => [['Novo usuário', '☻', 'usuarios.php?mini=1']]
    ];
    $titulosRecursos = [
        'Responsáveis' => 'Responsáveis cadastrados',
        'Turmas' => 'Turmas cadastradas',
        'Matrículas' => 'Matrículas registradas',
        'Aulas e chamada' => 'Aulas registradas',
        'Usuários' => 'Usuários cadastrados'
    ];
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title) ?> - GestorFC</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="pagina-sistema<?= $classePagina ?> min-h-screen bg-slate-950 text-slate-100">
    <header class="cabecalho-sistema bg-slate-950 text-white shadow-lg">
        <div class="cabecalho-inner mx-auto flex w-full max-w-7xl items-center justify-between px-4 py-4">
            <button class="botao-menu-mobile" type="button" id="abrirMenuMobile" aria-controls="menuLateral" aria-expanded="false" aria-label="Abrir navegação">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
            <span class="contexto-cabecalho"><?= e($title) ?></span>
            <p class="usuario-cabecalho"><span class="avatar-usuario" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($_SESSION['username'], 0, 1, 'UTF-8'), 'UTF-8')) ?></span><span><?= e($_SESSION['username']) ?></span><span class="perfil-usuario"><?= e($_SESSION['user_role']) ?></span></p>
        </div>
    </header>

    <div class="estrutura-sistema mx-auto grid max-w-7xl gap-6 px-4 py-6 lg:grid-cols-[248px_minmax(0,1fr)]">
        <button type="button" class="fundo-menu-mobile" id="fundoMenuMobile" aria-label="Fechar menu"></button>
        <aside class="menu-lateral rounded-xl bg-slate-900 p-3 shadow-sm" id="menuLateral" aria-label="Navegação principal">
            <a class="marca-sidebar" href="index.php" aria-label="GestorFC, ir para o dashboard"><span class="logo-bola" aria-hidden="true">⚽</span><span><strong>Gestor<span>FC</span></strong><small>Escolinha de futebol</small></span></a>
            <nav class="navegacao-sidebar" aria-label="Menu principal">
                <p class="titulo-grupo-menu">PRINCIPAL</p>
                <?php menuItem('index.php', 'Dashboard', 'dashboard', ['ADMIN', 'SECRETARIA', 'PROFESSOR', 'RESPONSAVEL'], ['dashboard.php']); ?>
                <?php menuIndisponivel('Meu Perfil', 'profile'); ?>
                <?php menuItem('aulas.php', 'Treinos', 'training', ['ADMIN', 'PROFESSOR']); ?>
                <?php menuIndisponivel('Jogos', 'game'); ?>
                <?php menuIndisponivel('Calendário', 'calendar'); ?>
                <?php menuItem('avaliacoes.php', 'Avaliações', 'evaluation', ['ADMIN', 'PROFESSOR'], ['avaliacoes.php']); ?>
                <?php menuIndisponivel('Evolução', 'growth'); ?>
                <?php menuItem('aulas.php?secao=presenca', 'Presença', 'presence', ['ADMIN', 'PROFESSOR'], [], null, 'presenca'); ?>
                <?php menuIndisponivel('Metas', 'goal'); ?>
                <?php menuItem('camisas.php', 'Camisas', 'shirt', ['ADMIN', 'SECRETARIA'], ['camisas.php']); ?>
                <?php menuIndisponivel('Notificações', 'bell', 0); ?>

                <?php if (hasRole(['ADMIN', 'SECRETARIA'])): ?>
                    <p class="titulo-grupo-menu grupo-gestao">GESTÃO</p>
                <?php endif; ?>
                <?php if (hasRole(['ADMIN', 'SECRETARIA'])): ?>
                    <?php menuItem('alunos.php', 'Alunos', 'students', ['ADMIN', 'SECRETARIA'], ['alunos.php']); ?>
                    <?php menuItem('responsaveis.php', 'Responsáveis', 'users', ['ADMIN', 'SECRETARIA'], ['responsaveis.php']); ?>
                    <?php menuItem('turmas.php', 'Turmas', 'teams', ['ADMIN', 'SECRETARIA'], ['turmas.php']); ?>
                    <?php menuItem('matriculas.php', 'Matrículas', 'enrollments', ['ADMIN', 'SECRETARIA'], ['matriculas.php']); ?>
                <?php endif; ?>
                <?php menuItem('usuarios.php', 'Usuários', 'settings', ['ADMIN'], ['usuarios.php']); ?>
                <?php menuItem('meus_alunos.php', 'Meus alunos', 'students', ['RESPONSAVEL'], ['meus_alunos.php']); ?>
            </nav>
            <div class="rodape-sidebar"><a class="link-menu link-sair" href="logout.php"><svg class="icone-menu" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 17l5-5-5-5M15 12H3"/><path d="M12 3h7a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-7"/></svg><span class="texto-menu">Sair</span></a></div>
        </aside>

        <main class="conteudo-sistema min-w-0">
            <h1 class="mb-6 text-2xl font-bold text-emerald-950"><?= e($title) ?></h1>
            <?php showFlash(); ?>
            <?php if (isset($acoesRapidas[$title])): ?>
                <div class="barra-recursos">
                    <div><h2><?= e($titulosRecursos[$title]) ?></h2><p>Escolha uma ação para abrir o formulário em uma janela.</p></div>
                    <div class="botoes-recursos">
                        <?php foreach ($acoesRapidas[$title] as $acao): ?>
                            <button type="button" class="acao-rapida" data-modal-url="<?= e($acao[2]) ?>" data-modal-titulo="<?= e($acao[0]) ?>"><span><?= e($acao[1]) ?></span><?= e($acao[0]) ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <dialog class="modal-cadastro" id="modalRecurso" aria-labelledby="tituloModalRecurso">
                    <header class="modal-cabecalho"><h2 id="tituloModalRecurso"><?= e($title) ?></h2><button type="button" class="fechar-modal" id="fecharModalRecurso" aria-label="Fechar">×</button></header>
                    <iframe id="iframeRecurso" title="Formulário"></iframe>
                </dialog>
                <script>
                    const modalRecurso = document.getElementById('modalRecurso');
                    const iframeRecurso = document.getElementById('iframeRecurso');
                    const tituloModalRecurso = document.getElementById('tituloModalRecurso');

                    document.addEventListener('click', function (evento) {
                        const botao = evento.target.closest('[data-modal-url]');
                        if (!botao) {
                            return;
                        }

                        iframeRecurso.src = botao.dataset.modalUrl;
                        tituloModalRecurso.textContent = botao.dataset.modalTitulo;
                        modalRecurso.showModal();
                    });

                    document.getElementById('fecharModalRecurso').addEventListener('click', function () {
                        modalRecurso.close();
                    });

                    modalRecurso.addEventListener('close', function () {
                        iframeRecurso.src = 'about:blank';
                    });
                </script>
            <?php endif; ?>
    <?php
    ?>
        <script>
            (function () {
                const menu = document.getElementById('menuLateral');
                const abrir = document.getElementById('abrirMenuMobile');
                const fundo = document.getElementById('fundoMenuMobile');
                if (!menu || !abrir || !fundo) return;
                function fecharMenu() {
                    menu.classList.remove('menu-aberto');
                    fundo.classList.remove('fundo-aberto');
                    abrir.setAttribute('aria-expanded', 'false');
                    document.body.classList.remove('menu-mobile-aberto');
                }
                abrir.addEventListener('click', function () {
                    const aberto = menu.classList.toggle('menu-aberto');
                    fundo.classList.toggle('fundo-aberto', aberto);
                    abrir.setAttribute('aria-expanded', aberto ? 'true' : 'false');
                    document.body.classList.toggle('menu-mobile-aberto', aberto);
                });
                fundo.addEventListener('click', fecharMenu);
                document.addEventListener('keydown', function (evento) {
                    if (evento.key === 'Escape') fecharMenu();
                });
                menu.addEventListener('click', function (evento) {
                    if (evento.target.closest('a')) fecharMenu();
                });
            }());
        </script>
    <?php
}

function pageEnd() {
    if (isset($_GET['mini'])) {
        echo '</main></body></html>';
        return;
    }
    echo '</main></div></body></html>';
}

function actionButton($text) {
    echo '<button class="botao-principal rounded-lg bg-amber-400 px-4 py-2 font-semibold text-emerald-950 shadow-sm transition hover:bg-amber-300" type="submit">' . e($text) . '</button>';
}
