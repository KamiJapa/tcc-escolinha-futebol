<?php
/** A lista autorizada é sempre derivada da conta e da escolinha da sessão. */
function responsavelFilhos($db, $usuario, $escolinha) {
    $q = $db->prepare('SELECT a.* FROM TB_ALUNO a JOIN TB_RESPONSAVEL r ON r.COD_RESPONSAVEL = a.COD_RESPONSAVEL AND r.COD_ESCOLINHA = a.COD_ESCOLINHA JOIN TB_USUARIO u ON u.COD_USUARIO = r.COD_USUARIO AND u.COD_ESCOLINHA = r.COD_ESCOLINHA AND u.ATIVO = 1 AND u.PERFIL = \'RESPONSAVEL\' WHERE r.COD_USUARIO = ? AND a.COD_ESCOLINHA = ? ORDER BY a.NOME, a.COD_ALUNO');
    $q->execute([$usuario, $escolinha]);
    return $q->fetchAll();
}

/** Recebe somente um filho previamente autorizado por responsavelFilhos. */
function responsavelAcompanhamento($db, $filho) {
    $id = (int) $filho['COD_ALUNO'];
    $escola = (int) $filho['COD_ESCOLINHA'];
    $buscar = static function ($sql, $args) use ($db) {
        $q = $db->prepare($sql);
        $q->execute($args);
        return $q->fetchAll();
    };
    $turmas = $buscar("SELECT t.* FROM TB_MATRICULA m JOIN TB_TURMA t ON t.COD_TURMA = m.COD_TURMA WHERE m.COD_ALUNO = ? AND t.COD_ESCOLINHA = ? AND m.STATUS = 'ATIVA' AND t.ATIVA = 1 ORDER BY t.NOME", [$id, $escola]);
    $treinos = $buscar("SELECT au.*, t.NOME AS TURMA FROM TB_AULA au JOIN TB_TURMA t ON t.COD_TURMA = au.COD_TURMA WHERE t.COD_ESCOLINHA = ? AND t.ATIVA = 1 AND EXISTS (SELECT 1 FROM TB_MATRICULA m WHERE m.COD_TURMA = t.COD_TURMA AND m.COD_ALUNO = ? AND m.STATUS = 'ATIVA') AND (au.DATA_AULA > CURDATE() OR (au.DATA_AULA = CURDATE() AND (au.HORARIO IS NULL OR au.HORARIO >= CURTIME()))) ORDER BY au.DATA_AULA, au.HORARIO LIMIT 5", [$escola, $id]);
    $presencas = $buscar('SELECT p.*, au.DATA_AULA, t.NOME AS TURMA FROM TB_PRESENCA p JOIN TB_AULA au ON au.COD_AULA = p.COD_AULA JOIN TB_TURMA t ON t.COD_TURMA = au.COD_TURMA WHERE p.COD_ALUNO = ? AND t.COD_ESCOLINHA = ? AND au.DATA_AULA <= CURDATE() ORDER BY au.DATA_AULA DESC, p.COD_PRESENCA DESC', [$id, $escola]);
    $avaliacoes = $buscar('SELECT av.*, u.NOME AS PROFESSOR FROM TB_AVALIACAO av JOIN TB_USUARIO u ON u.COD_USUARIO = av.COD_PROFESSOR AND u.COD_ESCOLINHA = ? LEFT JOIN TB_AULA au ON au.COD_AULA = av.COD_AULA LEFT JOIN TB_TURMA t ON t.COD_TURMA = au.COD_TURMA WHERE av.COD_ALUNO = ? AND (av.COD_AULA IS NULL OR t.COD_ESCOLINHA = ?) AND av.DATA_AVALIACAO <= CURDATE() ORDER BY av.DATA_AVALIACAO DESC, av.COD_AVALIACAO DESC', [$escola, $id, $escola]);
    $jogos = $buscar("SELECT j.*, t.NOME AS TURMA, ja.PARTICIPOU, ja.GOLS, ja.ASSISTENCIAS FROM TB_JOGO j JOIN TB_TURMA t ON t.COD_TURMA = j.COD_TURMA LEFT JOIN TB_JOGO_ATLETA ja ON ja.COD_JOGO = j.COD_JOGO AND ja.COD_ALUNO = ? WHERE t.COD_ESCOLINHA = ? AND (ja.COD_ALUNO IS NOT NULL OR (j.DATA_JOGO >= CURDATE() AND t.ATIVA = 1 AND EXISTS (SELECT 1 FROM TB_MATRICULA m WHERE m.COD_TURMA = t.COD_TURMA AND m.COD_ALUNO = ? AND m.STATUS = 'ATIVA'))) ORDER BY j.DATA_JOGO DESC, j.COD_JOGO DESC", [$id, $escola, $id]);
    $metas = $buscar('SELECT m.* FROM TB_META_ATLETA m JOIN TB_USUARIO u ON u.COD_USUARIO = m.CRIADO_POR AND u.COD_ESCOLINHA = ? WHERE m.COD_ALUNO = ? ORDER BY m.CRIADO_EM DESC, m.COD_META DESC', [$escola, $id]);
    $overall = null;
    $notas = [];
    $atributos = [];
    foreach ($avaliacoes as $av) {
        if ($av['NOTA_GERAL'] !== null) {
            $notas[] = $av;
            if ($overall === null) $overall = (float) $av['NOTA_GERAL'];
        }
        $criterios = json_decode($av['CRITERIOS_JSON'], true);
        if (is_array($criterios)) foreach ($criterios as $nome => $nota) {
            if (isset(avaliacaoCatalogoAtributos()[$nome]) && is_numeric($nota)) $atributos[$nome][] = max(0, min(10, (float) $nota));
        }
    }
    $medias = array_map(static function ($valores) { return array_sum($valores) / count($valores); }, $atributos);
    $stats = ['JOGOS' => 0, 'GOLS' => null, 'ASSISTENCIAS' => null];
    foreach ($jogos as $jogo) {
        if ($jogo['DATA_JOGO'] <= date('Y-m-d') && (int) $jogo['PARTICIPOU'] === 1) {
            $stats['JOGOS']++;
            $stats['GOLS'] = ($stats['GOLS'] ?? 0) + (int) $jogo['GOLS'];
            $stats['ASSISTENCIAS'] = ($stats['ASSISTENCIAS'] ?? 0) + (int) $jogo['ASSISTENCIAS'];
        }
    }
    $presentes = count(array_filter($presencas, static function ($p) { return (int) $p['PRESENTE'] === 1; }));
    $frequencia = $presencas ? round($presentes / count($presencas) * 100) : null;
    return compact('turmas', 'treinos', 'presencas', 'avaliacoes', 'jogos', 'metas', 'overall', 'notas', 'medias', 'stats', 'presentes', 'frequencia');
}
