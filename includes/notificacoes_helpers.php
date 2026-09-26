<?php

function notificacoesTipos() {
    return [
        'NOVO_TREINO' => 'Novo treino',
        'ALTERACAO_TREINO' => 'Alteração de treino',
        'NOVA_AVALIACAO' => 'Nova avaliação',
        'NOVA_MATRICULA' => 'Nova matrícula',
        'SOLICITACAO_CAMISA' => 'Solicitação de camisa',
        'SOLICITACAO_ACEITA' => 'Solicitação aceita',
        'SOLICITACAO_RECUSADA' => 'Solicitação recusada',
        'APROVACAO_PROFESSOR' => 'Aprovação de professor',
        'NOVO_JOGO' => 'Novo jogo',
        'NOVO_EVENTO' => 'Novo evento',
        'OUTRA_ALTERACAO' => 'Atualização importante'
    ];
}

/** Persiste somente destinatários ativos que pertencem à escolinha indicada. */
function notificacaoEnviar($db, $escolinha, $usuarios, $tipo, $titulo, $descricao, $url = null, $excluirUsuario = 0) {
    if (!isset(notificacoesTipos()[$tipo])) return 0;
    $usuarios = array_values(array_unique(array_filter(array_map('intval', $usuarios), static function ($id) use ($excluirUsuario) {
        return $id > 0 && $id !== (int) $excluirUsuario;
    })));
    if (!$usuarios) return 0;
    $url = $url && preg_match('/^[a-zA-Z0-9_-]+\.php(?:\?[a-zA-Z0-9_=&-]*)?$/', $url) ? $url : null;
    $stmt = $db->prepare(
        'INSERT INTO TB_NOTIFICACAO (COD_ESCOLINHA, COD_USUARIO, TIPO, TITULO, DESCRICAO, URL)
         SELECT COD_ESCOLINHA, COD_USUARIO, ?, ?, ?, ?
         FROM TB_USUARIO WHERE COD_USUARIO = ? AND COD_ESCOLINHA = ? AND ATIVO = 1'
    );
    $total = 0;
    foreach ($usuarios as $usuario) {
        try {
            $stmt->execute([$tipo, mb_substr($titulo, 0, 120, 'UTF-8'), $descricao, $url, $usuario, (int) $escolinha]);
            $total += $stmt->rowCount();
        } catch (PDOException $erro) {
            error_log('Não foi possível salvar uma notificação: ' . $erro->getMessage());
        }
    }
    return $total;
}

function notificacoesNaoLidas($db, $escolinha, $usuario) {
    $stmt = $db->prepare('SELECT COUNT(*) FROM TB_NOTIFICACAO WHERE COD_ESCOLINHA = ? AND COD_USUARIO = ? AND LIDA = 0');
    $stmt->execute([(int) $escolinha, (int) $usuario]);
    return (int) $stmt->fetchColumn();
}

function notificacaoDestinatariosGestao($db, $escolinha) {
    $stmt = $db->prepare('SELECT COD_USUARIO FROM TB_USUARIO WHERE COD_ESCOLINHA = ? AND ATIVO = 1 AND PERFIL IN (\'ADMIN\', \'SECRETARIA\')');
    $stmt->execute([(int) $escolinha]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** Equipe, secretaria e contas de atleta/responsável ativas na turma. */
function notificacaoDestinatariosTurma($db, $escolinha, $turma) {
    $stmt = $db->prepare(
        'SELECT COD_USUARIO FROM TB_USUARIO WHERE COD_ESCOLINHA = ? AND ATIVO = 1 AND PERFIL IN (\'ADMIN\', \'SECRETARIA\')
         UNION SELECT t.COD_PROFESSOR FROM TB_TURMA t JOIN TB_USUARIO u ON u.COD_USUARIO = t.COD_PROFESSOR AND u.ATIVO = 1 WHERE t.COD_ESCOLINHA = ? AND t.COD_TURMA = ?
         UNION SELECT al.COD_USUARIO FROM TB_MATRICULA m JOIN TB_ALUNO al ON al.COD_ALUNO = m.COD_ALUNO JOIN TB_USUARIO u ON u.COD_USUARIO = al.COD_USUARIO AND u.ATIVO = 1 WHERE m.COD_TURMA = ? AND m.STATUS = \'ATIVA\' AND al.COD_ESCOLINHA = ?
         UNION SELECT r.COD_USUARIO FROM TB_MATRICULA m JOIN TB_ALUNO al ON al.COD_ALUNO = m.COD_ALUNO JOIN TB_RESPONSAVEL r ON r.COD_RESPONSAVEL = al.COD_RESPONSAVEL JOIN TB_USUARIO u ON u.COD_USUARIO = r.COD_USUARIO AND u.ATIVO = 1 WHERE m.COD_TURMA = ? AND m.STATUS = \'ATIVA\' AND al.COD_ESCOLINHA = ?'
    );
    $stmt->execute([(int) $escolinha, (int) $escolinha, (int) $turma, (int) $turma, (int) $escolinha, (int) $turma, (int) $escolinha]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function notificacaoDestinatariosAluno($db, $escolinha, $aluno) {
    $stmt = $db->prepare(
        'SELECT COD_USUARIO FROM TB_USUARIO WHERE COD_ESCOLINHA = ? AND ATIVO = 1 AND PERFIL IN (\'ADMIN\', \'SECRETARIA\')
         UNION SELECT al.COD_USUARIO FROM TB_ALUNO al JOIN TB_USUARIO u ON u.COD_USUARIO = al.COD_USUARIO AND u.ATIVO = 1 WHERE al.COD_ALUNO = ? AND al.COD_ESCOLINHA = ?
         UNION SELECT r.COD_USUARIO FROM TB_ALUNO al JOIN TB_RESPONSAVEL r ON r.COD_RESPONSAVEL = al.COD_RESPONSAVEL JOIN TB_USUARIO u ON u.COD_USUARIO = r.COD_USUARIO AND u.ATIVO = 1 WHERE al.COD_ALUNO = ? AND al.COD_ESCOLINHA = ?
         UNION SELECT t.COD_PROFESSOR FROM TB_MATRICULA m JOIN TB_TURMA t ON t.COD_TURMA = m.COD_TURMA JOIN TB_USUARIO u ON u.COD_USUARIO = t.COD_PROFESSOR AND u.ATIVO = 1 WHERE m.COD_ALUNO = ? AND m.STATUS = \'ATIVA\' AND t.COD_ESCOLINHA = ?'
    );
    $stmt->execute([(int) $escolinha, (int) $aluno, (int) $escolinha, (int) $aluno, (int) $escolinha, (int) $aluno, (int) $escolinha]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function notificacaoDataTreino($data, $horario = null) {
    return date('d/m/Y', strtotime($data)) . ($horario ? ' às ' . substr($horario, 0, 5) : '');
}
