<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/config/database.php';

$db = getDB();
$escolinha = currentEscolinhaId();

$consulta = $db->prepare("SELECT COUNT(*) FROM TB_ALUNO WHERE COD_ESCOLINHA = ? AND STATUS = 'ATIVO'");
$consulta->execute([$escolinha]);
$totalAlunos = (int) $consulta->fetchColumn();

$consulta = $db->prepare('SELECT COUNT(*) FROM TB_TURMA WHERE COD_ESCOLINHA = ? AND ATIVA = 1');
$consulta->execute([$escolinha]);
$totalTurmas = (int) $consulta->fetchColumn();

$consulta = $db->prepare(
    'SELECT AVG(p.PRESENTE) * 100
     FROM TB_PRESENCA p
     JOIN TB_ALUNO a ON a.COD_ALUNO = p.COD_ALUNO
     WHERE a.COD_ESCOLINHA = ?'
);
$consulta->execute([$escolinha]);
$frequencia = $consulta->fetchColumn();
$frequencia = $frequencia === null ? 0 : round((float) $frequencia, 1);

pageStart('Resumo da escolinha');
?>
<section>
    <h2>Alunos ativos</h2>
    <p><?= $totalAlunos ?></p>
</section>
<section>
    <h2>Turmas ativas</h2>
    <p><?= $totalTurmas ?></p>
</section>
<section>
    <h2>Frequência média</h2>
    <p><?= e($frequencia) ?>%</p>
</section>
<section>
    <h2>Sobre este resumo</h2>
    <p>Os números são calculados a partir dos alunos, das turmas e das presenças cadastrados no sistema.</p>
</section>
<?php pageEnd(); ?>
