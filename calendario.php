<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/avaliacao_helpers.php';
checkRole(['ADMIN', 'SECRETARIA', 'PROFESSOR', 'RESPONSAVEL', 'ALUNO']);

$db = getDB();
$escolinha = currentEscolinhaId();
$perfil = $_SESSION['user_role'];
$responsavelId = 0;
if ($perfil === 'RESPONSAVEL') {
    $consultaResponsavel = $db->prepare('SELECT COD_RESPONSAVEL FROM TB_RESPONSAVEL WHERE COD_USUARIO = ? AND COD_ESCOLINHA = ?');
    $consultaResponsavel->execute([(int) $_SESSION['user_id'], $escolinha]);
    $responsavelId = (int) $consultaResponsavel->fetchColumn();
} elseif ($perfil === 'ALUNO') {
    $consultaAluno = $db->prepare('SELECT COD_ALUNO FROM TB_ALUNO WHERE COD_USUARIO = ? AND COD_ESCOLINHA = ?');
    $consultaAluno->execute([(int) $_SESSION['user_id'], $escolinha]);
    $alunoId = (int) $consultaAluno->fetchColumn();
}
$mesInformado = (string) ($_GET['mes'] ?? date('Y-m'));
$mesValido = DateTime::createFromFormat('!Y-m', $mesInformado);
$mesAtual = $mesValido && $mesValido->format('Y-m') === $mesInformado ? $mesValido : new DateTime('first day of this month');
$inicioMes = (clone $mesAtual)->modify('first day of this month');
$fimMes = (clone $inicioMes)->modify('last day of this month');
$inicioGrade = (clone $inicioMes)->modify('monday this week');
$fimGrade = (clone $fimMes)->modify('sunday this week');
$mesAnterior = (clone $inicioMes)->modify('-1 month')->format('Y-m');
$mesProximo = (clone $inicioMes)->modify('+1 month')->format('Y-m');
$tipos = avaliacaoTiposTreino();
$filtroTipo = (string) ($_GET['tipo'] ?? '');
if (!isset($tipos[$filtroTipo])) $filtroTipo = '';

if ($perfil === 'RESPONSAVEL' || $perfil === 'ALUNO') {
    $consulta = $db->prepare(
        "SELECT DISTINCT t.COD_TURMA, t.NOME
         FROM TB_TURMA t
         JOIN TB_MATRICULA m ON m.COD_TURMA = t.COD_TURMA AND m.STATUS = 'ATIVA'
         JOIN TB_ALUNO a ON a.COD_ALUNO = m.COD_ALUNO AND a.STATUS = 'ATIVO'
         WHERE t.COD_ESCOLINHA = ? AND t.ATIVA = 1 AND " . ($perfil === 'ALUNO' ? 'a.COD_ALUNO = ?' : 'a.COD_RESPONSAVEL = ?') . "
         ORDER BY t.NOME"
    );
    $consulta->execute([$escolinha, $perfil === 'ALUNO' ? $alunoId : $responsavelId]);
} else {
    $sqlTurmas = 'SELECT t.COD_TURMA, t.NOME FROM TB_TURMA t WHERE t.COD_ESCOLINHA = ? AND t.ATIVA = 1';
    $parametrosTurmas = [$escolinha];
    if ($perfil === 'PROFESSOR') {
        $sqlTurmas .= ' AND t.COD_PROFESSOR = ?';
        $parametrosTurmas[] = (int) $_SESSION['user_id'];
    }
    $consulta = $db->prepare($sqlTurmas . ' ORDER BY t.NOME');
    $consulta->execute($parametrosTurmas);
}
$turmas = $consulta->fetchAll();
$idsTurmas = array_map(static function ($turma) { return (int) $turma['COD_TURMA']; }, $turmas);
$filtroTurma = (int) ($_GET['turma'] ?? 0);
if ($filtroTurma && !in_array($filtroTurma, $idsTurmas, true)) $filtroTurma = 0;

$sqlEventos =
    'SELECT a.COD_AULA, a.DATA_AULA, TIME_FORMAT(a.HORARIO, "%H:%i") AS HORARIO,
            a.TEMA_TREINO, a.TIPO_TREINO, a.OBJETIVO, a.EXERCICIOS, a.OBSERVACAO,
            t.COD_TURMA, t.NOME AS TURMA, t.FAIXA_ETARIA, u.NOME AS PROFESSOR
     FROM TB_AULA a
     JOIN TB_TURMA t ON t.COD_TURMA = a.COD_TURMA
     LEFT JOIN TB_USUARIO u ON u.COD_USUARIO = t.COD_PROFESSOR
     WHERE t.COD_ESCOLINHA = ? AND t.ATIVA = 1 AND a.DATA_AULA BETWEEN ? AND ?';
$parametrosEventos = [$escolinha, $inicioGrade->format('Y-m-d'), $fimGrade->format('Y-m-d')];
if ($perfil === 'PROFESSOR') {
    $sqlEventos .= ' AND t.COD_PROFESSOR = ?';
    $parametrosEventos[] = (int) $_SESSION['user_id'];
} elseif ($perfil === 'RESPONSAVEL') {
    $sqlEventos .= " AND EXISTS (
        SELECT 1 FROM TB_MATRICULA m
        JOIN TB_ALUNO al ON al.COD_ALUNO = m.COD_ALUNO AND al.STATUS = 'ATIVO'
        WHERE m.COD_TURMA = t.COD_TURMA AND m.STATUS = 'ATIVA'
          AND al.COD_RESPONSAVEL = ? AND al.COD_ESCOLINHA = t.COD_ESCOLINHA
    )";
    $parametrosEventos[] = $responsavelId;
} elseif ($perfil === 'ALUNO') {
    $sqlEventos .= " AND EXISTS (SELECT 1 FROM TB_MATRICULA m JOIN TB_ALUNO al ON al.COD_ALUNO = m.COD_ALUNO WHERE m.COD_TURMA = t.COD_TURMA AND m.STATUS = 'ATIVA' AND al.COD_ALUNO = ? AND al.COD_ESCOLINHA = t.COD_ESCOLINHA)";
    $parametrosEventos[] = $alunoId;
}
if ($filtroTurma) {
    $sqlEventos .= ' AND t.COD_TURMA = ?';
    $parametrosEventos[] = $filtroTurma;
}
if ($filtroTipo) {
    $sqlEventos .= ' AND a.TIPO_TREINO = ?';
    $parametrosEventos[] = $filtroTipo;
}
$sqlEventos .= ' ORDER BY a.DATA_AULA, a.HORARIO, a.COD_AULA';
$consulta = $db->prepare($sqlEventos);
$consulta->execute($parametrosEventos);
$eventos = $consulta->fetchAll();
$eventosPorDia = [];
foreach ($eventos as $evento) {
    $eventosPorDia[$evento['DATA_AULA']][] = $evento;
}
$dias = [];
$cursor = clone $inicioGrade;
while ($cursor <= $fimGrade) {
    $data = $cursor->format('Y-m-d');
    $dias[] = ['data' => clone $cursor, 'eventos' => $eventosPorDia[$data] ?? []];
    $cursor = $cursor->modify('+1 day');
}
$nomesMeses = [1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto', 9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'];
$nomesDias = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];

pageStart('Calendário');
?>
<div class="calendario-page">
    <header class="calendario-hero"><div><p class="calendario-kicker"><span></span> AGENDA DA ESCOLINHA</p><h2>Treinos no calendário.</h2><p>Planejamento compartilhado com as pessoas relacionadas à turma, a partir de um único registro por treino.</p></div><span class="calendario-hero-icon" aria-hidden="true">▦</span></header>

    <section class="calendario-toolbar">
        <div class="calendario-navegacao-mes"><a href="calendario.php?mes=<?= e($mesAnterior) ?>&amp;tipo=<?= e($filtroTipo) ?>&amp;turma=<?= $filtroTurma ?>" aria-label="Mês anterior">‹</a><div><strong><?= e($nomesMeses[(int) $inicioMes->format('n')]) ?></strong><span><?= e($inicioMes->format('Y')) ?></span></div><a href="calendario.php?mes=<?= e($mesProximo) ?>&amp;tipo=<?= e($filtroTipo) ?>&amp;turma=<?= $filtroTurma ?>" aria-label="Próximo mês">›</a></div>
        <form method="get" class="calendario-filtros"><input type="month" name="mes" value="<?= e($inicioMes->format('Y-m')) ?>" aria-label="Mês"><label><span>Tipo de treino</span><select name="tipo"><option value="">Todos os tipos</option><?php foreach ($tipos as $codigo => $tipo): ?><option value="<?= e($codigo) ?>" <?= $filtroTipo === $codigo ? 'selected' : '' ?>><?= e($tipo['nome']) ?></option><?php endforeach; ?></select></label><label><span>Turma</span><select name="turma"><option value="0">Todas as turmas</option><?php foreach ($turmas as $turma): ?><option value="<?= (int) $turma['COD_TURMA'] ?>" <?= $filtroTurma === (int) $turma['COD_TURMA'] ? 'selected' : '' ?>><?= e($turma['NOME']) ?></option><?php endforeach; ?></select></label><button type="submit" class="calendario-aplicar-filtros">Filtrar</button></form>
    </section>

    <section class="calendario-legenda" aria-label="Legenda de tipos"><span><i class="tipo-finalizacao"></i>Finalização</span><span><i class="tipo-passe"></i>Passe</span><span><i class="tipo-finalizacao_passe"></i>Finalização + passe</span><span><i class="tipo-drible"></i>Drible</span><span><i class="tipo-velocidade"></i>Velocidade</span><span><i class="tipo-fisico"></i>Físico</span><span><i class="tipo-posicionamento"></i>Posicionamento</span><span><i class="tipo-jogo_treino"></i>Jogo-treino</span><span><i class="tipo-misto"></i>Treino misto</span><span><i class="tipo-personalizado"></i>Personalizado</span></section>

    <section class="calendario-mes-card" aria-label="Calendário mensal">
        <div class="calendario-dias-semana"><?php foreach ($nomesDias as $nomeDia): ?><span><?= e($nomeDia) ?></span><?php endforeach; ?></div>
        <div class="calendario-grade">
            <?php foreach ($dias as $dia):
                $dataDia = $dia['data'];
                $dentroDoMes = $dataDia->format('Y-m') === $inicioMes->format('Y-m');
                $hoje = $dataDia->format('Y-m-d') === date('Y-m-d');
                $diaClasses = 'calendario-dia' . ($dentroDoMes ? '' : ' fora-mes') . ($hoje ? ' hoje' : '') . ($dia['eventos'] ? ' tem-eventos' : '');
            ?>
                <article class="<?= e($diaClasses) ?>"><header><span class="calendario-dia-nome"><?= e($nomesDias[(int) $dataDia->format('N') - 1]) ?></span><strong><?= e($dataDia->format('j')) ?></strong><?php if ($dia['eventos']): ?><small><?= count($dia['eventos']) ?> <?= count($dia['eventos']) === 1 ? 'treino' : 'treinos' ?></small><?php endif; ?></header><div class="calendario-eventos-dia">
                    <?php foreach ($dia['eventos'] as $evento):
                        $tipoCodigo = (string) ($evento['TIPO_TREINO'] ?? '');
                        $mapaTipos = ['FINALIZACAO' => 'finalizacao', 'PASSE' => 'passe', 'FINALIZACAO_PASSE' => 'finalizacao_passe', 'DRIBLE' => 'drible', 'VELOCIDADE' => 'velocidade', 'FISICO' => 'fisico', 'POSICIONAMENTO' => 'posicionamento', 'JOGO_TREINO' => 'jogo_treino', 'TREINO_MISTO' => 'misto', 'TECNICO_GERAL' => 'misto', 'PERSONALIZADO' => 'personalizado'];
                        $classeTipo = $mapaTipos[$tipoCodigo] ?? 'outro';
                        $tipoNome = $tipos[$evento['TIPO_TREINO']]['nome'] ?? 'Tipo não definido';
                    ?>
                        <button type="button" class="calendario-evento tipo-<?= e($classeTipo) ?>" data-evento-titulo="<?= e($evento['TEMA_TREINO']) ?>" data-evento-tipo="<?= e($tipoNome) ?>" data-evento-data="<?= e(date('d/m/Y', strtotime($evento['DATA_AULA']))) ?>" data-evento-horario="<?= e($evento['HORARIO'] ?: 'Horário não informado') ?>" data-evento-turma="<?= e($evento['TURMA']) ?>" data-evento-categoria="<?= e($evento['FAIXA_ETARIA'] ?? '') ?>" data-evento-professor="<?= e($evento['PROFESSOR'] ?: 'Professor não informado') ?>" data-evento-objetivo="<?= e($evento['OBJETIVO'] ?: 'Objetivo não informado') ?>" data-evento-exercicios="<?= e($evento['EXERCICIOS'] ?: 'Exercícios não informados') ?>" data-evento-observacao="<?= e($evento['OBSERVACAO'] ?: 'Sem observações') ?>"><span class="calendario-evento-hora"><?= e($evento['HORARIO'] ?: 'Treino') ?></span><strong><?= e($evento['TEMA_TREINO']) ?></strong><small><?= e($evento['TURMA']) ?></small></button>
                    <?php endforeach; ?>
                </div></article>
            <?php endforeach; ?>
        </div>
        <?php if (!$eventos): ?><p class="calendario-sem-eventos">Nenhum treino encontrado para este mês e filtros.</p><?php endif; ?>
    </section>

    <dialog class="calendario-dialog" id="detalhesTreino" aria-labelledby="detalheTitulo"><div class="calendario-dialog-inner"><button class="calendario-dialog-fechar" id="fecharDetalheTreino" type="button" aria-label="Fechar detalhes">×</button><p class="calendario-kicker"><span></span><span id="detalheTipo"></span></p><h2 id="detalheTitulo"></h2><div class="calendario-dialog-meta"><span id="detalheData"></span><span id="detalheHorario"></span><span id="detalheTurma"></span><span id="detalheCategoria"></span><span id="detalheProfessor"></span></div><section><h3>Objetivo</h3><p id="detalheObjetivo"></p></section><section><h3>Exercícios e aquecimento</h3><p id="detalheExercicios"></p></section><section><h3>Observações</h3><p id="detalheObservacao"></p></section></div></dialog>
</div>
<script>
(function () {
    'use strict';
    const dialog = document.getElementById('detalhesTreino');
    if (!dialog) return;
    const ids = {titulo: 'detalheTitulo', tipo: 'detalheTipo', data: 'detalheData', horario: 'detalheHorario', turma: 'detalheTurma', categoria: 'detalheCategoria', professor: 'detalheProfessor', objetivo: 'detalheObjetivo', exercicios: 'detalheExercicios', observacao: 'detalheObservacao'};
    document.querySelectorAll('[data-evento-titulo]').forEach(function (botao) {
        botao.addEventListener('click', function () {
            Object.keys(ids).forEach(function (chave) {
                const valor = botao.dataset['evento' + chave.charAt(0).toUpperCase() + chave.slice(1)] || '';
                document.getElementById(ids[chave]).textContent = valor;
            });
            document.getElementById('detalheCategoria').hidden = !botao.dataset.eventoCategoria;
            dialog.showModal();
        });
    });
    document.getElementById('fecharDetalheTreino').addEventListener('click', function () { dialog.close(); });
    dialog.addEventListener('click', function (evento) { if (evento.target === dialog) dialog.close(); });
}());
</script>
<?php pageEnd(); ?>
