<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/config/database.php';

checkRole(['ADMIN', 'SECRETARIA']);
$db = getDB();
$escolinha = currentEscolinhaId();
$edit = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'delete') {
        $stmt = $db->prepare('DELETE FROM TB_ALUNO WHERE COD_ALUNO = ? AND COD_ESCOLINHA = ?');
        $stmt->execute([$id, $escolinha]);
        flash('success', 'Aluno excluído com sucesso.');
        go('alunos.php');
    }

    $nome = trim($_POST['nome'] ?? '');
    $nascimento = $_POST['data_nascimento'] ?? '';
    $responsavel = (int) ($_POST['cod_responsavel'] ?? 0);
    $posicao = trim($_POST['posicao'] ?? '');
    $status = $_POST['status'] ?? 'ATIVO';
    $observacoes = trim($_POST['observacoes_medicas'] ?? '');

    if ($nome === '' || $nascimento === '' || $responsavel <= 0 || strtotime($nascimento) > time() || !in_array($status, ['ATIVO', 'INATIVO', 'PENDENTE'], true)) {
        flash('error', 'Revise os campos obrigatórios e a data de nascimento.');
        go('alunos.php' . ($id ? '?editar=' . $id : ''));
    }

    $validResponsavel = $db->prepare('SELECT 1 FROM TB_RESPONSAVEL WHERE COD_RESPONSAVEL = ? AND COD_ESCOLINHA = ?');
    $validResponsavel->execute([$responsavel, $escolinha]);
    if (!$validResponsavel->fetchColumn()) {
        flash('error', 'O responsável selecionado não pertence a esta escolinha.');
        go('alunos.php');
    }

    // Evita repetir o mesmo aluno para o mesmo responsável.
    $duplicado = $db->prepare(
        'SELECT 1 FROM TB_ALUNO
         WHERE COD_ESCOLINHA = ? AND COD_RESPONSAVEL = ?
           AND LOWER(TRIM(NOME)) = LOWER(TRIM(?)) AND DATA_NASCIMENTO = ?
           AND COD_ALUNO <> ? LIMIT 1'
    );
    $duplicado->execute([$escolinha, $responsavel, $nome, $nascimento, $id]);
    if ($duplicado->fetchColumn()) {
        flash('error', 'Este responsável já tem um aluno com o mesmo nome e data de nascimento.');
        go('alunos.php' . ($id ? '?editar=' . $id : ''));
    }

    if ($action === 'create') {
        $stmt = $db->prepare('INSERT INTO TB_ALUNO (COD_ESCOLINHA, COD_RESPONSAVEL, NOME, DATA_NASCIMENTO, POSICAO, STATUS, DATA_MATRICULA, OBSERVACOES_MEDICAS) VALUES (?, ?, ?, ?, ?, ?, CURDATE(), ?)');
        $stmt->execute([$escolinha, $responsavel, $nome, $nascimento, $posicao ?: 'Não definida', $status, $observacoes ?: null]);
        flash('success', 'Aluno cadastrado. Agora faça a matrícula em uma turma.');
    } elseif ($action === 'update') {
        $stmt = $db->prepare('UPDATE TB_ALUNO SET COD_RESPONSAVEL = ?, NOME = ?, DATA_NASCIMENTO = ?, POSICAO = ?, STATUS = ?, OBSERVACOES_MEDICAS = ? WHERE COD_ALUNO = ? AND COD_ESCOLINHA = ?');
        $stmt->execute([$responsavel, $nome, $nascimento, $posicao ?: 'Não definida', $status, $observacoes ?: null, $id, $escolinha]);
        flash('success', 'Dados do aluno atualizados.');
    }
    go('alunos.php');
}

if (isset($_GET['editar'])) {
    $stmt = $db->prepare('SELECT * FROM TB_ALUNO WHERE COD_ALUNO = ? AND COD_ESCOLINHA = ?');
    $stmt->execute([(int) $_GET['editar'], $escolinha]);
    $edit = $stmt->fetch();
    if (!$edit) {
        flash('error', 'Aluno não encontrado.');
        go('alunos.php');
    }
}

$responsaveis = $db->prepare('SELECT COD_RESPONSAVEL, NOME FROM TB_RESPONSAVEL WHERE COD_ESCOLINHA = ? ORDER BY NOME');
$responsaveis->execute([$escolinha]);
$responsaveis = $responsaveis->fetchAll();
$lista = $db->prepare('SELECT a.*, r.NOME AS RESPONSAVEL, COUNT(m.COD_MATRICULA) AS TURMAS_ATIVAS FROM TB_ALUNO a JOIN TB_RESPONSAVEL r ON r.COD_RESPONSAVEL = a.COD_RESPONSAVEL LEFT JOIN TB_MATRICULA m ON m.COD_ALUNO = a.COD_ALUNO AND m.STATUS = "ATIVA" WHERE a.COD_ESCOLINHA = ? GROUP BY a.COD_ALUNO ORDER BY a.NOME');
$lista->execute([$escolinha]);
$lista = $lista->fetchAll();

pageStart('Alunos');
$value = function ($field, $default = '') use ($edit) { return e($edit[$field] ?? $default); };
?>
<?php if (isset($_GET['mini'])): ?>
<section>
    <h2><?= $edit ? 'Editar aluno' : 'Novo aluno' ?></h2>
    <?php if (!$responsaveis): ?><p>Cadastre um responsável antes de cadastrar um aluno.</p><?php else: ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>">
        <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['COD_ALUNO'] ?>"><?php endif; ?>
        <label>Nome completo<input required name="nome" value="<?= $value('NOME') ?>"></label>
        <label>Data de nascimento<input required type="date" name="data_nascimento" value="<?= $value('DATA_NASCIMENTO') ?>"></label>
        <label>Responsável<select required name="cod_responsavel"><option value="">Selecione</option><?php foreach ($responsaveis as $r): ?><option value="<?= (int) $r['COD_RESPONSAVEL'] ?>" <?= ($edit['COD_RESPONSAVEL'] ?? '') == $r['COD_RESPONSAVEL'] ? 'selected' : '' ?>><?= e($r['NOME']) ?></option><?php endforeach; ?></select></label>
        <label>Posição<input name="posicao" value="<?= $value('POSICAO', 'Meio-Campo') ?>"></label>
        <label>Situação<select name="status"><?php foreach (['ATIVO', 'INATIVO', 'PENDENTE'] as $status): ?><option <?= ($edit['STATUS'] ?? 'ATIVO') === $status ? 'selected' : '' ?>><?= $status ?></option><?php endforeach; ?></select></label>
        <label>Observações médicas<textarea name="observacoes_medicas"><?= $value('OBSERVACOES_MEDICAS') ?></textarea></label>
        <div><?php actionButton($edit ? 'Salvar alterações' : 'Cadastrar aluno'); ?><?php if ($edit): ?><a href="alunos.php">Cancelar</a><?php endif; ?></div>
    </form>
    <?php endif; ?>
</section>
<?php else: ?>
<div class="alunos-acoes">
    <div><h2>Alunos cadastrados</h2><p>Cadastre e gerencie os alunos da escolinha.</p></div>
    <div class="alunos-botoes">
        <button type="button" class="acao-rapida" data-url="alunos.php?mini=1" data-titulo="Novo aluno"><span>＋</span>Novo aluno</button>
        <button type="button" class="acao-rapida" data-url="camisas.php?mini=1" data-titulo="Camisas dos alunos"><span>👕</span>Camisas</button>
    </div>
</div>
<section class="lista-alunos"><div class="tabela-alunos"><table><thead><tr><th>Aluno</th><th>Responsável</th><th>Situação</th><th>Turmas</th><th>Ações</th></tr></thead><tbody><?php foreach ($lista as $aluno): ?><tr><td><?= e($aluno['NOME']) ?></td><td><?= e($aluno['RESPONSAVEL']) ?></td><td><?= e($aluno['STATUS']) ?></td><td><?= (int) $aluno['TURMAS_ATIVAS'] ?></td><td><button type="button" class="acao-tabela" data-url="alunos.php?mini=1&amp;editar=<?= (int) $aluno['COD_ALUNO'] ?>" data-titulo="Editar aluno">Editar</button><form method="post" onsubmit="return confirm('Excluir este aluno? As matrículas e presenças relacionadas serão removidas.');"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $aluno['COD_ALUNO'] ?>"><button>Excluir</button></form></td></tr><?php endforeach; ?><?php if (!$lista): ?><tr><td colspan="5">Nenhum aluno cadastrado.</td></tr><?php endif; ?></tbody></table></div></section>
<dialog class="modal-cadastro" id="modalAlunos" aria-labelledby="tituloModalAlunos">
    <header class="modal-cabecalho"><h2 id="tituloModalAlunos">Alunos</h2><button type="button" class="fechar-modal" id="fecharModalAlunos" aria-label="Fechar">×</button></header>
    <iframe id="iframeAlunos" title="Cadastro de aluno ou gestão de camisas"></iframe>
</dialog>
<script>
    const modalAlunos = document.getElementById('modalAlunos');
    const iframeAlunos = document.getElementById('iframeAlunos');
    const tituloModalAlunos = document.getElementById('tituloModalAlunos');

    document.querySelectorAll('[data-url]').forEach(function (botao) {
        botao.addEventListener('click', function () {
            iframeAlunos.src = botao.dataset.url;
            tituloModalAlunos.textContent = botao.dataset.titulo;
            modalAlunos.showModal();
        });
    });

    document.getElementById('fecharModalAlunos').addEventListener('click', function () {
        modalAlunos.close();
    });

    modalAlunos.addEventListener('close', function () {
        iframeAlunos.src = 'about:blank';
    });
</script>
<?php endif; ?>
<?php pageEnd(); ?>
