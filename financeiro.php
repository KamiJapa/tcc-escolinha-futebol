<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/config/database.php';
checkRole(['ADMIN', 'SECRETARIA']);
$db = getDB();
$escola = currentEscolinhaId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $acao = $_POST['acao'] ?? '';
    $id = positiveId($_POST['id'] ?? null);
    try {
        if ($acao !== 'criar' && !$id) throw new InvalidArgumentException('Informe um lançamento válido.');
        if (in_array($acao, ['criar','editar'], true)) {
            $tipo = $_POST['tipo'] ?? '';
            $categoria = $_POST['categoria'] ?? '';
            $descricao = trim(postString('descricao'));
            $valor = str_replace(',', '.', trim(postString('valor')));
            $vencimento = $_POST['vencimento'] ?? '';
            $alunoTexto = $_POST['aluno'] ?? '';
            $aluno = positiveId($alunoTexto);
            if ($alunoTexto !== '' && !$aluno) throw new InvalidArgumentException('Aluno inválido.');
            $mes = postString('competencia');
            $competencia = $categoria === 'MENSALIDADE' ? $mes . '-01' : null;
            if (!in_array($tipo, ['RECEITA','DESPESA'], true) || !in_array($categoria, ['MENSALIDADE','MATRICULA','UNIFORME','OUTROS'], true)
                || $descricao === '' || mb_strlen($descricao) > 150 || !preg_match('/^\d{1,8}(\.\d{1,2})?$/', $valor) || (float) $valor <= 0
                || !validDate($vencimento) || ($categoria === 'MENSALIDADE' && (!$aluno || $tipo !== 'RECEITA' || !validDate($competencia)))) {
                throw new InvalidArgumentException('Confira descrição, valor, vencimento e competência. Mensalidades exigem aluno e tipo receita.');
            }
            if ($aluno) {
                $q = $db->prepare('SELECT 1 FROM TB_ALUNO WHERE COD_ALUNO=? AND COD_ESCOLINHA=?');
                $q->execute([$aluno, $escola]);
                if (!$q->fetchColumn()) throw new InvalidArgumentException('Aluno inválido.');
            }
            if ($acao === 'editar') {
                $q = $db->prepare("UPDATE TB_FINANCEIRO SET COD_ALUNO=?,TIPO=?,CATEGORIA=?,DESCRICAO=?,COMPETENCIA=?,VALOR=?,VENCIMENTO=? WHERE COD_LANCAMENTO=? AND COD_ESCOLINHA=? AND STATUS='PENDENTE'");
                $q->execute([$aluno ?: null,$tipo,$categoria,$descricao,$competencia,$valor,$vencimento,$id,$escola]);
                if (!$q->rowCount()) throw new InvalidArgumentException('Sem alterações ou lançamento indisponível. Só é possível editar lançamentos pendentes.');
                flash('success','Lançamento atualizado.');
            } else {
                $q = $db->prepare('INSERT INTO TB_FINANCEIRO (COD_ESCOLINHA,COD_ALUNO,TIPO,CATEGORIA,DESCRICAO,COMPETENCIA,VALOR,VENCIMENTO) VALUES (?,?,?,?,?,?,?,?)');
                $q->execute([$escola,$aluno ?: null,$tipo,$categoria,$descricao,$competencia,$valor,$vencimento]);
                flash('success','Lançamento registrado.');
            }
        } elseif (in_array($acao, ['pagar','reabrir','cancelar'], true)) {
            $data = $acao === 'pagar' ? ($_POST['data_pagamento'] ?? '') : null;
            if ($acao === 'pagar' && (!validDate($data) || $data > date('Y-m-d'))) throw new InvalidArgumentException('Informe uma data de pagamento válida, até hoje.');
            $novo = ['pagar'=>'PAGO','reabrir'=>'PENDENTE','cancelar'=>'CANCELADO'][$acao];
            $anterior = ['pagar'=>'PENDENTE','reabrir'=>'PAGO','cancelar'=>'PENDENTE'][$acao];
            if ($acao==='reabrir' && ($_POST['cancelado'] ?? '')==='1') $anterior='CANCELADO';
            $q = $db->prepare('UPDATE TB_FINANCEIRO SET STATUS=?,DATA_PAGAMENTO=? WHERE COD_LANCAMENTO=? AND COD_ESCOLINHA=? AND STATUS=?');
            $q->execute([$novo,$data,$id,$escola,$anterior]);
            if (!$q->rowCount()) throw new InvalidArgumentException('Lançamento não encontrado ou já atualizado. Recarregue a página.');
            flash('success','Lançamento atualizado.');
        } else {
            throw new InvalidArgumentException('Ação inválida.');
        }
    } catch (InvalidArgumentException $error) {
        flash('error', $error->getMessage());
    } catch (PDOException $error) {
        error_log('GestorFC: falha ao gravar lançamento financeiro.');
        flash('error', $error->getCode() === '23000'
            ? 'A mensalidade deste aluno para esta competência já existe.'
            : 'Não foi possível salvar o lançamento. Confira os dados e tente novamente.');
    }
    go('financeiro.php');
}
$edit = null;
$editId = positiveId($_GET['editar'] ?? null);
if ($editId) {
    $q = $db->prepare("SELECT * FROM TB_FINANCEIRO WHERE COD_LANCAMENTO=? AND COD_ESCOLINHA=? AND STATUS='PENDENTE'");
    $q->execute([$editId,$escola]);
    $edit=$q->fetch();
    if (!$edit) { flash('error','Lançamento não disponível para edição.'); go('financeiro.php'); }
}
$mes = is_string($_GET['mes'] ?? null) ? $_GET['mes'] : date('Y-m');
if (!is_string($mes) || !validDate($mes . '-01')) $mes = date('Y-m');
$inicio = $mes . '-01';
$fim = date('Y-m-d', strtotime($inicio . ' +1 month'));
$situacao = is_string($_GET['situacao'] ?? null) ? $_GET['situacao'] : '';
if (!in_array($situacao, ['', 'PENDENTE','PAGO','CANCELADO','ATRASADO'], true)) $situacao = '';
$q = $db->prepare('SELECT f.*,a.NOME AS ALUNO FROM TB_FINANCEIRO f LEFT JOIN TB_ALUNO a ON a.COD_ALUNO=f.COD_ALUNO AND a.COD_ESCOLINHA=f.COD_ESCOLINHA WHERE f.COD_ESCOLINHA=? AND f.VENCIMENTO>=? AND f.VENCIMENTO<? ORDER BY f.VENCIMENTO,f.COD_LANCAMENTO DESC');
$q->execute([$escola,$inicio,$fim]);
$todos = $q->fetchAll();
$recebido = $despesas = $pendente = 0;
foreach ($todos as $item) {
    if ($item['STATUS'] === 'PAGO') {
        if ($item['TIPO'] === 'RECEITA') $recebido += (int) round((float) $item['VALOR'] * 100);
        else $despesas += (int) round((float) $item['VALOR'] * 100);
    } elseif ($item['STATUS'] === 'PENDENTE' && $item['TIPO'] === 'RECEITA') $pendente += (int) round((float) $item['VALOR'] * 100);
}
$lista = array_filter($todos, static function ($item) use ($situacao) {
    if ($situacao === 'ATRASADO') return $item['STATUS'] === 'PENDENTE' && $item['VENCIMENTO'] < date('Y-m-d');
    return $situacao === '' || $item['STATUS'] === $situacao;
});
$q = $db->prepare('SELECT COD_ALUNO,NOME FROM TB_ALUNO WHERE COD_ESCOLINHA=? ORDER BY NOME');
$q->execute([$escola]);
$alunos = $q->fetchAll();
function reais($centavos) { return 'R$ ' . number_format($centavos / 100, 2, ',', '.'); }
pageStart('Financeiro');
?>
<div class="financeiro">
<p>Mensalidades, receitas e despesas da escolinha. Baixas manuais, sem cobrança bancária automática.</p>
<form method="get" class="financeiro-filtros"><label>Mês de vencimento<input type="month" name="mes" value="<?= e($mes) ?>" required></label><label>Situação<select name="situacao"><?php foreach ([''=>'Todas','PENDENTE'=>'Pendente','PAGO'=>'Pago','ATRASADO'=>'Atrasado','CANCELADO'=>'Cancelado'] as $valor=>$rotulo): ?><option value="<?= e($valor) ?>" <?= $situacao===$valor?'selected':'' ?>><?= e($rotulo) ?></option><?php endforeach; ?></select></label><button type="submit">Filtrar</button></form>
<dl class="financeiro-resumo"><div><dt>Receitas recebidas</dt><dd><?= reais($recebido) ?></dd></div><div><dt>Despesas pagas</dt><dd><?= reais($despesas) ?></dd></div><div><dt>Saldo liquidado</dt><dd><?= reais($recebido-$despesas) ?></dd></div><div><dt>Receitas pendentes</dt><dd><?= reais($pendente) ?></dd></div></dl>
<p><small>Totais dos lançamentos com vencimento no mês selecionado, independentemente da data da baixa e do filtro de situação.</small></p>
<details class="financeiro-novo" <?= $edit ? 'open' : '' ?>><summary><?= $edit ? 'Editar lançamento' : 'Novo lançamento' ?></summary><form method="post" class="financeiro-form">
<input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="acao" value="<?= $edit ? 'editar' : 'criar' ?>"><input type="hidden" name="id" value="<?= (int)($edit['COD_LANCAMENTO'] ?? 0) ?>">
<label>Tipo<select name="tipo"><option value="RECEITA" <?= ($edit['TIPO'] ?? '')==='RECEITA' ? 'selected' : '' ?>>Receita</option><option value="DESPESA" <?= ($edit['TIPO'] ?? '')==='DESPESA' ? 'selected' : '' ?>>Despesa</option></select></label>
<label>Categoria<select name="categoria"><option value="MENSALIDADE" <?= ($edit['CATEGORIA'] ?? '')==='MENSALIDADE' ? 'selected' : '' ?>>Mensalidade</option><option value="MATRICULA" <?= ($edit['CATEGORIA'] ?? '')==='MATRICULA' ? 'selected' : '' ?>>Matrícula</option><option value="UNIFORME" <?= ($edit['CATEGORIA'] ?? '')==='UNIFORME' ? 'selected' : '' ?>>Uniforme</option><option value="OUTROS" <?= ($edit['CATEGORIA'] ?? '')==='OUTROS' ? 'selected' : '' ?>>Outros</option></select></label>
<label>Aluno<select name="aluno"><option value="">Sem aluno</option><?php foreach ($alunos as $aluno): ?><option value="<?= (int)$aluno['COD_ALUNO'] ?>" <?= ($edit['COD_ALUNO'] ?? 0)==$aluno['COD_ALUNO'] ? 'selected' : '' ?>><?= e($aluno['NOME']) ?></option><?php endforeach; ?></select></label>
<label>Competência da mensalidade<input type="month" name="competencia" value="<?= e(!empty($edit['COMPETENCIA']) ? substr($edit['COMPETENCIA'],0,7) : $mes) ?>"></label>
<label>Descrição<input name="descricao" maxlength="150" required value="<?= e($edit['DESCRICAO'] ?? '') ?>"></label><label>Valor (R$)<input name="valor" value="<?= e($edit['VALOR'] ?? '') ?>" type="number" step="0.01" min="0.01" max="99999999.99" required></label><label>Vencimento<input name="vencimento" type="date" required value="<?= e($edit['VENCIMENTO'] ?? date('Y-m-d')) ?>"></label>
<div><?php actionButton($edit ? 'Salvar alterações' : 'Registrar lançamento'); ?></div></form></details>
<div class="financeiro-tabela" tabindex="0" aria-label="Lançamentos financeiros"><table><thead><tr><th>Descrição / Aluno</th><th>Tipo</th><th>Vencimento</th><th>Valor</th><th>Situação</th><th>Ações</th></tr></thead><tbody>
<?php foreach ($lista as $item): ?><tr><td><?= e($item['DESCRICAO']) ?><br><small><?= e($item['ALUNO'] ?: 'Sem aluno vinculado') ?><?= $item['COMPETENCIA'] ? ' · '.e(date('m/Y',strtotime($item['COMPETENCIA']))) : '' ?></small></td><td><?= e($item['TIPO']) ?></td><td><?= e(date('d/m/Y',strtotime($item['VENCIMENTO']))) ?></td><td><?= reais((int)round((float)$item['VALOR']*100)) ?></td><td><?= $item['STATUS']==='PENDENTE' && $item['VENCIMENTO']<date('Y-m-d') ? 'ATRASADO' : e($item['STATUS']) ?><?php if ($item['DATA_PAGAMENTO']): ?><br><small>Baixa: <?= e(date('d/m/Y',strtotime($item['DATA_PAGAMENTO']))) ?></small><?php endif; ?></td><td>
<form method="post" class="financeiro-baixa"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="id" value="<?= (int)$item['COD_LANCAMENTO'] ?>"><?php if ($item['STATUS']==='PENDENTE'): ?><a href="financeiro.php?editar=<?= (int)$item['COD_LANCAMENTO'] ?>">Editar</a><label>Data da baixa<input type="date" name="data_pagamento" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required></label><button name="acao" value="pagar">Registrar pagamento</button><button name="acao" value="cancelar" formnovalidate data-confirm-click="Cancelar este lançamento?">Cancelar</button><?php elseif ($item['STATUS']==='PAGO'): ?><button name="acao" value="reabrir" data-confirm-click="Desfazer a baixa e tornar este lançamento pendente?">Desfazer baixa</button><?php else: ?><input type="hidden" name="cancelado" value="1"><button name="acao" value="reabrir" data-confirm-click="Reabrir este lançamento cancelado como pendente?">Reabrir lançamento</button><?php endif; ?></form>
</td></tr><?php endforeach; ?><?php if (!$lista): ?><tr><td colspan="6">Nenhum lançamento para estes filtros.</td></tr><?php endif; ?></tbody></table></div></div>
<?php pageEnd(); ?>
