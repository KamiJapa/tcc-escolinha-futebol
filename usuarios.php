<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/notificacoes_helpers.php';
checkRole(['ADMIN']);

$db = getDB();
$escolinha = currentEscolinhaId();
$edit = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $acao = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    if ($acao === 'delete') {
        if ($id === (int) $_SESSION['user_id']) {
            flash('error', 'Você não pode excluir seu próprio usuário.');
        } else {
            try {
                $db->prepare('DELETE FROM TB_USUARIO WHERE COD_USUARIO = ? AND COD_ESCOLINHA = ?')->execute([$id, $escolinha]);
                flash('success', 'Usuário excluído.');
            } catch (PDOException $erro) {
                flash('error', 'Não é possível excluir este usuário porque ele está vinculado a uma avaliação técnica.');
            }
        }
        go('usuarios.php');
    }

    $nome = trim($_POST['nome'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $perfil = $_POST['perfil'] ?? '';
    $senha = $_POST['senha'] ?? '';
    $ativo = isset($_POST['ativo']) ? 1 : 0;
    if ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($perfil, ['ADMIN', 'SECRETARIA', 'PROFESSOR', 'RESPONSAVEL'], true) || ($acao === 'create' && strlen($senha) < 6)) {
        flash('error', 'Informe nome, e-mail válido, perfil e senha de ao menos 6 caracteres para um novo usuário.');
        go('usuarios.php' . ($id ? '?editar=' . $id : ''));
    }

    try {
        if ($acao === 'create') {
            $db->prepare('INSERT INTO TB_USUARIO (COD_ESCOLINHA,NOME,EMAIL,SENHA_HASH,PERFIL,ATIVO) VALUES (?,?,?,?,?,?)')
                ->execute([$escolinha, $nome, $email, password_hash($senha, PASSWORD_DEFAULT), $perfil, $ativo]);
            $idNovo = (int) $db->lastInsertId();
            if ($perfil === 'PROFESSOR' && $ativo) {
                notificacaoEnviar($db, $escolinha, [$idNovo], 'APROVACAO_PROFESSOR', 'Acesso de professor aprovado', 'Seu acesso de professor à escolinha está ativo.', 'dashboard.php', (int) $_SESSION['user_id']);
            }
            flash('success', 'Usuário cadastrado.');
        } elseif ($acao === 'update') {
            $consulta = $db->prepare('SELECT PERFIL, ATIVO FROM TB_USUARIO WHERE COD_USUARIO = ? AND COD_ESCOLINHA = ?');
            $consulta->execute([$id, $escolinha]);
            $anterior = $consulta->fetch();
            if (!$anterior) { flash('error', 'Usuário não encontrado.'); go('usuarios.php'); }
            if ($anterior['PERFIL'] === 'ALUNO') { flash('error', 'Gerencie o acesso do aluno pelo cadastro do atleta.'); go('usuarios.php'); }
            if ($senha !== '') {
                $db->prepare('UPDATE TB_USUARIO SET NOME=?,EMAIL=?,PERFIL=?,ATIVO=?,SENHA_HASH=? WHERE COD_USUARIO=? AND COD_ESCOLINHA=?')
                    ->execute([$nome, $email, $perfil, $ativo, password_hash($senha, PASSWORD_DEFAULT), $id, $escolinha]);
            } else {
                $db->prepare('UPDATE TB_USUARIO SET NOME=?,EMAIL=?,PERFIL=?,ATIVO=? WHERE COD_USUARIO=? AND COD_ESCOLINHA=?')
                    ->execute([$nome, $email, $perfil, $ativo, $id, $escolinha]);
            }
            $foiAprovado = $perfil === 'PROFESSOR' && $ativo && ($anterior['PERFIL'] !== 'PROFESSOR' || !(int) $anterior['ATIVO']);
            if ($foiAprovado) {
                notificacaoEnviar($db, $escolinha, [$id], 'APROVACAO_PROFESSOR', 'Acesso de professor aprovado', 'Seu acesso de professor à escolinha está ativo.', 'dashboard.php', (int) $_SESSION['user_id']);
            }
            flash('success', 'Usuário atualizado.');
        }
    } catch (PDOException $erro) {
        flash('error', 'Já existe um usuário com este e-mail.');
    }
    go('usuarios.php');
}

if (isset($_GET['editar'])) {
    $consulta = $db->prepare('SELECT u.*, a.COD_ALUNO FROM TB_USUARIO u LEFT JOIN TB_ALUNO a ON a.COD_USUARIO = u.COD_USUARIO WHERE u.COD_USUARIO = ? AND u.COD_ESCOLINHA = ?');
    $consulta->execute([(int) $_GET['editar'], $escolinha]);
    $edit = $consulta->fetch();
    if (!$edit) { flash('error', 'Usuário não encontrado.'); go('usuarios.php'); }
    if ($edit['PERFIL'] === 'ALUNO') {
        if (!empty($edit['COD_ALUNO'])) go('alunos.php?editar=' . (int) $edit['COD_ALUNO']);
        flash('error', 'Este acesso de aluno não está vinculado a um cadastro esportivo.');
        go('usuarios.php');
    }
}

$consulta = $db->prepare('SELECT u.COD_USUARIO,u.NOME,u.EMAIL,u.PERFIL,u.ATIVO,a.COD_ALUNO FROM TB_USUARIO u LEFT JOIN TB_ALUNO a ON a.COD_USUARIO = u.COD_USUARIO WHERE u.COD_ESCOLINHA = ? ORDER BY u.NOME');
$consulta->execute([$escolinha]);
$lista = $consulta->fetchAll();
pageStart('Usuários');
?>
<section>
    <h2><?= $edit ? 'Editar usuário' : 'Novo usuário' ?></h2>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>">
        <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['COD_USUARIO'] ?>"><?php endif; ?>
        <label>Nome<input required name="nome" value="<?= e($edit['NOME'] ?? '') ?>"></label>
        <label>E-mail<input required type="email" name="email" value="<?= e($edit['EMAIL'] ?? '') ?>"></label>
        <label>Perfil<select name="perfil"><?php foreach (['ADMIN' => 'Administrador', 'SECRETARIA' => 'Secretaria', 'PROFESSOR' => 'Professor', 'RESPONSAVEL' => 'Responsável'] as $valor => $rotulo): ?><option value="<?= e($valor) ?>" <?= ($edit['PERFIL'] ?? '') === $valor ? 'selected' : '' ?>><?= e($rotulo) ?></option><?php endforeach; ?></select></label>
        <label>Senha <?= $edit ? '(deixe vazia para manter)' : '' ?><input <?= $edit ? '' : 'required' ?> type="password" name="senha" minlength="6"></label>
        <label><input type="checkbox" name="ativo" <?= ($edit['ATIVO'] ?? 1) ? 'checked' : '' ?>> Usuário ativo</label>
        <div><?php actionButton($edit ? 'Salvar alterações' : 'Cadastrar usuário'); ?><?php if ($edit): ?><a href="usuarios.php">Cancelar</a><?php endif; ?></div>
    </form>
</section>
<section><table><thead><tr><th>Nome</th><th>E-mail</th><th>Perfil</th><th>Estado</th><th>Ações</th></tr></thead><tbody>
<?php foreach ($lista as $usuario): ?><tr><td><?= e($usuario['NOME']) ?></td><td><?= e($usuario['EMAIL']) ?></td><td><?= e($usuario['PERFIL']) ?></td><td><?= $usuario['ATIVO'] ? 'Ativo' : 'Inativo' ?></td><td>
    <?php if ($usuario['PERFIL'] === 'ALUNO' && $usuario['COD_ALUNO']): ?><button type="button" class="acao-tabela" data-modal-url="alunos.php?mini=1&amp;editar=<?= (int) $usuario['COD_ALUNO'] ?>" data-modal-titulo="Acesso do aluno">Gerenciar acesso</button><?php else: ?><button type="button" class="acao-tabela" data-modal-url="usuarios.php?mini=1&amp;editar=<?= (int) $usuario['COD_USUARIO'] ?>" data-modal-titulo="Editar usuário">Editar</button><?php endif; ?>
    <?php if ((int) $usuario['COD_USUARIO'] !== (int) $_SESSION['user_id']): ?><form method="post" onsubmit="return confirm('Excluir este usuário?');"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $usuario['COD_USUARIO'] ?>"><button>Excluir</button></form><?php endif; ?>
</td></tr><?php endforeach; ?>
</tbody></table></section>
<?php pageEnd(); ?>
