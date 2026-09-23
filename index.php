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
<p class="dashboard-intro mb-6 text-slate-600">Indicadores atualizados com os dados cadastrados na escolinha.</p>
<div class="resumo-grid grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
    <section class="cartao-indicador rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <span class="icone-indicador alunos" aria-hidden="true">👥</span>
        <h2>Alunos ativos</h2>
        <p><?= $totalAlunos ?></p>
    </section>
    <section class="cartao-indicador rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <span class="icone-indicador turmas" aria-hidden="true">⚽</span>
        <h2>Turmas ativas</h2>
        <p><?= $totalTurmas ?></p>
    </section>
    <section class="cartao-indicador rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <span class="icone-indicador frequencia" aria-hidden="true">✓</span>
        <h2>Frequência média</h2>
        <p><?= e($frequencia) ?>%</p>
    </section>
</div>
<section class="dashboard-nota mt-6 rounded-2xl border border-emerald-100 bg-emerald-50 p-6">
    <h2>Resumo da gestão</h2>
    <p>Os indicadores ajudam a acompanhar alunos, turmas e frequência. Eles são calculados a partir dos registros do sistema.</p>
</section>
<?php pageEnd(); ?>
