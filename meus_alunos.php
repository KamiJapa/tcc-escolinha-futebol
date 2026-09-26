<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/config/database.php';
checkRole(['RESPONSAVEL']);

$db = getDB();
$escolinha = currentEscolinhaId();
$consulta = $db->prepare('SELECT COD_RESPONSAVEL FROM TB_RESPONSAVEL WHERE COD_USUARIO = ? AND COD_ESCOLINHA = ?');
$consulta->execute([$_SESSION['user_id'], $escolinha]);
$responsavel = (int) $consulta->fetchColumn();
$atletas = [];

if ($responsavel) {
    $consulta = $db->prepare(
        "SELECT a.*,
                GROUP_CONCAT(DISTINCT t.NOME ORDER BY t.NOME SEPARATOR ', ') AS TURMAS,
                GROUP_CONCAT(DISTINCT t.FAIXA_ETARIA ORDER BY t.FAIXA_ETARIA SEPARATOR ', ') AS CATEGORIAS,
                COUNT(DISTINCT t.COD_TURMA) AS TOTAL_TURMAS
         FROM TB_ALUNO a
         LEFT JOIN TB_MATRICULA m ON m.COD_ALUNO = a.COD_ALUNO AND m.STATUS = 'ATIVA'
         LEFT JOIN TB_TURMA t ON t.COD_TURMA = m.COD_TURMA AND t.ATIVA = 1
         WHERE a.COD_RESPONSAVEL = ? AND a.COD_ESCOLINHA = ?
         GROUP BY a.COD_ALUNO
         ORDER BY a.NOME"
    );
    $consulta->execute([$responsavel, $escolinha]);
    $atletas = $consulta->fetchAll();
}

function carreiraDiasTreino($dias) {
    $mapa = [
        'domingo' => 0, 'segunda' => 1, 'terça' => 2, 'terca' => 2,
        'quarta' => 3, 'quinta' => 4, 'sexta' => 5, 'sábado' => 6, 'sabado' => 6
    ];
    preg_match_all('/domingo|segunda|terça|terca|quarta|quinta|sexta|sábado|sabado/iu', (string) $dias, $matches);
    $diasSemana = [];
    foreach ($matches[0] as $dia) {
        $chave = mb_strtolower($dia, 'UTF-8');
        if (isset($mapa[$chave])) {
            $diasSemana[] = $mapa[$chave];
        }
    }
    return array_values(array_unique($diasSemana));
}

function carreiraRotuloAtributo($atributo) {
    $rotulos = [
        'passe' => 'Passe', 'finalizacao' => 'Finalização', 'posicionamento' => 'Posicionamento',
        'fisico' => 'Físico', 'disciplina' => 'Disciplina', 'drible' => 'Drible',
        'defesa' => 'Defesa', 'velocidade' => 'Velocidade', 'tecnica' => 'Técnica'
    ];
    $chave = mb_strtolower((string) $atributo, 'UTF-8');
    return $rotulos[$chave] ?? mb_convert_case(str_replace(['_', '-'], ' ', $chave), MB_CASE_TITLE, 'UTF-8');
}

$nomesDias = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];
$agora = new DateTimeImmutable();
$horaAtual = $agora->format('H:i');

foreach ($atletas as &$atleta) {
    $idAtleta = (int) $atleta['COD_ALUNO'];

    $consulta = $db->prepare(
        'SELECT COUNT(*) AS CHAMADAS, COALESCE(SUM(PRESENTE = 1), 0) AS PRESENCAS,
                COALESCE(SUM(PRESENTE = 0), 0) AS AUSENCIAS
         FROM TB_PRESENCA WHERE COD_ALUNO = ?'
    );
    $consulta->execute([$idAtleta]);
    $atleta['FREQUENCIA_DADOS'] = $consulta->fetch();

    $consulta = $db->prepare(
        'SELECT av.COD_AVALIACAO, av.DATA_AVALIACAO, av.CRITERIOS_JSON,
                av.NOTA_GERAL, av.OBSERVACAO, u.NOME AS PROFESSOR
         FROM TB_AVALIACAO av
         LEFT JOIN TB_USUARIO u ON u.COD_USUARIO = av.COD_PROFESSOR
         WHERE av.COD_ALUNO = ?
         ORDER BY av.DATA_AVALIACAO DESC, av.COD_AVALIACAO DESC'
    );
    $consulta->execute([$idAtleta]);
    $atleta['AVALIACOES'] = $consulta->fetchAll();
    $atleta['AVALIACOES_COM_OVERALL'] = array_values(array_filter($atleta['AVALIACOES'], static function ($avaliacao) {
        return $avaliacao['NOTA_GERAL'] !== null;
    }));
    $atleta['AVALIACAO_OVERALL'] = $atleta['AVALIACOES_COM_OVERALL'][0] ?? null;
    $atleta['OVERALL'] = $atleta['AVALIACAO_OVERALL'] ? (float) $atleta['AVALIACAO_OVERALL']['NOTA_GERAL'] : null;
    $atleta['ATRIBUTOS'] = [];
    if ($atleta['AVALIACAO_OVERALL']) {
        $criterios = json_decode($atleta['AVALIACAO_OVERALL']['CRITERIOS_JSON'], true);
        $atributosCarreira = ['velocidade', 'finalizacao', 'passe', 'drible', 'fisico', 'posicionamento'];
        if (is_array($criterios)) {
            foreach ($criterios as $nome => $nota) {
                if (in_array(mb_strtolower((string) $nome, 'UTF-8'), $atributosCarreira, true) && is_numeric($nota)) {
                    $atleta['ATRIBUTOS'][] = ['nome' => carreiraRotuloAtributo($nome), 'nota' => min(10, max(0, (float) $nota))];
                }
            }
        }
    }

    $atleta['EVOLUCAO'] = null;
    if (count($atleta['AVALIACOES_COM_OVERALL']) > 1) {
        $maisAntiga = (float) $atleta['AVALIACOES_COM_OVERALL'][count($atleta['AVALIACOES_COM_OVERALL']) - 1]['NOTA_GERAL'];
        $atleta['EVOLUCAO'] = round($atleta['OVERALL'] - $maisAntiga, 1);
    }
    $atleta['AVALIACAO_OBSERVACAO'] = null;
    foreach ($atleta['AVALIACOES'] as $avaliacao) {
        if (!empty($avaliacao['OBSERVACAO'])) {
            $atleta['AVALIACAO_OBSERVACAO'] = $avaliacao;
            break;
        }
    }

    $consulta = $db->prepare(
        'SELECT au.DATA_AULA, au.TEMA_TREINO, au.OBSERVACAO AS OBSERVACAO_AULA,
                p.PRESENTE, p.JUSTIFICATIVA, t.NOME AS TURMA
         FROM TB_PRESENCA p
         JOIN TB_AULA au ON au.COD_AULA = p.COD_AULA
         JOIN TB_TURMA t ON t.COD_TURMA = au.COD_TURMA
         WHERE p.COD_ALUNO = ?
         ORDER BY au.DATA_AULA DESC, au.COD_AULA DESC'
    );
    $consulta->execute([$idAtleta]);
    $atleta['HISTORICO_TREINOS'] = $consulta->fetchAll();

    $historico = [];
    foreach ($atleta['AVALIACOES'] as $avaliacao) {
        $historico[] = [
            'data' => $avaliacao['DATA_AVALIACAO'], 'tipo' => 'avaliacao',
            'titulo' => $avaliacao['NOTA_GERAL'] === null ? 'Avaliação sem notas' : 'Avaliação técnica',
            'detalhe' => $avaliacao['PROFESSOR'] ?: 'Professor não identificado',
            'nota' => $avaliacao['NOTA_GERAL'] === null ? null : (float) $avaliacao['NOTA_GERAL'], 'observacao' => $avaliacao['OBSERVACAO']
        ];
    }
    foreach ($atleta['HISTORICO_TREINOS'] as $treino) {
        $historico[] = [
            'data' => $treino['DATA_AULA'], 'tipo' => 'treino',
            'titulo' => $treino['TEMA_TREINO'] ?: 'Treino', 'detalhe' => $treino['TURMA'],
            'presente' => (bool) $treino['PRESENTE'], 'observacao' => $treino['PRESENTE'] ? $treino['OBSERVACAO_AULA'] : $treino['JUSTIFICATIVA']
        ];
    }
    usort($historico, static function ($a, $b) {
        return [$b['data'], $b['tipo']] <=> [$a['data'], $a['tipo']];
    });
    $atleta['HISTORICO'] = $historico;

    $consulta = $db->prepare(
        "SELECT t.COD_TURMA, t.NOME AS TURMA, t.FAIXA_ETARIA, t.DIAS_TREINO, t.HORARIO
         FROM TB_MATRICULA m
         JOIN TB_TURMA t ON t.COD_TURMA = m.COD_TURMA
         WHERE m.COD_ALUNO = ? AND m.STATUS = 'ATIVA' AND t.ATIVA = 1"
    );
    $consulta->execute([$idAtleta]);
    $turmasAtleta = $consulta->fetchAll();
    $treinoSeguinte = null;
    foreach ($turmasAtleta as $turma) {
        preg_match('/\b([01]?\d|2[0-3]):[0-5]\d\b/u', (string) $turma['HORARIO'], $horas);
        $hora = $horas[0] ?? '';
        foreach (carreiraDiasTreino($turma['DIAS_TREINO']) as $diaSemana) {
            $deslocamento = ($diaSemana - (int) $agora->format('w') + 7) % 7;
            if ($deslocamento === 0 && $hora !== '' && $hora <= $horaAtual) {
                continue;
            }
            $data = $agora->modify('+' . $deslocamento . ' days')->format('Y-m-d');
            $candidato = [
                'data' => $data, 'hora' => $hora, 'cod_turma' => (int) $turma['COD_TURMA'], 'turma' => $turma['TURMA'],
                'categoria' => $turma['FAIXA_ETARIA'], 'tema' => null, 'registrado' => false
            ];
            if ($treinoSeguinte === null || [$candidato['data'], $candidato['hora']] < [$treinoSeguinte['data'], $treinoSeguinte['hora']]) {
                $treinoSeguinte = $candidato;
            }
        }
    }
    if ($treinoSeguinte) {
        $consulta = $db->prepare(
            'SELECT TEMA_TREINO FROM TB_AULA WHERE COD_TURMA = ? AND DATA_AULA = ? LIMIT 1'
        );
        $consulta->execute([$treinoSeguinte['cod_turma'], $treinoSeguinte['data']]);
        $temaTreino = $consulta->fetchColumn();
        if ($temaTreino !== false) {
            $treinoSeguinte['tema'] = $temaTreino;
            $treinoSeguinte['registrado'] = true;
        }
    }
    $atleta['PROXIMO_TREINO'] = $treinoSeguinte;
}
unset($atleta);

pageStart('Meu Perfil');
?>
<div class="career-page">
    <header class="career-heading">
        <div>
            <p class="career-kicker"><span></span> CENTRAL DO ATLETA</p>
            <h2>Uma jornada construída treino a treino.</h2>
            <p>Acompanhe a trajetória, o desempenho e os próximos passos de cada atleta.</p>
        </div>
        <div class="career-heading-badge"><span aria-hidden="true">✦</span> Perfil de desenvolvimento</div>
    </header>

    <?php if (!$responsavel): ?>
        <section class="career-empty"><span class="career-empty-icon" aria-hidden="true">◎</span><div><h2>Vínculo de responsável pendente</h2><p>Solicite à secretaria a associação do seu usuário ao cadastro de responsável para acessar a carreira dos atletas.</p></div></section>
    <?php elseif (!$atletas): ?>
        <section class="career-empty"><span class="career-empty-icon" aria-hidden="true">⚽</span><div><h2>Nenhum atleta vinculado</h2><p>Quando um atleta for associado ao seu cadastro pela secretaria, seu perfil aparecerá aqui.</p></div></section>
    <?php else: ?>
        <div class="career-list">
            <?php foreach ($atletas as $atleta): ?>
                <?php
                    $iniciais = mb_strtoupper(mb_substr($atleta['NOME'], 0, 1, 'UTF-8'), 'UTF-8');
                    $presenca = $atleta['FREQUENCIA_DADOS'];
                    $percentualPresenca = (int) $presenca['CHAMADAS'] > 0 ? round(100 * (int) $presenca['PRESENCAS'] / (int) $presenca['CHAMADAS']) : null;
                    $overallPercentual = $atleta['OVERALL'] === null ? 0 : min(100, max(0, $atleta['OVERALL'] * 10));
                ?>
                <article class="career-athlete">
                    <section class="career-hero">
                        <div class="career-identity">
                            <div class="career-avatar" aria-label="Avatar de <?= e($atleta['NOME']) ?>"><span><?= e($iniciais) ?></span><i aria-hidden="true"></i></div>
                            <div class="career-name-block">
                                <div class="career-badges"><span class="career-status <?= strtolower(e($atleta['STATUS'])) ?>"><?= e($atleta['STATUS']) ?></span><span class="career-season">CARREIRA NA ESCOLINHA</span></div>
                                <h2><?= e($atleta['NOME']) ?></h2>
                                <div class="career-meta"><span><?= e($atleta['POSICAO'] ?: 'Posição não informada') ?></span><span><?= $atleta['NUMERO_CAMISA'] === null ? 'Camisa não definida' : 'Camisa #' . (int) $atleta['NUMERO_CAMISA'] ?></span><span><?= e($atleta['CATEGORIAS'] ?: 'Categoria não definida') ?></span></div>
                            </div>
                        </div>
                        <div class="career-overall <?= $atleta['OVERALL'] === null ? 'indisponivel' : '' ?>">
                            <span>OVERALL</span>
                            <?php if ($atleta['OVERALL'] !== null): ?><strong><?= e(number_format($atleta['OVERALL'], 1, ',', '.')) ?><small>/10</small></strong><div class="career-overall-track"><i style="width: <?= e($overallPercentual) ?>%"></i></div><?php else: ?><strong>—</strong><small>Aguardando avaliação</small><?php endif; ?>
                        </div>
                    </section>

                    <div class="career-content">
                        <section class="career-card career-evolution">
                            <div class="career-section-title"><div><span class="career-section-icon blue" aria-hidden="true">↗</span><div><p>DESENVOLVIMENTO</p><h3>Evolução do Overall</h3></div></div><span class="career-context">Avaliações registradas</span></div>
                            <?php if ($atleta['AVALIACAO_OVERALL']): ?>
                                <div class="career-evolution-main"><strong><?= e(number_format($atleta['OVERALL'], 1, ',', '.')) ?></strong><span>última nota calculada<br><?= e(date('d/m/Y', strtotime($atleta['AVALIACAO_OVERALL']['DATA_AVALIACAO']))) ?></span><?php if ($atleta['EVOLUCAO'] !== null): ?><b class="<?= $atleta['EVOLUCAO'] >= 0 ? 'positive' : 'negative' ?>"><?= $atleta['EVOLUCAO'] > 0 ? '+' : '' ?><?= e(number_format($atleta['EVOLUCAO'], 1, ',', '.')) ?> <small>desde a 1ª</small></b><?php endif; ?></div>
                                <div class="career-timeline-chart" role="img" aria-label="<?= count($atleta['AVALIACOES_COM_OVERALL']) ?> avaliações com Overall calculado"><div class="career-chart-line"></div><div class="career-chart-points"><?php foreach (array_reverse(array_slice($atleta['AVALIACOES_COM_OVERALL'], 0, 6)) as $indice => $avaliacao): $altura = max(10, min(100, (float) $avaliacao['NOTA_GERAL'] * 10)); ?><div class="career-chart-point"><span style="bottom: <?= e($altura) ?>%" title="Nota <?= e(number_format((float) $avaliacao['NOTA_GERAL'], 1, ',', '.')) ?>"></span><small><?= e(date('d/m', strtotime($avaliacao['DATA_AVALIACAO']))) ?></small></div><?php endforeach; ?></div></div>
                            <?php else: ?><div class="career-card-empty"><span>↗</span><p>A evolução começa após a primeira avaliação técnica.</p></div><?php endif; ?>
                        </section>

                        <section class="career-card career-attributes">
                            <div class="career-section-title"><div><span class="career-section-icon green" aria-hidden="true">◈</span><div><p>LEITURA DE JOGO</p><h3>Atributos</h3></div></div><?php if ($atleta['AVALIACAO_OVERALL']): ?><span class="career-context">Último Overall</span><?php endif; ?></div>
                            <?php if ($atleta['ATRIBUTOS']): ?><div class="career-attribute-list"><?php foreach ($atleta['ATRIBUTOS'] as $atributo): ?><div class="career-attribute"><div><span><?= e($atributo['nome']) ?></span><strong><?= e(number_format($atributo['nota'], 1, ',', '.')) ?></strong></div><div class="career-attribute-track"><i style="width: <?= e($atributo['nota'] * 10) ?>%"></i></div></div><?php endforeach; ?></div><?php else: ?><div class="career-card-empty"><span>◈</span><p>Os atributos aparecem após uma avaliação técnica.</p></div><?php endif; ?>
                        </section>

                        <section class="career-card career-stats">
                            <div class="career-section-title"><div><span class="career-section-icon green" aria-hidden="true">▦</span><div><p>NÚMEROS DA TRAJETÓRIA</p><h3>Estatísticas</h3></div></div></div>
                            <div class="career-stat-grid">
                                <div class="career-stat"><span class="career-stat-icon">◷</span><strong><?= (int) $presenca['PRESENCAS'] ?></strong><small>Presenças</small></div>
                                <div class="career-stat"><span class="career-stat-icon blue">%</span><strong><?= $percentualPresenca === null ? '—' : e($percentualPresenca . '%') ?></strong><small>Frequência</small></div>
                                <div class="career-stat"><span class="career-stat-icon gold">⚽</span><strong class="career-unavailable">Sem registro</strong><small>Gols</small></div>
                                <div class="career-stat"><span class="career-stat-icon violet">↗</span><strong class="career-unavailable">Sem registro</strong><small>Assistências</small></div>
                            </div>
                            <p class="career-data-note">Gols e assistências ainda não são registrados pelo sistema.</p>
                        </section>

                        <section class="career-card career-next-training">
                            <div class="career-section-title"><div><span class="career-section-icon blue" aria-hidden="true">⚽</span><div><p>PRÓXIMO PASSO</p><h3>Próximo treino</h3></div></div></div>
                            <?php if ($atleta['PROXIMO_TREINO']): $proximo = $atleta['PROXIMO_TREINO']; ?>
                                <div class="career-next-event"><div class="career-event-date"><strong><?= e(date('d', strtotime($proximo['data']))) ?></strong><span><?= e(mb_strtoupper(mb_substr($nomesDias[(int) date('w', strtotime($proximo['data']))], 0, 3, 'UTF-8'), 'UTF-8')) ?></span></div><div><strong><?= e($proximo['turma']) ?></strong><span><?= e($proximo['tema'] ?: 'Treino regular') ?></span><small><?= e(date('d/m', strtotime($proximo['data']))) ?><?php if ($proximo['hora']): ?> · <?= e($proximo['hora']) ?> · <?= e($proximo['categoria']) ?><?php else: ?> · <?= e($proximo['categoria']) ?><?php endif; ?></small></div><span class="career-event-badge">AGENDADO</span></div>
                            <?php else: ?><div class="career-card-empty compact"><span>◷</span><p>Não há próximo treino cadastrado para as turmas ativas.</p></div><?php endif; ?>
                        </section>

                        <section class="career-card career-goals">
                            <div class="career-section-title"><div><span class="career-section-icon gold" aria-hidden="true">◎</span><div><p>FOCO E CONSTÂNCIA</p><h3>Metas</h3></div></div></div>
                            <div class="career-card-empty compact"><span>◎</span><p>O sistema ainda não possui metas cadastradas para acompanhar.</p></div>
                        </section>

                        <section class="career-card career-observations">
                            <div class="career-section-title"><div><span class="career-section-icon violet" aria-hidden="true">✎</span><div><p>CUIDADO E ACOMPANHAMENTO</p><h3>Observações</h3></div></div></div>
                            <?php if ($atleta['OBSERVACOES_MEDICAS']): ?><div class="career-observation"><span>OBSERVAÇÕES MÉDICAS</span><p><?= nl2br(e($atleta['OBSERVACOES_MEDICAS'])) ?></p></div><?php else: ?><div class="career-card-empty compact"><span>✎</span><p>Nenhuma observação registrada para este atleta.</p></div><?php endif; ?>
                            <?php if ($atleta['AVALIACAO_OBSERVACAO']): ?><div class="career-observation evaluation"><span>NOTA DA ÚLTIMA AVALIAÇÃO</span><p><?= nl2br(e($atleta['AVALIACAO_OBSERVACAO']['OBSERVACAO'])) ?></p><small><?= e($atleta['AVALIACAO_OBSERVACAO']['PROFESSOR'] ?: 'Professor não identificado') ?></small></div><?php endif; ?>
                        </section>

                        <section class="career-card career-history">
                            <div class="career-section-title"><div><span class="career-section-icon blue" aria-hidden="true">⌁</span><div><p>JORNADA DO ATLETA</p><h3>Histórico</h3></div></div><span class="career-context"><?= count($atleta['HISTORICO']) ?> registros</span></div>
                            <?php if ($atleta['HISTORICO']): ?><ol class="career-history-list"><?php foreach ($atleta['HISTORICO'] as $item): ?><li class="career-history-item"><span class="career-history-mark <?= e($item['tipo']) ?>" aria-hidden="true"><?= $item['tipo'] === 'avaliacao' ? '↗' : ($item['presente'] ? '✓' : '–') ?></span><div class="career-history-date"><?= e(date('d/m/Y', strtotime($item['data']))) ?></div><div class="career-history-content"><div><strong><?= e($item['titulo']) ?></strong><span><?= e($item['detalhe']) ?></span></div><?php if ($item['tipo'] === 'avaliacao' && $item['nota'] !== null): ?><b class="career-history-score"><?= e(number_format($item['nota'], 1, ',', '.')) ?></b><?php elseif ($item['tipo'] === 'treino'): ?><span class="career-history-status <?= $item['presente'] ? 'presente' : 'ausente' ?>"><?= $item['presente'] ? 'Presente' : 'Ausente' ?></span><?php endif; ?><?php if ($item['observacao']): ?><p><?= e($item['observacao']) ?></p><?php endif; ?></div></li><?php endforeach; ?></ol><?php else: ?><div class="career-card-empty compact"><span>⌁</span><p>O histórico será preenchido conforme treinos e avaliações forem registrados.</p></div><?php endif; ?>
                        </section>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php pageEnd(); ?>
