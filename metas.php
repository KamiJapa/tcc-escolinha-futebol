<?php
require_once __DIR__ . '/includes/layout.php';
// Visão individual com vínculo validado no servidor.
if (hasRole(['RESPONSAVEL'])) {
    checkLogin();
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(403); exit('Acesso somente para consulta.'); }
    header('Location: area_responsavel.php#metas');
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/avaliacao_helpers.php';
require_once __DIR__ . '/includes/destaques_helpers.php';
checkRole(['ADMIN', 'SECRETARIA', 'PROFESSOR', 'RESPONSAVEL', 'ALUNO']);

$db = getDB();
$escolinha = currentEscolinhaId();
$usuario = (int) $_SESSION['user_id'];
$perfil = $_SESSION['user_role'];
$catalogo = avaliacaoCatalogoAtributos();
$atletas = [];
$podeCriarMetas = $perfil === 'ALUNO';

if ($perfil === 'ALUNO') {
    $q = $db->prepare('SELECT COD_ALUNO, NOME FROM TB_ALUNO WHERE COD_USUARIO = ? AND COD_ESCOLINHA = ?');
    $q->execute([$usuario, $escolinha]);
    $atletas = $q->fetchAll();
} elseif ($perfil === 'RESPONSAVEL') {
    $q = $db->prepare('SELECT COD_ALUNO, NOME FROM TB_ALUNO WHERE COD_RESPONSAVEL = (SELECT COD_RESPONSAVEL FROM TB_RESPONSAVEL WHERE COD_USUARIO = ? AND COD_ESCOLINHA = ?) AND COD_ESCOLINHA = ? ORDER BY NOME');
    $q->execute([$usuario, $escolinha, $escolinha]);
    $atletas = $q->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    if (!$podeCriarMetas || !$atletas) { http_response_code(403); exit('Apenas o aluno pode gerenciar suas próprias metas.'); }
    $alunoId = (int) $atletas[0]['COD_ALUNO'];
    $acao = $_POST['acao'] ?? '';
    $metaId = (int) ($_POST['meta'] ?? 0);
    if ($acao === 'excluir') {
        $q = $db->prepare('DELETE FROM TB_META_ATLETA WHERE COD_META = ? AND COD_ALUNO = ? AND CRIADO_POR = ?');
        $q->execute([$metaId, $alunoId, $usuario]);
        flash('success', $q->rowCount() ? 'Meta removida.' : 'Meta não encontrada.');
        go('metas.php');
    }
    $tipo = $_POST['tipo'] ?? '';
    $atributo = $_POST['atributo'] ?? null;
    $alvo = filter_var($_POST['valor_alvo'] ?? null, FILTER_VALIDATE_FLOAT);
    $tiposValidos = ['OVERALL', 'ATRIBUTO', 'GOLS', 'ASSISTENCIAS'];
    $tipoValido = in_array($tipo, $tiposValidos, true) && ($tipo !== 'ATRIBUTO' || isset($catalogo[$atributo]));
    $maximo = in_array($tipo, ['OVERALL', 'ATRIBUTO'], true) ? 10 : 9999;
    $integral = in_array($tipo, ['GOLS', 'ASSISTENCIAS'], true);
    if (!$tipoValido || $alvo === false || $alvo <= 0 || $alvo > $maximo || ($integral && floor($alvo) !== $alvo)) {
        flash('error', 'Revise o tipo de meta e informe um objetivo válido.');
        go('metas.php');
    }
    if ($tipo !== 'ATRIBUTO') $atributo = null;
    if ($acao === 'editar') {
        $q = $db->prepare('UPDATE TB_META_ATLETA SET TIPO = ?, ATRIBUTO = ?, VALOR_ALVO = ? WHERE COD_META = ? AND COD_ALUNO = ? AND CRIADO_POR = ?');
        $q->execute([$tipo, $atributo, $alvo, $metaId, $alunoId, $usuario]);
        flash('success', $q->rowCount() ? 'Meta atualizada.' : 'Meta sem alterações ou não encontrada.');
    } else {
        $q = $db->prepare('INSERT INTO TB_META_ATLETA (COD_ALUNO, CRIADO_POR, TIPO, ATRIBUTO, VALOR_ALVO) VALUES (?, ?, ?, ?, ?)');
        $q->execute([$alunoId, $usuario, $tipo, $atributo, $alvo]);
        flash('success', 'Meta criada.');
    }
    go('metas.php');
}

$resumoPorAtleta = [];
foreach ($atletas as $atleta) {
    $id = (int) $atleta['COD_ALUNO'];
    $q = $db->prepare('SELECT NOTA_GERAL, CRITERIOS_JSON FROM TB_AVALIACAO WHERE COD_ALUNO = ? ORDER BY DATA_AVALIACAO DESC, COD_AVALIACAO DESC');
    $q->execute([$id]);
    $overall = null;
    $atributos = [];
    foreach ($q->fetchAll() as $avaliacao) {
        if ($overall === null && $avaliacao['NOTA_GERAL'] !== null) $overall = (float) $avaliacao['NOTA_GERAL'];
        $criterios = json_decode($avaliacao['CRITERIOS_JSON'], true);
        if (is_array($criterios)) foreach ($criterios as $nome => $nota) {
            $nome = strtolower((string) $nome);
            if (isset($catalogo[$nome]) && is_numeric($nota)) { $atributos[$nome]['soma'] = ($atributos[$nome]['soma'] ?? 0) + (float) $nota; $atributos[$nome]['n'] = ($atributos[$nome]['n'] ?? 0) + 1; }
        }
    }
    $q = $db->prepare('SELECT SUM(ja.GOLS), SUM(ja.ASSISTENCIAS), COUNT(*) FROM TB_JOGO_ATLETA ja JOIN TB_JOGO j ON j.COD_JOGO = ja.COD_JOGO WHERE ja.COD_ALUNO = ? AND ja.PARTICIPOU = 1 AND j.DATA_JOGO <= CURDATE()');
    $q->execute([$id]);
    $stats = $q->fetch(PDO::FETCH_NUM);
    $resumoPorAtleta[$id] = ['OVERALL' => $overall, 'ATRIBUTO' => $atributos, 'GOLS' => (int) $stats[2] ? (int) $stats[0] : null, 'ASSISTENCIAS' => (int) $stats[2] ? (int) $stats[1] : null, 'JOGOS' => (int) $stats[2]];
}

$metasPorAtleta = [];
if ($atletas) {
    $ids = array_map(static function ($atleta) { return (int) $atleta['COD_ALUNO']; }, $atletas);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $q = $db->prepare("SELECT * FROM TB_META_ATLETA WHERE COD_ALUNO IN ($placeholders) ORDER BY CRIADO_EM DESC, COD_META DESC");
    $q->execute($ids);
    foreach ($q->fetchAll() as $meta) $metasPorAtleta[(int) $meta['COD_ALUNO']][] = $meta;
}

$destaques = buscarDestaquesSemanais($db, $escolinha, $perfil, $usuario, (int) ($responsavelId ?? 0));
if ($perfil === 'RESPONSAVEL') {
    $q = $db->prepare('SELECT COD_RESPONSAVEL FROM TB_RESPONSAVEL WHERE COD_USUARIO = ? AND COD_ESCOLINHA = ?');
    $q->execute([$usuario, $escolinha]);
    $responsavelId = (int) $q->fetchColumn();
    $destaques = buscarDestaquesSemanais($db, $escolinha, $perfil, $usuario, $responsavelId);
}

function metaRotulo($meta, $catalogo) {
    if ($meta['TIPO'] === 'OVERALL') return 'Overall';
    if ($meta['TIPO'] === 'ATRIBUTO') return $catalogo[$meta['ATRIBUTO']] ?? 'Atributo';
    return $meta['TIPO'] === 'GOLS' ? 'Gols' : 'Assistências';
}
function metaValorAtual($meta, $resumo, $catalogo) {
    if ($meta['TIPO'] === 'ATRIBUTO') {
        $atributo = $resumo['ATRIBUTO'][$meta['ATRIBUTO']] ?? null;
        return $atributo && $atributo['n'] ? $atributo['soma'] / $atributo['n'] : null;
    }
    return $resumo[$meta['TIPO']] ?? null;
}

pageStart('Metas e destaques');
?>
<div class="metas-page">
    <header class="metas-hero"><div><p class="metas-kicker">CARREIRA DO ATLETA</p><h2>Objetivos claros. Evolução construída em campo.</h2><p>Defina metas pessoais e acompanhe os destaques calculados a partir das avaliações e súmulas da semana.</p></div><span aria-hidden="true">◎</span></header>

    <?php if ($podeCriarMetas && $atletas): ?>
    <section class="metas-criacao"><div class="metas-section-heading"><div><p class="metas-kicker">PRÓXIMO PASSO</p><h3>Minhas metas</h3></div><span>Os valores atuais vêm dos registros do sistema</span></div>
        <form method="post" class="metas-form" id="formMeta"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="acao" id="acaoMeta" value="criar"><input type="hidden" name="meta" id="idMeta" value="">
            <label>O que quer alcançar?<select name="tipo" id="tipoMeta" required><option value="OVERALL">Overall</option><option value="ATRIBUTO">Atributo</option><option value="GOLS">Gols</option><option value="ASSISTENCIAS">Assistências</option></select></label>
            <label id="campoAtributo" hidden>Atributo<select name="atributo" id="atributoMeta"><?php foreach ($catalogo as $chave => $nome): ?><option value="<?= e($chave) ?>"><?= e($nome) ?></option><?php endforeach; ?></select></label>
            <label>Objetivo <span id="sufixoMeta">/10</span><input type="number" name="valor_alvo" id="valorMeta" min="0.1" max="10" step="0.1" required></label>
            <button class="metas-botao" type="submit" id="salvarMeta">＋ Criar meta</button><button class="metas-cancelar" type="button" id="cancelarEdicao" hidden>Cancelar</button>
        </form>
        <?php $aluno = $atletas[0]; $id = (int) $aluno['COD_ALUNO']; $listaMetas = $metasPorAtleta[$id] ?? []; ?>
        <?php if ($listaMetas): ?><div class="metas-grid"><?php foreach ($listaMetas as $meta): $atual = metaValorAtual($meta, $resumoPorAtleta[$id], $catalogo); $alvo = (float) $meta['VALOR_ALVO']; $percentual = $atual === null ? 0 : min(100, max(0, $atual / $alvo * 100)); $concluida = $atual !== null && $atual >= $alvo; ?>
            <article class="meta-card"><div class="meta-card-top"><span class="meta-badge <?= $concluida ? 'concluida' : '' ?>"><?= $concluida ? 'Concluída' : ($atual === null ? 'Aguardando dados' : 'Em andamento') ?></span><span class="meta-tipo"><?= e(metaRotulo($meta, $catalogo)) ?></span></div><div class="meta-valores"><strong><?= $atual === null ? '—' : e(number_format($atual, in_array($meta['TIPO'], ['GOLS','ASSISTENCIAS'], true) ? 0 : 1, ',', '.')) ?></strong><span>de <?= e(number_format($alvo, in_array($meta['TIPO'], ['GOLS','ASSISTENCIAS'], true) ? 0 : 1, ',', '.')) ?></span></div><div class="meta-barra"><i style="width: <?= e(number_format($percentual, 2, '.', '')) ?>%"></i></div><div class="meta-card-bottom"><span><?= $atual === null ? 'Ainda sem registros' : e(number_format($percentual, 0, ',', '.')) . '% do objetivo' ?></span><div><button type="button" class="meta-editar" data-meta="<?= (int) $meta['COD_META'] ?>" data-tipo="<?= e($meta['TIPO']) ?>" data-atributo="<?= e($meta['ATRIBUTO'] ?? '') ?>" data-alvo="<?= e($meta['VALOR_ALVO']) ?>">Editar</button><form method="post" onsubmit="return confirm('Remover esta meta?')"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="acao" value="excluir"><input type="hidden" name="meta" value="<?= (int) $meta['COD_META'] ?>"><button class="meta-editar" type="submit">Remover</button></form></div></div></article>
        <?php endforeach; ?></div><?php else: ?><div class="metas-vazio">Você ainda não criou uma meta. Escolha um objetivo e acompanhe seu progresso aqui.</div><?php endif; ?>
    </section>
    <?php elseif ($perfil === 'RESPONSAVEL' && $atletas): ?><section class="metas-criacao"><div class="metas-section-heading"><div><p class="metas-kicker">CARREIRA</p><h3>Metas dos atletas</h3></div><span>Metas definidas pelo próprio aluno</span></div><?php foreach ($atletas as $aluno): $id = (int) $aluno['COD_ALUNO']; ?><h4 class="metas-nome-atleta"><?= e($aluno['NOME']) ?></h4><div class="metas-grid"><?php foreach ($metasPorAtleta[$id] ?? [] as $meta): $atual = metaValorAtual($meta, $resumoPorAtleta[$id], $catalogo); $alvo = (float) $meta['VALOR_ALVO']; $percentual = $atual === null ? 0 : min(100, max(0, $atual / $alvo * 100)); ?><article class="meta-card"><div class="meta-card-top"><span class="meta-badge"><?= $atual !== null && $atual >= $alvo ? 'Concluída' : ($atual === null ? 'Aguardando dados' : 'Em andamento') ?></span><span class="meta-tipo"><?= e(metaRotulo($meta, $catalogo)) ?></span></div><div class="meta-valores"><strong><?= $atual === null ? '—' : e(number_format($atual, 1, ',', '.')) ?></strong><span>de <?= e(number_format($alvo, 1, ',', '.')) ?></span></div><div class="meta-barra"><i style="width:<?= e(number_format($percentual, 2, '.', '')) ?>%"></i></div></article><?php endforeach; if (empty($metasPorAtleta[$id])): ?><p class="metas-vazio">Nenhuma meta cadastrada por este aluno.</p><?php endif; ?></div><?php endforeach; ?></section><?php endif; ?>

    <section class="destaques-semana"><div class="metas-section-heading"><div><p class="metas-kicker"><?= e(date('d/m', strtotime($destaques['inicio']))) ?> · SEMANA ATUAL</p><h3>Destaques da semana</h3></div><span>Indicadores objetivos<?= $perfil === 'PROFESSOR' ? ' das suas turmas' : ($perfil === 'ALUNO' || $perfil === 'RESPONSAVEL' ? ' relacionados aos atletas vinculados' : ' por turma') ?></span></div>
        <?php $tiposDestaque = [['evolucao','↗','Maior evolução'],['gols','⚽','Artilharia'],['assistencias','✳','Assistências']]; ?><div class="destaques-grid">
        <?php foreach ($tiposDestaque as [$chave,$icone,$titulo]): ?><article class="destaque-card"><div class="destaque-heading"><span class="destaque-icon"><?= $icone ?></span><div><small>POR TURMA/CATEGORIA</small><h4><?= e($titulo) ?></h4></div></div>
            <?php if (!$destaques[$chave]): ?><p class="destaque-vazio">Sem dados suficientes registrados nesta semana.</p><?php else: foreach ($destaques[$chave] as $grupo): ?><div class="destaque-grupo"><span class="destaque-turma"><?= e($grupo['turma']) ?><?= $grupo['categoria'] ? ' · ' . e($grupo['categoria']) : '' ?></span><?php foreach ($grupo['atletas'] as $destaque): ?><div class="destaque-atleta"><span class="destaque-avatar"><?= e(mb_strtoupper(mb_substr($destaque['ATLETA'], 0, 1, 'UTF-8'), 'UTF-8')) ?></span><strong><?= e($destaque['ATLETA']) ?></strong><b><?php if ($chave === 'evolucao'): ?>+<?= e(number_format($destaque['VARIACAO'], 1, ',', '.')) ?> pts<?php else: ?><?= (int) $grupo['valor'] ?> <?= $chave === 'gols' ? 'gols' : 'ass.' ?><?php endif; ?></b></div><?php endforeach; ?></div><?php endforeach; endif; ?></article><?php endforeach; ?></div>
    </section>
</div>
<?php if ($podeCriarMetas && $atletas): ?><script>
(function(){const tipo=document.getElementById('tipoMeta'),campo=document.getElementById('campoAtributo'),valor=document.getElementById('valorMeta'),sufixo=document.getElementById('sufixoMeta'),form=document.getElementById('formMeta'),acao=document.getElementById('acaoMeta'),id=document.getElementById('idMeta'),salvar=document.getElementById('salvarMeta'),cancelar=document.getElementById('cancelarEdicao');function atualizar(){const escala=['GOLS','ASSISTENCIAS'].includes(tipo.value);campo.hidden=tipo.value!=='ATRIBUTO';valor.max=escala?'9999':'10';valor.step=escala?'1':'0.1';sufixo.textContent=escala?'total':'/10'}tipo.addEventListener('change',atualizar);atualizar();document.querySelectorAll('.meta-editar[data-meta]').forEach(b=>b.addEventListener('click',()=>{tipo.value=b.dataset.tipo;document.getElementById('atributoMeta').value=b.dataset.atributo;valor.value=b.dataset.alvo;id.value=b.dataset.meta;acao.value='editar';salvar.textContent='Salvar meta';cancelar.hidden=false;atualizar();form.scrollIntoView({behavior:'smooth',block:'center'})}));cancelar.addEventListener('click',()=>{form.reset();id.value='';acao.value='criar';salvar.textContent='＋ Criar meta';cancelar.hidden=true;atualizar()})}());
</script><?php endif; ?>
<?php pageEnd(); ?>
