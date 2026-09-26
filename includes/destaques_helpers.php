<?php

/** Calcula destaques exclusivamente a partir de avaliações e súmulas registradas. */
function buscarDestaquesSemanais($db, $escolinha, $perfil, $usuario, $responsavelId = 0) {
    $periodo = $db->query(
        'SELECT DATE_SUB(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 7 DAY) AS SEMANA_ANTERIOR,
                DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY) AS INICIO_SEMANA,
                DATE_ADD(CURDATE(), INTERVAL 1 DAY) AS FIM_EXCLUSIVO'
    )->fetch();
    $filtroTurma = '';
    $parametrosScope = [(int) $escolinha];
    if ($perfil === 'PROFESSOR') {
        $filtroTurma .= ' AND t.COD_PROFESSOR = ?';
        $parametrosScope[] = (int) $usuario;
    } elseif ($perfil === 'RESPONSAVEL') {
        $filtroTurma .= ' AND al.COD_RESPONSAVEL = ?';
        $parametrosScope[] = (int) $responsavelId;
    } elseif ($perfil === 'ALUNO') {
        $filtroTurma .= " AND EXISTS (SELECT 1 FROM TB_MATRICULA selfm JOIN TB_ALUNO selfa ON selfa.COD_ALUNO = selfm.COD_ALUNO WHERE selfm.COD_TURMA = t.COD_TURMA AND selfm.STATUS = 'ATIVA' AND selfa.COD_USUARIO = ?)";
        $parametrosScope[] = (int) $usuario;
    }

    $consulta = $db->prepare(
        'SELECT al.COD_ALUNO, al.NOME AS ATLETA, al.NUMERO_CAMISA,
                t.COD_TURMA, t.NOME AS TURMA, t.FAIXA_ETARIA,
                AVG(CASE WHEN av.DATA_AVALIACAO >= ? AND av.DATA_AVALIACAO < ? THEN av.NOTA_GERAL END) AS MEDIA_ATUAL,
                AVG(CASE WHEN av.DATA_AVALIACAO >= ? AND av.DATA_AVALIACAO < ? THEN av.NOTA_GERAL END) AS MEDIA_ANTERIOR
         FROM TB_AVALIACAO av
         JOIN TB_ALUNO al ON al.COD_ALUNO = av.COD_ALUNO AND al.COD_ESCOLINHA = ?
         JOIN TB_AULA au ON au.COD_AULA = av.COD_AULA
         JOIN TB_TURMA t ON t.COD_TURMA = au.COD_TURMA
         WHERE av.DATA_AVALIACAO >= ? AND av.DATA_AVALIACAO < ? AND av.NOTA_GERAL IS NOT NULL' . $filtroTurma . '
         GROUP BY al.COD_ALUNO, al.NOME, al.NUMERO_CAMISA, t.COD_TURMA, t.NOME, t.FAIXA_ETARIA
         HAVING MEDIA_ATUAL IS NOT NULL AND MEDIA_ANTERIOR IS NOT NULL'
    );
    $consulta->execute(array_merge([
        $periodo['INICIO_SEMANA'], $periodo['FIM_EXCLUSIVO'],
        $periodo['SEMANA_ANTERIOR'], $periodo['INICIO_SEMANA'],
        (int) $escolinha,
        $periodo['SEMANA_ANTERIOR'], $periodo['FIM_EXCLUSIVO']
    ], array_slice($parametrosScope, 1)));
    $evolucaoPorTurma = [];
    foreach ($consulta->fetchAll() as $linha) {
        $variacao = round((float) $linha['MEDIA_ATUAL'] - (float) $linha['MEDIA_ANTERIOR'], 2);
        if ($variacao <= 0) continue;
        $linha['VARIACAO'] = $variacao;
        $evolucaoPorTurma[(int) $linha['COD_TURMA']][] = $linha;
    }
    $maiorEvolucao = [];
    foreach ($evolucaoPorTurma as $linhas) {
        $maior = max(array_column($linhas, 'VARIACAO'));
        $maiorEvolucao[] = [
            'turma' => $linhas[0]['TURMA'],
            'categoria' => $linhas[0]['FAIXA_ETARIA'],
            'atletas' => array_values(array_filter($linhas, static function ($linha) use ($maior) { return (float) $linha['VARIACAO'] === (float) $maior; }))
        ];
    }

    $consulta = $db->prepare(
        'SELECT t.COD_TURMA, t.NOME AS TURMA, t.FAIXA_ETARIA,
                al.COD_ALUNO, al.NOME AS ATLETA, al.NUMERO_CAMISA,
                SUM(ja.GOLS) AS GOLS, SUM(ja.ASSISTENCIAS) AS ASSISTENCIAS
         FROM TB_JOGO_ATLETA ja
         JOIN TB_JOGO j ON j.COD_JOGO = ja.COD_JOGO
         JOIN TB_TURMA t ON t.COD_TURMA = j.COD_TURMA
         JOIN TB_ALUNO al ON al.COD_ALUNO = ja.COD_ALUNO AND al.COD_ESCOLINHA = t.COD_ESCOLINHA
         WHERE t.COD_ESCOLINHA = ? AND j.DATA_JOGO >= ? AND j.DATA_JOGO < ? AND ja.PARTICIPOU = 1' . $filtroTurma . '
         GROUP BY t.COD_TURMA, t.NOME, t.FAIXA_ETARIA, al.COD_ALUNO, al.NOME, al.NUMERO_CAMISA'
    );
    $consulta->execute(array_merge([(int) $escolinha, $periodo['INICIO_SEMANA'], $periodo['FIM_EXCLUSIVO']], array_slice($parametrosScope, 1)));
    $numerosPorTurma = [];
    foreach ($consulta->fetchAll() as $linha) $numerosPorTurma[(int) $linha['COD_TURMA']][] = $linha;
    $leaders = ['gols' => [], 'assistencias' => []];
    foreach ($numerosPorTurma as $linhas) {
        foreach (['gols' => 'GOLS', 'assistencias' => 'ASSISTENCIAS'] as $chave => $coluna) {
            $maior = max(array_map('intval', array_column($linhas, $coluna)));
            if ($maior < 1) continue;
            $leaders[$chave][] = [
                'turma' => $linhas[0]['TURMA'],
                'categoria' => $linhas[0]['FAIXA_ETARIA'],
                'valor' => $maior,
                'atletas' => array_values(array_filter($linhas, static function ($linha) use ($coluna, $maior) { return (int) $linha[$coluna] === $maior; }))
            ];
        }
    }

    return ['inicio' => $periodo['INICIO_SEMANA'], 'evolucao' => $maiorEvolucao, 'gols' => $leaders['gols'], 'assistencias' => $leaders['assistencias']];
}
