<?php
// Gerencia cadastros, vínculos com turmas, acesso do aluno e mensalidades.
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/notificacoes_helpers.php';

checkRole(['ADMIN', 'SECRETARIA']);
$db = getDB();
$escolinha = currentEscolinhaId();
$edit = null;


// A competência define o mês do relatório, mesmo quando a data da baixa cai em outro mês.
if (isset($_GET['mensalidades'])) {
    $mes = $_GET['mes'] ?? date('Y-m');
    if (!is_string($mes) || !validDate($mes . '-01')) $mes = date('Y-m');
    $competencia = $mes . '-01';
    $hoje = date('Y-m-d');
    $retorno = 'alunos.php?mensalidades=1&mes=' . $mes;
    $relatorio = isset($_GET['relatorio']);
    $situacoesMensalidade = ['PAGO' => 'Paga', 'ATRASADO' => 'Atrasada', 'NAO_PAGO' => 'Não paga'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        checkCsrf();
        $acao = $_POST['acao'] ?? '';
        // Remove apenas a mensalidade paga selecionada na competência aberta.
        if ($acao === 'excluir_paga') {
            $lancamento = filter_input(INPUT_POST, 'lancamento', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            try {
                $q = $db->prepare("DELETE FROM TB_FINANCEIRO
                    WHERE COD_LANCAMENTO=? AND COD_ESCOLINHA=? AND COMPETENCIA=?
                      AND CATEGORIA='MENSALIDADE' AND TIPO='RECEITA' AND STATUS='PAGO'
                      AND COD_ALUNO IS NOT NULL");
                $q->execute([$lancamento ?: null, $escolinha, $competencia]);
                if ($q->rowCount()) {
                    flash('success', 'Mensalidade paga excluída.');
                } else {
                    flash('error', 'Mensalidade paga indisponível ou já atualizada.');
                }
            } catch (PDOException $error) {
                flash('error', 'Não foi possível excluir a mensalidade paga.');
            }
            go($retorno);
        }
        $alunoId = filter_input(INPUT_POST, 'aluno', FILTER_VALIDATE_INT);
        $novaSituacao = in_array($acao, ['criar_paga', 'pagar'], true) ? 'PAGO' : ($_POST['situacao'] ?? '');
        $data = $novaSituacao === 'PAGO' ? ($_POST['data_pagamento'] ?? '') : null;
        try {
            if (!$alunoId || !is_string($novaSituacao) || !isset($situacoesMensalidade[$novaSituacao])) {
                throw new InvalidArgumentException('Informe uma situação válida para a mensalidade.');
            }
            if ($novaSituacao === 'PAGO' && (!validDate($data) || $data > $hoje)) {
                throw new InvalidArgumentException('Informe uma data de pagamento válida, até hoje.');
            }
            if ($novaSituacao === 'PAGO' && $data < $competencia) {
                throw new InvalidArgumentException('A data do pagamento não pode ser anterior ao mês da mensalidade.');
            }
            if ($acao === 'pagar') {
                $q = $db->prepare("UPDATE TB_FINANCEIRO SET STATUS='PAGO', DATA_PAGAMENTO=?
                    WHERE COD_ESCOLINHA=? AND COD_ALUNO=? AND COMPETENCIA=?
                      AND CATEGORIA='MENSALIDADE' AND TIPO='RECEITA' AND STATUS IN ('PENDENTE','ATRASADO','NAO_PAGO')");
                $q->execute([$data, $escolinha, $alunoId, $competencia]);
            } elseif ($acao === 'editar_situacao') {
                $anterior = $_POST['situacao_anterior'] ?? '';
                if (!in_array($anterior, ['PENDENTE', 'PAGO', 'ATRASADO', 'NAO_PAGO'], true)) {
                    throw new InvalidArgumentException('Atualize a lista de mensalidades e tente novamente.');
                }
                $q = $db->prepare("UPDATE TB_FINANCEIRO SET STATUS=?, DATA_PAGAMENTO=?
                    WHERE COD_LANCAMENTO=? AND COD_ESCOLINHA=? AND COD_ALUNO=? AND COMPETENCIA=?
                      AND CATEGORIA='MENSALIDADE' AND TIPO='RECEITA' AND STATUS=?");
                $q->execute([$novaSituacao, $data, (int) ($_POST['lancamento'] ?? 0), $escolinha, $alunoId, $competencia, $anterior]);
            } elseif (in_array($acao, ['criar_paga', 'criar_mensalidade'], true)) {
                $valor = $_POST['valor'] ?? '';
                $vencimento = $_POST['vencimento'] ?? '';
                $valor = is_string($valor) ? str_replace(',', '.', trim($valor)) : '';
                if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/D', $valor) || (float) $valor <= 0 ||
                    !is_string($vencimento) || !validDate($vencimento)) {
                    throw new InvalidArgumentException('Informe o valor e o vencimento da mensalidade.');
                }
                $q = $db->prepare("INSERT INTO TB_FINANCEIRO
                    (COD_ESCOLINHA,COD_ALUNO,TIPO,CATEGORIA,DESCRICAO,COMPETENCIA,VALOR,VENCIMENTO,DATA_PAGAMENTO,STATUS)
                    SELECT COD_ESCOLINHA,COD_ALUNO,'RECEITA','MENSALIDADE','Mensalidade',?,?,?,?,?
                    FROM TB_ALUNO WHERE COD_ESCOLINHA=? AND COD_ALUNO=? AND STATUS='ATIVO'");
                $q->execute([$competencia, $valor, $vencimento, $data, $novaSituacao, $escolinha, $alunoId]);
            } else {
                throw new InvalidArgumentException('Ação inválida.');
            }
            if (!$q->rowCount()) throw new InvalidArgumentException('Mensalidade indisponível ou já atualizada.');
            flash('success', $novaSituacao === 'PAGO' ? 'Pagamento registrado.' : 'Situação da mensalidade atualizada.');
        } catch (InvalidArgumentException $error) {
            flash('error', $error->getMessage());
        } catch (PDOException $error) {
            flash('error', (int) ($error->errorInfo[1] ?? 0) === 1062
                ? 'Já existe uma mensalidade para este aluno nesse mês. Recarregue a lista.'
                : 'Não foi possível salvar a mensalidade.');
        }
        go($retorno);
    }

    $q = $db->prepare("SELECT a.COD_ALUNO,a.NOME,a.STATUS AS STATUS_ALUNO,
            f.COD_LANCAMENTO,f.TIPO,f.STATUS,f.VALOR,f.VENCIMENTO,f.DATA_PAGAMENTO
        FROM TB_ALUNO a LEFT JOIN TB_FINANCEIRO f ON f.COD_ALUNO=a.COD_ALUNO
            AND f.COD_ESCOLINHA=a.COD_ESCOLINHA AND f.CATEGORIA='MENSALIDADE' AND f.COMPETENCIA=?
        WHERE a.COD_ESCOLINHA=? AND (a.STATUS='ATIVO' OR f.COD_LANCAMENTO IS NOT NULL)
        ORDER BY a.NOME, a.COD_ALUNO");
    $q->execute([$competencia, $escolinha]);
    $mensalidades = $q->fetchAll();
    $pagas = count(array_filter($mensalidades, static function ($item) { return $item['STATUS'] === 'PAGO'; }));
    pageStart($relatorio ? 'Relatório de mensalidades' : 'Mensalidades dos alunos');
    ?>
    <section class="mensalidades-alunos">
        <div class="mensalidades-controles">
            <a href="alunos.php">Voltar para Alunos</a>
            <?php if ($relatorio): ?>
                <a href="<?= e($retorno) ?>">Voltar aos pagamentos</a>
                <button type="button" class="botao-principal" id="imprimirMensalidades">Imprimir ou salvar PDF</button>
            <?php else: ?>
                <form method="get" class="mensalidades-mes">
                    <input type="hidden" name="mensalidades" value="1">
                    <label>Mês <input type="month" name="mes" required value="<?= e($mes) ?>"></label>
                    <?php actionButton('Consultar'); ?>
                </form>
                <a class="botao-principal" href="<?= e($retorno . '&relatorio=1') ?>">Gerar relatório</a>
            <?php endif; ?>
        </div>
        <h2>Mensalidades de <?= e(date('m/Y', strtotime($competencia))) ?></h2>
        <p><?= $pagas ?> pagas de <?= count($mensalidades) ?> alunos na lista.</p>
        <div class="tabela-alunos"><table>
            <thead><tr><th>Aluno</th><th>Situação</th><th>Valor</th><th>Vencimento</th><th>Pagamento</th><?php if (!$relatorio): ?><th>Ação</th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($mensalidades as $item):
                $situacaoAtual = match ($item['STATUS']) {
                    'PENDENTE' => $item['VENCIMENTO'] < $hoje ? 'ATRASADO' : 'NAO_PAGO',
                    null => 'NAO_PAGO',
                    default => $item['STATUS'],
                };
                $pagaComAtraso = $situacaoAtual === 'PAGO' && $item['DATA_PAGAMENTO'] && $item['VENCIMENTO']
                    && $item['DATA_PAGAMENTO'] > $item['VENCIMENTO'];
                $status = $pagaComAtraso ? 'Paga com atraso' : ($situacoesMensalidade[$situacaoAtual] ?? 'Cancelada');
                $classeSituacao = ['ATRASADO' => 'mensalidade-atrasada', 'NAO_PAGO' => 'mensalidade-nao-paga'][$situacaoAtual] ?? '';
                if ($pagaComAtraso) $classeSituacao = 'mensalidade-atrasada';
            ?>
                <tr>
                    <td><?= e($item['NOME']) ?></td>
                    <td><strong class="<?= e($classeSituacao) ?>"><?= e($status) ?></strong><?php if ($item['STATUS'] === null): ?><br><small>Sem lançamento</small><?php endif; ?></td>
                    <td><?= $item['VALOR'] === null ? '—' : 'R$ ' . e(number_format((float) $item['VALOR'], 2, ',', '.')) ?></td>
                    <td><?= $item['VENCIMENTO'] ? e(date('d/m/Y', strtotime($item['VENCIMENTO']))) : '—' ?></td>
                    <td><?= $item['DATA_PAGAMENTO'] ? e(date('d/m/Y', strtotime($item['DATA_PAGAMENTO']))) : '—' ?></td>
                    <?php if (!$relatorio): ?><td>
                        <?php if ($item['STATUS'] === 'PAGO' && $item['TIPO'] === 'RECEITA'): ?>
                            <form method="post" action="<?= e($retorno) ?>" data-confirm-submit="Excluir esta mensalidade paga? O lançamento será removido do relatório do mês.">
                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="acao" value="excluir_paga">
                                <input type="hidden" name="lancamento" value="<?= (int) $item['COD_LANCAMENTO'] ?>">
                                <button type="submit" class="acao-tabela">Excluir</button>
                            </form>
                        <?php endif; ?>
                        <?php if (($item['TIPO'] === 'RECEITA' && $item['STATUS'] !== 'CANCELADO') || ($item['STATUS'] === null && $item['STATUS_ALUNO'] === 'ATIVO')): ?>
                            <details>
                            <summary>Editar situação</summary>
                            <form method="post" action="<?= e($retorno) ?>" class="mensalidade-pagamento">
                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="aluno" value="<?= (int) $item['COD_ALUNO'] ?>">
                                <input type="hidden" name="acao" value="<?= $item['STATUS'] === null ? 'criar_mensalidade' : 'editar_situacao' ?>">
                                <?php if ($item['STATUS'] !== null): ?>
                                    <input type="hidden" name="lancamento" value="<?= (int) $item['COD_LANCAMENTO'] ?>">
                                    <input type="hidden" name="situacao_anterior" value="<?= e($item['STATUS']) ?>">
                                <?php endif; ?>
                                <label>Situação<select name="situacao">
                                    <?php foreach ($situacoesMensalidade as $codigo => $rotulo): ?>
                                        <?php if ($codigo === 'PAGO' && $competencia > $hoje) continue; ?>
                                        <option value="<?= e($codigo) ?>" <?= $codigo === $situacaoAtual ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                                    <?php endforeach; ?>
                                </select></label>
                                <?php if ($item['STATUS'] === null): ?>
                                    <label>Valor (R$)<input type="number" name="valor" min="0.01" step="0.01" required></label>
                                    <label>Vencimento<input type="date" name="vencimento" required></label>
                                <?php endif; ?>
                                <label data-data-pagamento>Data do pagamento<input type="date" name="data_pagamento" value="<?= e($item['DATA_PAGAMENTO'] ?? $hoje) ?>" min="<?= e($competencia) ?>" max="<?= e($hoje) ?>"></label>
                                <?php actionButton('Salvar situação'); ?>
                            </form>
                            </details>
                        <?php endif; ?>
                    </td><?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (!$mensalidades): ?><tr><td colspan="<?= $relatorio ? 5 : 6 ?>">Nenhum aluno neste mês.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </section>
    <?php if ($relatorio): ?>
        <script nonce="<?= cspNonce() ?>">document.getElementById('imprimirMensalidades').addEventListener('click', () => window.print());</script>
    <?php else: ?>
        <script nonce="<?= cspNonce() ?>">
            for (const form of document.querySelectorAll('.mensalidade-pagamento')) {
                const situacao = form.elements.situacao;
                const data = form.elements.data_pagamento;
                const campo = form.querySelector('[data-data-pagamento]');
                const atualizarData = () => {
                    const paga = situacao.value === 'PAGO';
                    campo.hidden = !paga;
                    data.disabled = !paga;
                    data.required = paga;
                };
                situacao.addEventListener('change', atualizarData);
                atualizarData();
            }
        </script>
    <?php endif; ?>
    <?php pageEnd();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    foreach (['nome', 'data_nascimento', 'posicao', 'status', 'observacoes_medicas', 'email_acesso', 'senha_acesso', 'numero_camisa'] as $campo) {
        if (isset($_POST[$campo]) && !is_string($_POST[$campo])) {
            flash('error', 'Revise os campos informados.');
            go('alunos.php');
        }
    }
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'inactivate') {
        try {
            $db->beginTransaction();
            $stmt = $db->prepare('SELECT COD_USUARIO FROM TB_ALUNO WHERE COD_ALUNO = ? AND COD_ESCOLINHA = ? FOR UPDATE');
            $stmt->execute([$id, $escolinha]);
            $aluno = $stmt->fetch();
            if (!$aluno) {
                $db->rollBack();
                flash('error', 'Aluno não encontrado.');
                go('alunos.php');
            }
            $db->prepare("UPDATE TB_ALUNO SET STATUS = 'INATIVO' WHERE COD_ALUNO = ? AND COD_ESCOLINHA = ?")
                ->execute([$id, $escolinha]);
            $db->prepare("UPDATE TB_MATRICULA SET STATUS = 'ENCERRADA' WHERE COD_ALUNO = ? AND STATUS = 'ATIVA'")
                ->execute([$id]);
            if ($aluno['COD_USUARIO']) {
                $db->prepare("UPDATE TB_USUARIO SET ATIVO = 0 WHERE COD_USUARIO = ? AND COD_ESCOLINHA = ? AND PERFIL = 'ALUNO'")
                    ->execute([$aluno['COD_USUARIO'], $escolinha]);
            }
            $db->commit();
            flash('success', 'Aluno inativado. O histórico foi preservado.');
        } catch (PDOException $error) {
            if ($db->inTransaction()) $db->rollBack();
            flash('error', 'Não foi possível inativar o aluno.');
        }
        go('alunos.php');
    }

    // A exclusão definitiva apaga os vínculos do aluno dentro da mesma transação.
    if ($action === 'delete') {
        try {
            $db->beginTransaction();
            $stmt = $db->prepare('SELECT COD_USUARIO FROM TB_ALUNO WHERE COD_ALUNO = ? AND COD_ESCOLINHA = ? FOR UPDATE');
            $stmt->execute([$id, $escolinha]);
            $aluno = $stmt->fetch();
            if (!$aluno) {
                $db->rollBack();
                flash('error', 'Aluno não encontrado.');
                go('alunos.php');
            }

            // O financeiro usa RESTRICT. Os demais vínculos do aluno têm exclusão em cascata.
            $db->prepare('DELETE FROM TB_FINANCEIRO WHERE COD_ALUNO = ? AND COD_ESCOLINHA = ?')
                ->execute([$id, $escolinha]);
            $db->prepare('DELETE FROM TB_ALUNO WHERE COD_ALUNO = ? AND COD_ESCOLINHA = ?')
                ->execute([$id, $escolinha]);
            if ($aluno['COD_USUARIO']) {
                $db->prepare("DELETE FROM TB_USUARIO WHERE COD_USUARIO = ? AND COD_ESCOLINHA = ? AND PERFIL = 'ALUNO'")
                    ->execute([$aluno['COD_USUARIO'], $escolinha]);
            }
            $db->commit();
            flash('success', 'Aluno e histórico vinculado excluídos.');
        } catch (PDOException $error) {
            if ($db->inTransaction()) $db->rollBack();
            flash('error', 'Não foi possível excluir o aluno e o histórico.');
        }
        go('alunos.php');
    }

    $nome = trim($_POST['nome'] ?? '');
    $nascimento = $_POST['data_nascimento'] ?? '';
    $responsavel = (int) ($_POST['cod_responsavel'] ?? 0);
    $posicao = trim($_POST['posicao'] ?? '');
    $status = $_POST['status'] ?? 'ATIVO';
    $turma = (int) ($_POST['cod_turma'] ?? 0);
    $observacoes = trim($_POST['observacoes_medicas'] ?? '');
    if (mb_strlen($nome)>100 || mb_strlen($posicao)>30 || mb_strlen($observacoes)>65535) { flash('error','Nome (até 100 caracteres), posição (até 30) ou observações excedem o limite.'); go('alunos.php'); }
    $emailAluno = trim($_POST['email_acesso'] ?? '');
    $senhaAluno = postString('senha_acesso');
    $numeroTexto = trim(postString('numero_camisa'));
    $numeroCamisa = $numeroTexto === '' ? null : filter_var($numeroTexto, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 99]]);
    if ($numeroCamisa === false) {
        flash('error', 'Informe um número de camisa de 0 a 99.');
        go('alunos.php' . ($id ? '?editar=' . $id : ''));
    }

    if (!is_string($nascimento) || idadeAluno($nascimento) === null) {
        flash('error', 'Informe uma data de nascimento válida que não esteja no futuro.');
        go('alunos.php' . ($id ? '?editar=' . $id : ''));
    }
    if ($nome === '' || $responsavel <= 0 || !in_array($status, ['ATIVO', 'INATIVO', 'PENDENTE'], true)) {
        flash('error', 'Revise os campos obrigatórios do aluno.');
        go('alunos.php' . ($id ? '?editar=' . $id : ''));
    }

    $validResponsavel = $db->prepare('SELECT 1 FROM TB_RESPONSAVEL WHERE COD_RESPONSAVEL = ? AND COD_ESCOLINHA = ?');
    $validResponsavel->execute([$responsavel, $escolinha]);
    if (!$validResponsavel->fetchColumn()) {
        flash('error', 'O responsável selecionado não pertence a esta escolinha.');
        go('alunos.php');
    }

    // Evita repetir o mesmo aluno para o mesmo responsável.
    $duplicado = $db->prepare(
        'SELECT 1 FROM TB_ALUNO
         WHERE COD_ESCOLINHA = ? AND COD_RESPONSAVEL = ?
           AND LOWER(TRIM(NOME)) = LOWER(TRIM(?)) AND DATA_NASCIMENTO = ?
           AND COD_ALUNO <> ? LIMIT 1'
    );
    $duplicado->execute([$escolinha, $responsavel, $nome, $nascimento, $id]);
    if ($duplicado->fetchColumn()) {
        flash('error', 'Este responsável já tem um aluno com o mesmo nome e data de nascimento.');
        go('alunos.php' . ($id ? '?editar=' . $id : ''));
    }

    if (($emailAluno !== '' && !filter_var($emailAluno, FILTER_VALIDATE_EMAIL)) || ($emailAluno !== '' && $senhaAluno !== '' && (strlen($senhaAluno) < 6 || strlen($senhaAluno) > 72 || str_contains($senhaAluno, "\0"))) || ($emailAluno === '' && $senhaAluno !== '')) {
        flash('error', 'Para criar ou atualizar o acesso do aluno, informe um e-mail válido e uma senha de ao menos 6 caracteres.');
        go('alunos.php' . ($id ? '?editar=' . $id : ''));
    }

    try {
        $db->beginTransaction();
        if (!in_array($action, ['create', 'update'], true)) {
            throw new InvalidArgumentException('Ação de cadastro inválida.');
        }
        // O bloqueio da turma serializa matrículas que disputam a última vaga.
        if ($status === 'ATIVO') {
            $consultaTurma = $db->prepare('SELECT CAPACIDADE, FAIXA_ETARIA FROM TB_TURMA WHERE COD_TURMA = ? AND COD_ESCOLINHA = ? AND ATIVA = 1 FOR UPDATE');
            $consultaTurma->execute([$turma, $escolinha]);
            $turmaSelecionada = $consultaTurma->fetch();
            if (!$turmaSelecionada || !categoriaAceitaIdade($turmaSelecionada['FAIXA_ETARIA'], idadeAluno($nascimento))) {
                throw new InvalidArgumentException('Selecione uma turma compatível com a idade. Se não houver, crie uma em Turmas.');
            }
            $ocupadas = $db->prepare("SELECT COUNT(*) FROM TB_MATRICULA m JOIN TB_ALUNO a ON a.COD_ALUNO = m.COD_ALUNO WHERE m.COD_TURMA = ? AND m.STATUS = 'ATIVA' AND a.STATUS = 'ATIVO' AND m.COD_ALUNO <> ?");
            $ocupadas->execute([$turma, $action === 'update' ? $id : 0]);
            if ((int) $ocupadas->fetchColumn() >= (int) $turmaSelecionada['CAPACIDADE']) {
                throw new InvalidArgumentException('Esta turma já atingiu a capacidade máxima.');
            }
        }
        if ($action === 'create') {
            $stmt = $db->prepare('INSERT INTO TB_ALUNO (COD_ESCOLINHA, COD_RESPONSAVEL, NOME, DATA_NASCIMENTO, POSICAO, STATUS, DATA_MATRICULA, OBSERVACOES_MEDICAS, NUMERO_CAMISA) VALUES (?, ?, ?, ?, ?, ?, CURDATE(), ?, ?)');
            $stmt->execute([$escolinha, $responsavel, $nome, $nascimento, $posicao ?: 'Não definida', $status, $observacoes ?: null, $numeroCamisa]);
            $id = (int) $db->lastInsertId();
        } elseif ($action === 'update') {
            $anterior = $db->prepare('SELECT NUMERO_CAMISA FROM TB_ALUNO WHERE COD_ALUNO = ? AND COD_ESCOLINHA = ? FOR UPDATE');
            $anterior->execute([$id, $escolinha]);
            $cadastroAtual = $anterior->fetch();
            if (!$cadastroAtual) {
                throw new InvalidArgumentException('Aluno não encontrado.');
            }
            if (!array_key_exists('numero_camisa', $_POST)) $numeroCamisa = $cadastroAtual['NUMERO_CAMISA'];
            $stmt = $db->prepare('UPDATE TB_ALUNO SET COD_RESPONSAVEL = ?, NOME = ?, DATA_NASCIMENTO = ?, POSICAO = ?, STATUS = ?, OBSERVACOES_MEDICAS = ?, NUMERO_CAMISA = ? WHERE COD_ALUNO = ? AND COD_ESCOLINHA = ?');
            $stmt->execute([$responsavel, $nome, $nascimento, $posicao ?: 'Não definida', $status, $observacoes ?: null, $numeroCamisa, $id, $escolinha]);
        }

        if ($emailAluno !== '') {
            $stmt = $db->prepare('SELECT a.COD_USUARIO, u.NOME FROM TB_ALUNO a LEFT JOIN TB_USUARIO u ON u.COD_USUARIO = a.COD_USUARIO WHERE a.COD_ALUNO = ? AND a.COD_ESCOLINHA = ? FOR UPDATE');
            $stmt->execute([$id, $escolinha]);
            $acesso = $stmt->fetch();
            if ($acesso && $acesso['COD_USUARIO']) {
                if ($senhaAluno !== '') {
                    $db->prepare('UPDATE TB_USUARIO SET NOME = ?, EMAIL = ?, SENHA_HASH = ?, ATIVO = ? WHERE COD_USUARIO = ? AND COD_ESCOLINHA = ? AND PERFIL = "ALUNO"')
                        ->execute([$nome, $emailAluno, password_hash($senhaAluno, PASSWORD_DEFAULT), $status === 'ATIVO' ? 1 : 0, $acesso['COD_USUARIO'], $escolinha]);
                } else {
                    $db->prepare('UPDATE TB_USUARIO SET NOME = ?, EMAIL = ?, ATIVO = ? WHERE COD_USUARIO = ? AND COD_ESCOLINHA = ? AND PERFIL = "ALUNO"')
                        ->execute([$nome, $emailAluno, $status === 'ATIVO' ? 1 : 0, $acesso['COD_USUARIO'], $escolinha]);
                }
            } elseif ($acesso) {
                if ((strlen($senhaAluno) < 6 || strlen($senhaAluno) > 72 || str_contains($senhaAluno, "\0"))) throw new InvalidArgumentException('Informe uma senha de ao menos 6 caracteres para criar o primeiro acesso.');
                $db->prepare('INSERT INTO TB_USUARIO (COD_ESCOLINHA,NOME,EMAIL,SENHA_HASH,PERFIL,ATIVO) VALUES (?,?,?,?,"ALUNO",?)')
                    ->execute([$escolinha, $nome, $emailAluno, password_hash($senhaAluno, PASSWORD_DEFAULT), $status === 'ATIVO' ? 1 : 0]);
                $db->prepare('UPDATE TB_ALUNO SET COD_USUARIO = ? WHERE COD_ALUNO = ? AND COD_ESCOLINHA = ?')->execute([(int) $db->lastInsertId(), $id, $escolinha]);
            }
        }
        if ($status === 'ATIVO') {
            $db->prepare("UPDATE TB_MATRICULA SET STATUS = 'ENCERRADA' WHERE COD_ALUNO = ? AND COD_TURMA <> ? AND STATUS = 'ATIVA'")
                ->execute([$id, $turma]);
            $matricula = $db->prepare("SELECT STATUS FROM TB_MATRICULA WHERE COD_ALUNO = ? AND COD_TURMA = ?");
            $matricula->execute([$id, $turma]);
            if ($matricula->fetchColumn() !== 'ATIVA') {
                $db->prepare("INSERT INTO TB_MATRICULA (COD_ALUNO, COD_TURMA, DATA_MATRICULA, STATUS) VALUES (?, ?, CURDATE(), 'ATIVA') ON DUPLICATE KEY UPDATE STATUS = 'ATIVA'")
                ->execute([$id, $turma]);
                notificacaoEnviar($db, $escolinha, notificacaoDestinatariosAluno($db, $escolinha, $id), 'NOVA_MATRICULA', 'Nova matrícula', 'Uma matrícula foi registrada para você na turma selecionada.', 'meus_alunos.php', (int) $_SESSION['user_id']);
            }
        } else {
            $db->prepare("UPDATE TB_MATRICULA SET STATUS = 'ENCERRADA' WHERE COD_ALUNO = ? AND STATUS = 'ATIVA'")->execute([$id]);
        }
        if ($emailAluno === '' && $action === 'update') {
            $db->prepare("UPDATE TB_USUARIO u JOIN TB_ALUNO a ON a.COD_USUARIO = u.COD_USUARIO SET u.ATIVO = ? WHERE a.COD_ALUNO = ? AND u.COD_ESCOLINHA = ? AND u.PERFIL = 'ALUNO'")
                ->execute([$status === 'ATIVO' ? 1 : 0, $id, $escolinha]);
        }
        $db->commit();
        $mensagem = $action === 'create'
            ? ($status === 'ATIVO' ? 'Aluno cadastrado e matriculado na turma.' : 'Aluno cadastrado.')
            : 'Dados do aluno atualizados.';
        flash('success', $mensagem);
    } catch (InvalidArgumentException $e) {
        if ($db->inTransaction()) $db->rollBack();
        flash('error', $e->getMessage());
        go('alunos.php' . ($id ? '?editar=' . $id : ''));
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        flash('error', isDuplicateShirt($e) ? 'Esse número já está com outro aluno ativo nesta escolinha.'
            : 'Não foi possível salvar os dados ou o acesso. Verifique os campos e se o e-mail já está em uso.');
        go('alunos.php' . ($id ? '?editar=' . $id : ''));
    }
    voltarAposSalvarCadastro('alunos.php');
}

if (isset($_GET['editar'])) {
    $stmt = $db->prepare("SELECT a.*, u.EMAIL AS EMAIL_ACESSO,
        (SELECT m.COD_TURMA FROM TB_MATRICULA m JOIN TB_TURMA t ON t.COD_TURMA = m.COD_TURMA
         WHERE m.COD_ALUNO = a.COD_ALUNO AND t.COD_ESCOLINHA = a.COD_ESCOLINHA
         ORDER BY (m.STATUS = 'ATIVA') DESC, m.COD_MATRICULA DESC LIMIT 1) AS TURMA_ALUNO
        FROM TB_ALUNO a LEFT JOIN TB_USUARIO u ON u.COD_USUARIO = a.COD_USUARIO AND u.PERFIL = 'ALUNO'
        WHERE a.COD_ALUNO = ? AND a.COD_ESCOLINHA = ?");
    $stmt->execute([(int) $_GET['editar'], $escolinha]);
    $edit = $stmt->fetch();
    if (!$edit) {
        flash('error', 'Aluno não encontrado.');
        go('alunos.php');
    }
}

$responsaveis = $db->prepare('SELECT COD_RESPONSAVEL, NOME FROM TB_RESPONSAVEL WHERE COD_ESCOLINHA = ? ORDER BY NOME');
$responsaveis->execute([$escolinha]);
$responsaveis = $responsaveis->fetchAll();
$lista = $db->prepare("SELECT a.*, r.NOME AS RESPONSAVEL, GROUP_CONCAT(t.NOME ORDER BY t.NOME SEPARATOR ', ') AS TURMAS_ATIVAS
    FROM TB_ALUNO a JOIN TB_RESPONSAVEL r ON r.COD_RESPONSAVEL = a.COD_RESPONSAVEL
    LEFT JOIN TB_MATRICULA m ON m.COD_ALUNO = a.COD_ALUNO AND m.STATUS = 'ATIVA'
    LEFT JOIN TB_TURMA t ON t.COD_TURMA = m.COD_TURMA AND t.COD_ESCOLINHA = a.COD_ESCOLINHA
    WHERE a.COD_ESCOLINHA = ? GROUP BY a.COD_ALUNO ORDER BY a.NOME");
$lista->execute([$escolinha]);
$lista = $lista->fetchAll();

pageStart('Alunos');
$value = function ($field, $default = '') use ($edit) { return e($edit[$field] ?? $default); };
?>
<?php if (isset($_GET['mini']) || $edit): ?>
<section>
    <h2><?= $edit ? 'Editar aluno' : 'Novo aluno' ?></h2>
    <?php if (!$responsaveis): ?><p>Cadastre um responsável antes de cadastrar um aluno.</p><?php else: ?>
    <?php
    $consultaCategorias = $db->prepare('SELECT COD_TURMA, NOME, FAIXA_ETARIA, DIAS_TREINO, HORARIO FROM TB_TURMA WHERE COD_ESCOLINHA = ? AND ATIVA = 1 ORDER BY NOME');
    $consultaCategorias->execute([$escolinha]);
    $categoriasTurmas = [];
    foreach ($consultaCategorias->fetchAll() as $turmaDados) {
        $categoria = $turmaDados['FAIXA_ETARIA'];
        $faixa = faixaIdadeCategoria($categoria);
        if ($faixa !== null) {
            $categoriasTurmas[] = [(int) $turmaDados['COD_TURMA'], $turmaDados['NOME'], $categoria, $faixa[0], $faixa[1], $turmaDados['DIAS_TREINO'], $turmaDados['HORARIO']];
        }
    }
    ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>">
        <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['COD_ALUNO'] ?>"><?php endif; ?>
        <label>Nome completo<input required name="nome" value="<?= $value('NOME') ?>"></label>
        <label>Data de nascimento<input required type="date" name="data_nascimento" id="nascimentoAluno" max="<?= e(date('Y-m-d')) ?>" value="<?= $value('DATA_NASCIMENTO') ?>" aria-describedby="categoriaAluno"></label>
        <p id="categoriaAluno" aria-live="polite"></p>
        <label>Responsável<select required name="cod_responsavel"><option value="">Selecione</option><?php foreach ($responsaveis as $r): ?><option value="<?= (int) $r['COD_RESPONSAVEL'] ?>" <?= ($edit['COD_RESPONSAVEL'] ?? '') == $r['COD_RESPONSAVEL'] ? 'selected' : '' ?>><?= e($r['NOME']) ?></option><?php endforeach; ?></select></label>
        <label>Posição<input name="posicao" value="<?= $value('POSICAO', 'Meio-Campo') ?>"></label>
        <label>Número da camisa<input type="number" name="numero_camisa" min="0" max="99" value="<?= e($edit['NUMERO_CAMISA'] ?? '') ?>"></label>
        <label>Situação<select name="status"><?php foreach (['ATIVO', 'INATIVO', 'PENDENTE'] as $status): ?><option <?= ($edit['STATUS'] ?? 'ATIVO') === $status ? 'selected' : '' ?>><?= $status ?></option><?php endforeach; ?></select></label>
        <label id="campoTurmaAluno">Turma<select name="cod_turma" id="turmaAluno" required><option value="">Informe a data de nascimento</option></select></label>
        <p id="horarioTurmaAluno" aria-live="polite"></p>
        <p id="criarTurmaAluno" hidden><a href="turmas.php" target="_top">Ir para Turmas e cadastrar uma turma</a></p>
        <fieldset class="acesso-aluno"><legend>Acesso do aluno</legend><p>Opcional. O aluno poderá entrar com este e-mail e acompanhar seu perfil e criar metas.</p><label>E-mail de acesso<input type="email" name="email_acesso" value="<?= e($edit['EMAIL_ACESSO'] ?? '') ?>" autocomplete="off"></label><label><?= !empty($edit['EMAIL_ACESSO']) ? 'Nova senha (deixe vazia para manter)' : 'Senha inicial' ?><input type="password" name="senha_acesso" minlength="6" autocomplete="new-password" <?= empty($edit['EMAIL_ACESSO']) ? '' : '' ?>></label></fieldset>
        <label>Observações médicas<textarea name="observacoes_medicas"><?= $value('OBSERVACOES_MEDICAS') ?></textarea></label>
        <div><?php actionButton($edit ? 'Salvar alterações' : 'Cadastrar aluno'); ?><?php if ($edit): ?><a href="alunos.php">Cancelar</a><?php endif; ?></div>
    </form>
    <script nonce="<?= cspNonce() ?>">
        (() => {
            const nascimento = document.getElementById('nascimentoAluno');
            const saida = document.getElementById('categoriaAluno');
            const turma = document.getElementById('turmaAluno');
            const situacao = nascimento.form.elements.status;
            const horario = document.getElementById('horarioTurmaAluno');
            const criarTurma = document.getElementById('criarTurmaAluno');
            let turmaAnterior = '<?= (int) ($edit['TURMA_ALUNO'] ?? 0) ?>';
            const categorias = <?= json_encode($categoriasTurmas, JSON_HEX_TAG | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
            const [anoAtual, mesAtual, diaAtual] = nascimento.max.split('-').map(Number);

            function atualizarCategoria() {
                const dataValida = nascimento.value && nascimento.validity.valid;
                let idade = null;
                let compativeis = [];
                if (dataValida) {
                    const [ano, mes, dia] = nascimento.value.split('-').map(Number);
                    const fezAniversario = mes < mesAtual || (mes === mesAtual && dia <= diaAtual);
                    idade = anoAtual - ano - (fezAniversario ? 0 : 1);
                    compativeis = categorias.filter(([, , , minima, maxima]) => idade >= minima && idade <= maxima);
                }
                const opcoes = [...new Set(compativeis.map(([, , categoria]) => categoria))];
                saida.textContent = idade === null ? '' : `${idade} ${idade === 1 ? 'ano completo' : 'anos completos'}. ` + (opcoes.length
                    ? 'Categorias disponíveis: ' + opcoes.join(', ')
                    : 'Nenhuma turma ativa tem categoria compatível com a idade.');
                const anterior = turma.value || turmaAnterior;
                turma.required = situacao.value === 'ATIVO';
                turma.disabled = !turma.required;
                document.getElementById('campoTurmaAluno').hidden = turma.disabled;
                turma.replaceChildren(new Option('Selecione uma turma compatível', ''));
                for (const [id, nome, categoria] of compativeis) {
                    turma.add(new Option(nome + ' · ' + categoria, id));
                }
                if (compativeis.length === 1) {
                    turma.value = compativeis[0][0];
                } else if (compativeis.some(([id]) => String(id) === anterior)) {
                    turma.value = anterior;
                }
                criarTurma.hidden = !dataValida || compativeis.length > 0 || turma.disabled;
                atualizarHorario();
            }

            function atualizarHorario() {
                const selecionada = categorias.find(([id]) => String(id) === turma.value);
                horario.textContent = selecionada && !turma.disabled ? selecionada[5] + ' · ' + selecionada[6] : '';
            }

            turma.addEventListener('change', () => {
                turmaAnterior = turma.value;
                atualizarHorario();
            });
            nascimento.addEventListener('input', atualizarCategoria);
            nascimento.addEventListener('change', atualizarCategoria);
            situacao.addEventListener('change', atualizarCategoria);
            atualizarCategoria();
        })();
    </script>
    <?php endif; ?>
</section>
<?php else: ?>
<div class="alunos-acoes">
    <div><h2>Alunos cadastrados</h2><p>Cadastre e gerencie os alunos da escolinha.</p></div>
    <div class="alunos-botoes"><a class="acao-rapida" href="alunos.php?mensalidades=1">Mensalidades</a>
        <button type="button" class="acao-rapida" data-url="alunos.php?mini=1" data-titulo="Novo aluno"><span>＋</span>Novo aluno</button>
        <button type="button" class="acao-rapida" data-url="camisas.php?mini=1" data-titulo="Camisas dos alunos"><span>👕</span>Camisas</button>
    </div>
</div>
<section class="lista-alunos"><div class="tabela-alunos"><table><thead><tr><th>Aluno</th><th>Responsável</th><th>Situação</th><th>Turmas</th><th>Ações</th></tr></thead><tbody><?php foreach ($lista as $aluno): ?><tr><td><?= e($aluno['NOME']) ?></td><td><?= e($aluno['RESPONSAVEL']) ?></td><td><?= e($aluno['STATUS']) ?></td><td><?= e($aluno['TURMAS_ATIVAS'] ?: 'Sem turma') ?></td><td><button type="button" class="acao-tabela" data-url="alunos.php?mini=1&amp;editar=<?= (int) $aluno['COD_ALUNO'] ?>" data-titulo="Editar aluno">Editar</button><?php if ($aluno['STATUS'] !== 'INATIVO'): ?>
<form method="post" data-confirm-submit="Inativar este aluno e encerrar as matrículas ativas? O histórico será preservado.">
    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="action" value="inactivate">
    <input type="hidden" name="id" value="<?= (int) $aluno['COD_ALUNO'] ?>">
    <button type="submit">Inativar</button>
</form>
<?php endif; ?>
<form method="post" data-confirm-submit="Excluir definitivamente este aluno? Matrículas, presenças, avaliações, registros de jogos, metas, pagamentos e o acesso do aluno serão apagados."><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $aluno['COD_ALUNO'] ?>"><button>Excluir</button></form></td></tr><?php endforeach; ?><?php if (!$lista): ?><tr><td colspan="5">Nenhum aluno cadastrado.</td></tr><?php endif; ?></tbody></table></div></section>
<dialog class="modal-cadastro" id="modalAlunos" aria-labelledby="tituloModalAlunos">
    <header class="modal-cabecalho"><h2 id="tituloModalAlunos">Alunos</h2><button type="button" class="fechar-modal" id="fecharModalAlunos" aria-label="Fechar">×</button></header>
    <iframe id="iframeAlunos" title="Cadastro de aluno ou gestão de camisas"></iframe>
</dialog>
<script nonce="<?= cspNonce() ?>">
    const modalAlunos = document.getElementById('modalAlunos');
    const iframeAlunos = document.getElementById('iframeAlunos');
    const tituloModalAlunos = document.getElementById('tituloModalAlunos');

    document.querySelectorAll('[data-url]').forEach(function (botao) {
        botao.addEventListener('click', function () {
            iframeAlunos.src = botao.dataset.url;
            tituloModalAlunos.textContent = botao.dataset.titulo;
            modalAlunos.showModal();
        });
    });

    document.getElementById('fecharModalAlunos').addEventListener('click', function () {
        modalAlunos.close();
    });

    modalAlunos.addEventListener('close', function () {
        iframeAlunos.src = 'about:blank';
    });
    window.addEventListener('message', function (evento) {
        if (evento.origin !== window.location.origin || evento.source !== iframeAlunos.contentWindow
            || evento.data !== 'gestorfc:cadastro-salvo') return;
        modalAlunos.close();
        window.location.reload();
    });
</script>
<?php endif; ?>
<?php pageEnd(); ?>
