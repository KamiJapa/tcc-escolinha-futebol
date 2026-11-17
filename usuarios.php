<?php
// Gerencia contas e oferece aos administradores um cadastro específico de professores.
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/notificacoes_helpers.php';
checkRole(['ADMIN']);

$db = getDB();
$escolinha = currentEscolinhaId();
$edit = null;

$professores = ($_GET['secao'] ?? '') === 'professores';
$pagina = $professores ? 'usuarios.php?secao=professores' : 'usuarios.php';
$filtroPerfil = $professores ? " AND PERFIL = 'PROFESSOR'" : '';
$perfis = ['ADMIN' => 'Administrador', 'SECRETARIA' => 'Secretaria', 'PROFESSOR' => 'Professor', 'RESPONSAVEL' => 'Responsável'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $acao = postString('action');
    $id = positiveId($_POST['id'] ?? null);
    $actor = (int) $_SESSION['user_id'];
    if (!in_array($acao, ['create', 'update', 'delete'], true) || ($acao !== 'create' && !$id)) {
        flash('error', 'Operação ou usuário inválido.');
        go($pagina);
    }

    $nome = trim(postString('nome'));
    $email = trim(postString('email'));
    $perfil = $professores ? 'PROFESSOR' : postString('perfil');
    $senha = postString('senha');
    $ativo = isset($_POST['ativo']) ? 1 : 0;
    if ($acao !== 'delete' && ($nome === '' || mb_strlen($nome) > 100 || strlen($email) > 100
        || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || !in_array($perfil, ['ADMIN', 'SECRETARIA', 'PROFESSOR', 'RESPONSAVEL'], true)
        || (isset($_POST['ativo']) && !is_string($_POST['ativo']))
        || (isset($_POST['senha']) && !is_string($_POST['senha']))
        || (($acao === 'create' || $senha !== '') && (strlen($senha) < 6 || strlen($senha) > 72 || strpos($senha, "\0") !== false)))) {
        flash('error', 'Informe nome, e-mail válido, perfil e senha válida de 6 a 72 bytes para um novo usuário.');
        go($pagina . ($id ? ($professores ? '&' : '?') . 'editar=' . $id : ''));
    }

    try {
        $db->beginTransaction();
        // Serializa mudanças de perfil, exclusão e desativação nesta escolinha.
        $lock = $db->prepare('SELECT COD_ESCOLINHA FROM TB_ESCOLINHA WHERE COD_ESCOLINHA = ? FOR UPDATE');
        $lock->execute([$escolinha]);
        $admins = $db->prepare("SELECT COD_USUARIO FROM TB_USUARIO WHERE COD_ESCOLINHA = ? AND PERFIL = 'ADMIN' AND ATIVO = 1");
        $admins->execute([$escolinha]);
        $adminIds = array_map('intval', $admins->fetchAll(PDO::FETCH_COLUMN));
        if (!in_array($actor, $adminIds, true)) {
            throw new DomainException('Seu acesso administrativo não está mais ativo.');
        }

        if ($professores && $acao !== 'create') {
            $professor = $db->prepare("SELECT 1 FROM TB_USUARIO WHERE COD_USUARIO=? AND COD_ESCOLINHA=? AND PERFIL='PROFESSOR' FOR UPDATE");
            $professor->execute([$id, $escolinha]);
            if (!$professor->fetchColumn()) throw new DomainException('Professor não encontrado nesta escolinha.');
        }
        $perdeAdmin = $acao === 'delete' || ($acao === 'update' && ($perfil !== 'ADMIN' || !$ativo));
        if ($perdeAdmin && count($adminIds) === 1 && $adminIds[0] === $id) {
            throw new DomainException('Mantenha seu próprio acesso ativo. A escolinha precisa de pelo menos um administrador ativo.');
        }
        if ($acao === 'delete') {
            if ($id === $actor) throw new DomainException('Você não pode excluir seu próprio usuário.');
            $stmt = $db->prepare('DELETE FROM TB_USUARIO WHERE COD_USUARIO = ? AND COD_ESCOLINHA = ?');
            $stmt->execute([$id, $escolinha]);
            if (!$stmt->rowCount()) throw new DomainException('Usuário não encontrado nesta escolinha.');
            flash('success', 'Usuário excluído.');
        } elseif ($acao === 'create') {
            $db->prepare('INSERT INTO TB_USUARIO (COD_ESCOLINHA,NOME,EMAIL,SENHA_HASH,PERFIL,ATIVO) VALUES (?,?,?,?,?,?)')
                ->execute([$escolinha, $nome, $email, password_hash($senha, PASSWORD_DEFAULT), $perfil, $ativo]);
            $idNovo = (int) $db->lastInsertId();
            if ($perfil === 'PROFESSOR' && $ativo) {
                notificacaoEnviar($db, $escolinha, [$idNovo], 'APROVACAO_PROFESSOR', 'Acesso de professor aprovado', 'Seu acesso de professor à escolinha está ativo.', 'dashboard.php', (int) $_SESSION['user_id']);
            }
            flash('success', $professores ? 'Professor cadastrado.' : 'Usuário cadastrado.');
        } elseif ($acao === 'update') {
            $consulta = $db->prepare('SELECT PERFIL, ATIVO FROM TB_USUARIO WHERE COD_USUARIO = ? AND COD_ESCOLINHA = ?');
            $consulta->execute([$id, $escolinha]);
            $anterior = $consulta->fetch();
            if (!$anterior) throw new DomainException('Usuário não encontrado nesta escolinha.');
            if ($anterior['PERFIL'] === 'ALUNO') throw new DomainException('Gerencie o acesso do aluno pelo cadastro do atleta.');
            if ($id === (int) $_SESSION['user_id'] && ($perfil !== $anterior['PERFIL'] || !$ativo)) {
                throw new DomainException('Mantenha seu próprio perfil e acesso ativo para não perder o controle administrativo.');
            }
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
        $db->commit();
        if ($professores && $acao !== 'delete') voltarAposSalvarCadastro($pagina);
    } catch (DomainException $erro) {
        if ($db->inTransaction()) $db->rollBack();
        flash('error', $erro->getMessage());
    } catch (PDOException $erro) {
        if ($db->inTransaction()) $db->rollBack();
        $duplicado = (int) ($erro->errorInfo[1] ?? 0) === 1062;
        flash('error', $duplicado ? 'Já existe um usuário com este e-mail.' : 'Não foi possível alterar o usuário. Confira se há registros vinculados.');
    }
    go($pagina);
}

if (isset($_GET['editar'])) {
    $editId = positiveId($_GET['editar']);
    if (!$editId) { flash('error', 'Usuário inválido.'); go($pagina); }
    $consulta = $db->prepare('SELECT u.*, a.COD_ALUNO FROM TB_USUARIO u LEFT JOIN TB_ALUNO a ON a.COD_USUARIO = u.COD_USUARIO WHERE u.COD_USUARIO = ? AND u.COD_ESCOLINHA = ?');
    $consulta->execute([$editId, $escolinha]);
    $edit = $consulta->fetch();
    if (!$edit || ($professores && $edit['PERFIL'] !== 'PROFESSOR')) { flash('error', 'Usuário não encontrado.'); go($pagina); }
    if ($edit['PERFIL'] === 'ALUNO') {
        if (!empty($edit['COD_ALUNO'])) go('alunos.php?editar=' . (int) $edit['COD_ALUNO']);
        flash('error', 'Este acesso de aluno não está vinculado a um cadastro esportivo.');
        go($pagina);
    }
}

$consulta = $db->prepare('SELECT u.COD_USUARIO,u.NOME,u.EMAIL,u.PERFIL,u.ATIVO,a.COD_ALUNO FROM TB_USUARIO u LEFT JOIN TB_ALUNO a ON a.COD_USUARIO = u.COD_USUARIO WHERE u.COD_ESCOLINHA = ?' . $filtroPerfil . ' ORDER BY u.NOME');
$consulta->execute([$escolinha]);
$lista = $consulta->fetchAll();
pageStart($professores ? 'Professores' : 'Usuários');
?>
<section>
    <h2><?= $edit ? ($professores ? 'Editar professor' : 'Editar usuário') : ($professores ? 'Novo professor' : 'Novo usuário') ?></h2>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>">
        <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['COD_USUARIO'] ?>"><?php endif; ?>
        <label>Nome<input required name="nome" value="<?= e($edit['NOME'] ?? '') ?>"></label>
        <label>E-mail<input required type="email" name="email" value="<?= e($edit['EMAIL'] ?? '') ?>"></label>
        <?php if (!$professores): ?><label>Perfil<select name="perfil"><?php foreach ($perfis as $valor => $rotulo): ?><option value="<?= e($valor) ?>" <?= ($edit['PERFIL'] ?? '') === $valor ? 'selected' : '' ?>><?= e($rotulo) ?></option><?php endforeach; ?></select></label><?php endif; ?>
        <label>Senha <?= $edit ? '(deixe vazia para manter)' : '' ?><input <?= $edit ? '' : 'required' ?> type="password" name="senha" minlength="6"></label>
        <label><input type="checkbox" name="ativo" <?= ($edit['ATIVO'] ?? 1) ? 'checked' : '' ?>> Usuário ativo</label>
        <div><?php actionButton($edit ? 'Salvar alterações' : ($professores ? 'Cadastrar professor' : 'Cadastrar usuário')); ?><?php if ($edit): ?><a href="<?= e($pagina) ?>">Cancelar</a><?php endif; ?></div>
    </form>
</section>
<section><table><thead><tr><th>Nome</th><th>E-mail</th><th>Perfil</th><th>Estado</th><th>Ações</th></tr></thead><tbody>
<?php foreach ($lista as $usuario): ?><tr><td><?= e($usuario['NOME']) ?></td><td><?= e($usuario['EMAIL']) ?></td><td><?= e($usuario['PERFIL']) ?></td><td><?= $usuario['ATIVO'] ? 'Ativo' : 'Inativo' ?></td><td>
    <?php if ($usuario['PERFIL'] === 'ALUNO' && $usuario['COD_ALUNO']): ?><button type="button" class="acao-tabela" data-modal-url="alunos.php?mini=1&amp;editar=<?= (int) $usuario['COD_ALUNO'] ?>" data-modal-titulo="Acesso do aluno">Gerenciar acesso</button><?php else: ?><button type="button" class="acao-tabela" data-modal-url="<?= e($pagina . ($professores ? '&' : '?') . 'mini=1&editar=' . (int) $usuario['COD_USUARIO']) ?>" data-modal-titulo="<?= $professores ? 'Editar professor' : 'Editar usuário' ?>">Editar</button><?php endif; ?>
    <?php if ((int) $usuario['COD_USUARIO'] !== (int) $_SESSION['user_id']): ?><form method="post" data-confirm-submit="Excluir este usuário?"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $usuario['COD_USUARIO'] ?>"><button>Excluir</button></form><?php endif; ?>
</td></tr><?php endforeach; ?>
</tbody></table></section>
<?php pageEnd(); ?>
