<?php
require_once __DIR__ . '/includes/layout.php'; require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/notificacoes_helpers.php';
checkRole(['ADMIN', 'SECRETARIA']); $db = getDB(); $escolinha = currentEscolinhaId(); $edit = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf(); $action = $_POST['action'] ?? ''; $id = (int) ($_POST['id'] ?? 0);
    if ($action === 'delete') { try { $db->prepare('DELETE m FROM TB_MATRICULA m JOIN TB_ALUNO a ON a.COD_ALUNO = m.COD_ALUNO WHERE m.COD_MATRICULA = ? AND a.COD_ESCOLINHA = ?')->execute([$id, $escolinha]); flash('success', 'Matrícula removida.'); } catch (PDOException $e) { flash('error', 'Não foi possível remover esta matrícula.'); } go('matriculas.php'); }
    $aluno = (int) ($_POST['cod_aluno'] ?? 0); $turma = (int) ($_POST['cod_turma'] ?? 0); $data = $_POST['data_matricula'] ?? ''; $status = $_POST['status'] ?? 'ATIVA';
    if ($action === 'update') { $owner = $db->prepare('SELECT 1 FROM TB_MATRICULA m JOIN TB_ALUNO a ON a.COD_ALUNO = m.COD_ALUNO WHERE m.COD_MATRICULA = ? AND a.COD_ESCOLINHA = ?'); $owner->execute([$id, $escolinha]); if (!$owner->fetchColumn()) { flash('error', 'Matrícula não encontrada.'); go('matriculas.php'); } }
    try {
        if (!in_array($action, ['create', 'update'], true) || !validDate($data) || !in_array($status, ['ATIVA','ENCERRADA'], true)) throw new InvalidArgumentException('Dados da matrícula inválidos.');
        $db->beginTransaction();
        // Serializa matrículas da mesma turma para respeitar a capacidade.
        $q = $db->prepare('SELECT CAPACIDADE,ATIVA FROM TB_TURMA WHERE COD_TURMA=? AND COD_ESCOLINHA=? FOR UPDATE');
        $q->execute([$turma,$escolinha]); $t=$q->fetch();
        $q = $db->prepare('SELECT STATUS FROM TB_ALUNO WHERE COD_ALUNO=? AND COD_ESCOLINHA=?');
        $q->execute([$aluno,$escolinha]); $a=$q->fetchColumn();
        if (!$t || !$a || ($status==='ATIVA' && (!$t['ATIVA'] || $a!=='ATIVO'))) throw new InvalidArgumentException('Aluno e turma devem pertencer à escolinha e estar ativos para uma matrícula ativa.');
        $q = $db->prepare("SELECT COUNT(*) FROM TB_MATRICULA WHERE COD_TURMA=? AND STATUS='ATIVA' AND COD_MATRICULA<>?");
        $q->execute([$turma,$id]);
        if ($status==='ATIVA' && (int)$q->fetchColumn()>=(int)$t['CAPACIDADE']) throw new InvalidArgumentException('Esta turma já atingiu a capacidade máxima.');
        if ($action==='create') {
            $db->prepare('INSERT INTO TB_MATRICULA (COD_ALUNO,COD_TURMA,DATA_MATRICULA,STATUS) VALUES (?,?,?,?)')->execute([$aluno,$turma,$data,$status]);
            notificacaoEnviar($db,$escolinha,notificacaoDestinatariosAluno($db,$escolinha,$aluno),'NOVA_MATRICULA','Nova matrícula','Uma matrícula foi registrada para você na turma selecionada.','meus_alunos.php',(int)$_SESSION['user_id']);
        } else {
            $db->prepare('UPDATE TB_MATRICULA SET COD_ALUNO=?,COD_TURMA=?,DATA_MATRICULA=?,STATUS=? WHERE COD_MATRICULA=?')->execute([$aluno,$turma,$data,$status,$id]);
        }
        $db->commit(); flash('success','Matrícula salva.');
    } catch (InvalidArgumentException $e) {
        if ($db->inTransaction()) $db->rollBack(); flash('error',$e->getMessage());
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack(); flash('error','Não foi possível salvar. Verifique se o aluno já tem matrícula nesta turma.');
    }
    go('matriculas.php');
}
if (isset($_GET['editar'])) { $q=$db->prepare('SELECT m.* FROM TB_MATRICULA m JOIN TB_ALUNO a ON a.COD_ALUNO=m.COD_ALUNO WHERE m.COD_MATRICULA=? AND a.COD_ESCOLINHA=?'); $q->execute([(int)$_GET['editar'],$escolinha]); $edit=$q->fetch(); if (!$edit) { flash('error','Matrícula não encontrada.'); go('matriculas.php'); } }
$q=$db->prepare("SELECT COD_ALUNO,NOME FROM TB_ALUNO WHERE COD_ESCOLINHA=? AND STATUS='ATIVO' ORDER BY NOME");$q->execute([$escolinha]);$alunos=$q->fetchAll();$q=$db->prepare('SELECT COD_TURMA,NOME FROM TB_TURMA WHERE COD_ESCOLINHA=? AND ATIVA=1 ORDER BY NOME');$q->execute([$escolinha]);$turmas=$q->fetchAll();$q=$db->prepare('SELECT m.*,a.NOME AS ALUNO,t.NOME AS TURMA FROM TB_MATRICULA m JOIN TB_ALUNO a ON a.COD_ALUNO=m.COD_ALUNO JOIN TB_TURMA t ON t.COD_TURMA=m.COD_TURMA WHERE a.COD_ESCOLINHA=? ORDER BY m.STATUS,m.DATA_MATRICULA DESC');$q->execute([$escolinha]);$lista=$q->fetchAll();
pageStart('Matrículas');
?>
<section><h2><?= $edit?'Editar matrícula':'Nova matrícula' ?></h2><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="<?= $edit?'update':'create' ?>"><?php if($edit):?><input type="hidden" name="id" value="<?= (int)$edit['COD_MATRICULA'] ?>"><?php endif;?><label>Aluno<select required name="cod_aluno"><option value="">Selecione</option><?php foreach($alunos as $a):?><option value="<?= (int)$a['COD_ALUNO'] ?>" <?=($edit['COD_ALUNO']??'')==$a['COD_ALUNO']?'selected':''?>><?=e($a['NOME'])?></option><?php endforeach;?></select></label><label>Turma<select required name="cod_turma"><option value="">Selecione</option><?php foreach($turmas as $t):?><option value="<?= (int)$t['COD_TURMA'] ?>" <?=($edit['COD_TURMA']??'')==$t['COD_TURMA']?'selected':''?>><?=e($t['NOME'])?></option><?php endforeach;?></select></label><label>Data<input required type="date" name="data_matricula" value="<?=e($edit['DATA_MATRICULA']??date('Y-m-d'))?>"></label><label>Situação<select name="status"><?php foreach(['ATIVA','ENCERRADA'] as $s):?><option <?=($edit['STATUS']??'ATIVA')===$s?'selected':''?>><?=$s?></option><?php endforeach;?></select></label><div><?php actionButton($edit?'Salvar alterações':'Matricular aluno');?><?php if($edit):?><a href="matriculas.php">Cancelar</a><?php endif;?></div></form></section>
<section><div><table><thead><tr><th>Aluno</th><th>Turma</th><th>Data</th><th>Situação</th><th>Ações</th></tr></thead><tbody><?php foreach($lista as $m):?><tr><td><?=e($m['ALUNO'])?></td><td><?=e($m['TURMA'])?></td><td><?=e(date('d/m/Y',strtotime($m['DATA_MATRICULA'])))?></td><td><?=e($m['STATUS'])?></td><td><button type="button" class="acao-tabela" data-modal-url="matriculas.php?mini=1&amp;editar=<?=(int)$m['COD_MATRICULA']?>" data-modal-titulo="Editar matrícula">Editar</button><form method="post" onsubmit="return confirm('Remover esta matrícula?');"><input type="hidden" name="csrf_token" value="<?=e(csrfToken())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=(int)$m['COD_MATRICULA']?>"><button>Excluir</button></form></td></tr><?php endforeach;?></tbody></table></div></section><?php pageEnd();?>
