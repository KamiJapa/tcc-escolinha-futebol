<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/config/database.php';
checkRole(['ADMIN', 'SECRETARIA', 'PROFESSOR', 'RESPONSAVEL', 'ALUNO']);

$db = getDB();
$escolinha = currentEscolinhaId();
$usuario = (int) $_SESSION['user_id'];
$filtros = ['todas' => null, 'nao-lidas' => 0, 'lidas' => 1];
$filtro = $_GET['filtro'] ?? 'todas';
if (!isset($filtros[$filtro])) $filtro = 'todas';
$urlRetorno = 'notificacoes.php?filtro=' . rawurlencode($filtro);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $acao = $_POST['acao'] ?? '';
    $filtroPost = $_POST['filtro'] ?? 'todas';
    if (!isset($filtros[$filtroPost])) $filtroPost = 'todas';
    $urlRetorno = 'notificacoes.php?filtro=' . rawurlencode($filtroPost);
    if ($acao === 'marcar_todas') {
        $stmt = $db->prepare('UPDATE TB_NOTIFICACAO SET LIDA = 1 WHERE COD_ESCOLINHA = ? AND COD_USUARIO = ? AND LIDA = 0');
        $stmt->execute([$escolinha, $usuario]);
        flash('success', $stmt->rowCount() ? 'Notificações marcadas como lidas.' : 'Não há notificações não lidas.');
        go($urlRetorno);
    }

    $id = (int) ($_POST['notificacao'] ?? 0);
    if ($acao === 'marcar_lida' || $acao === 'marcar_nao_lida') {
        $lida = $acao === 'marcar_lida' ? 1 : 0;
        $stmt = $db->prepare('UPDATE TB_NOTIFICACAO SET LIDA = ? WHERE COD_NOTIFICACAO = ? AND COD_ESCOLINHA = ? AND COD_USUARIO = ?');
        $stmt->execute([$lida, $id, $escolinha, $usuario]);
        flash('success', $lida ? 'Notificação marcada como lida.' : 'Notificação marcada como não lida.');
        go($urlRetorno);
    }
    if ($acao === 'abrir') {
        $stmt = $db->prepare('SELECT URL FROM TB_NOTIFICACAO WHERE COD_NOTIFICACAO = ? AND COD_ESCOLINHA = ? AND COD_USUARIO = ?');
        $stmt->execute([$id, $escolinha, $usuario]);
        $destino = $stmt->fetchColumn();
        if ($destino) {
            $db->prepare('UPDATE TB_NOTIFICACAO SET LIDA = 1 WHERE COD_NOTIFICACAO = ? AND COD_ESCOLINHA = ? AND COD_USUARIO = ?')->execute([$id, $escolinha, $usuario]);
            if (preg_match('/^[a-zA-Z0-9_-]+\.php(?:\?[a-zA-Z0-9_=&-]*)?$/', $destino)) go($destino);
        }
        go($urlRetorno);
    }
    flash('error', 'Ação de notificação inválida.');
    go($urlRetorno);
}

$totalNaoLidas = notificacoesNaoLidas($db, $escolinha, $usuario);
$totalStmt = $db->prepare('SELECT COUNT(*) FROM TB_NOTIFICACAO WHERE COD_ESCOLINHA = ? AND COD_USUARIO = ?');
$totalStmt->execute([$escolinha, $usuario]);
$total = (int) $totalStmt->fetchColumn();
$contagemFiltrada = $total;
if ($filtros[$filtro] !== null) {
    $totalFiltradoStmt = $db->prepare('SELECT COUNT(*) FROM TB_NOTIFICACAO WHERE COD_ESCOLINHA = ? AND COD_USUARIO = ? AND LIDA = ?');
    $totalFiltradoStmt->execute([$escolinha, $usuario, $filtros[$filtro]]);
    $contagemFiltrada = (int) $totalFiltradoStmt->fetchColumn();
}
$itensPorPagina = 50;
$ultimaPagina = max(1, (int) ceil($contagemFiltrada / $itensPorPagina));
$pagina = filter_var($_GET['pagina'] ?? 1, FILTER_VALIDATE_INT);
$pagina = $pagina === false || $pagina < 1 ? 1 : min($pagina, $ultimaPagina);
$offset = ($pagina - 1) * $itensPorPagina;
$sql = 'SELECT COD_NOTIFICACAO, TIPO, TITULO, DESCRICAO, URL, LIDA, CRIADO_EM FROM TB_NOTIFICACAO WHERE COD_ESCOLINHA = ? AND COD_USUARIO = ?';
$params = [$escolinha, $usuario];
if ($filtros[$filtro] !== null) { $sql .= ' AND LIDA = ?'; $params[] = $filtros[$filtro]; }
$sql .= ' ORDER BY CRIADO_EM DESC, COD_NOTIFICACAO DESC LIMIT ' . (int) $itensPorPagina . ' OFFSET ' . (int) $offset;
$stmt = $db->prepare($sql);
$stmt->execute($params);
$notificacoes = $stmt->fetchAll();
$rotulos = notificacoesTipos();

pageStart('Notificações');
?>
<div class="notificacoes-page">
    <header class="notificacoes-hero"><div><p class="notificacoes-kicker">CENTRAL DA ESCOLINHA</p><h2>Fique por dentro do que acontece.</h2><p>Treinos, avaliações, jogos e atualizações importantes da sua escolinha.</p></div><span class="notificacoes-hero-icone" aria-hidden="true">♧</span></header>
    <section class="notificacoes-painel" aria-label="Central de notificações">
        <div class="notificacoes-toolbar"><div><h3>Suas notificações</h3><p><?= $totalNaoLidas ?> não lidas <span>·</span> <?= $total ?> no total</p></div><?php if ($totalNaoLidas): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="acao" value="marcar_todas"><input type="hidden" name="filtro" value="<?= e($filtro) ?>"><button class="notificacao-acao-principal" type="submit">Marcar todas como lidas</button></form><?php endif; ?></div>
        <nav class="notificacoes-filtros" aria-label="Filtrar notificações"><?php foreach (['todas' => 'Todas', 'nao-lidas' => 'Não lidas', 'lidas' => 'Lidas'] as $chave => $nome): ?><a class="<?= $filtro === $chave ? 'ativo' : '' ?>" href="notificacoes.php?filtro=<?= e($chave) ?>" <?= $filtro === $chave ? 'aria-current="page"' : '' ?>><?= e($nome) ?><?php if ($chave === 'nao-lidas' && $totalNaoLidas): ?><span><?= $totalNaoLidas ?></span><?php endif; ?></a><?php endforeach; ?></nav>
        <?php if (!$notificacoes): ?><div class="notificacoes-vazio"><span aria-hidden="true">✓</span><strong><?= $filtro === 'nao-lidas' ? 'Tudo em dia' : 'Nenhuma notificação por aqui' ?></strong><p><?= $filtro === 'nao-lidas' ? 'Quando houver uma novidade, ela aparecerá nesta lista.' : 'As atualizações relevantes da escolinha aparecerão aqui.' ?></p></div>
        <?php else: ?><div class="notificacoes-lista">
            <?php foreach ($notificacoes as $notificacao): $data = new DateTimeImmutable($notificacao['CRIADO_EM']); $hoje = new DateTimeImmutable('today'); $dataRotulo = $data->format('Y-m-d') === $hoje->format('Y-m-d') ? 'Hoje, ' . $data->format('H:i') : $data->format('d/m/Y · H:i'); ?>
                <article class="notificacao-item <?= $notificacao['LIDA'] ? 'lida' : 'nao-lida' ?>"><span class="notificacao-marcador" aria-hidden="true"></span><div class="notificacao-conteudo"><div class="notificacao-meta"><span class="notificacao-tipo"><?= e($rotulos[$notificacao['TIPO']] ?? $rotulos['OUTRA_ALTERACAO']) ?></span><time datetime="<?= e(date(DATE_ATOM, strtotime($notificacao['CRIADO_EM']))) ?>"><?= e($dataRotulo) ?></time></div><h4><?= e($notificacao['TITULO']) ?></h4><p><?= nl2br(e($notificacao['DESCRICAO'])) ?></p><div class="notificacao-botoes"><?php if ($notificacao['URL']): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="acao" value="abrir"><input type="hidden" name="notificacao" value="<?= (int) $notificacao['COD_NOTIFICACAO'] ?>"><input type="hidden" name="filtro" value="<?= e($filtro) ?>"><button class="notificacao-abrir" type="submit">Ver detalhes <span aria-hidden="true">↗</span></button></form><?php endif; ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="acao" value="<?= $notificacao['LIDA'] ? 'marcar_nao_lida' : 'marcar_lida' ?>"><input type="hidden" name="notificacao" value="<?= (int) $notificacao['COD_NOTIFICACAO'] ?>"><input type="hidden" name="filtro" value="<?= e($filtro) ?>"><button class="notificacao-alternar" type="submit"><?= $notificacao['LIDA'] ? 'Marcar como não lida' : 'Marcar como lida' ?></button></form></div></div><span class="notificacao-estado"><?= $notificacao['LIDA'] ? 'Lida' : 'Não lida' ?></span></article>
            <?php endforeach; ?>
        </div><?php endif; ?>
        <?php if ($ultimaPagina > 1): ?><nav class="notificacoes-paginacao" aria-label="Paginação de notificações"><span>Página <?= $pagina ?> de <?= $ultimaPagina ?></span><div><?php if ($pagina > 1): ?><a href="notificacoes.php?filtro=<?= e($filtro) ?>&pagina=<?= $pagina - 1 ?>">← Anterior</a><?php endif; ?><?php if ($pagina < $ultimaPagina): ?><a href="notificacoes.php?filtro=<?= e($filtro) ?>&pagina=<?= $pagina + 1 ?>">Próxima →</a><?php endif; ?></div></nav><?php endif; ?>
    </section>
</div>
<?php pageEnd(); ?>
