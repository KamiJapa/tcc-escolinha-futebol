<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/avaliacao_helpers.php';
require_once __DIR__ . '/includes/responsavel_helpers.php';
require_once __DIR__ . '/config/database.php';
checkRole(['RESPONSAVEL']);
header('Cache-Control: no-store, private');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); header('Allow: GET'); exit('Esta área é somente para consulta.'); }
$db = getDB();
$filhos = responsavelFilhos($db, (int) $_SESSION['user_id'], currentEscolinhaId());
$selecionado = isset($_GET['aluno']) ? filter_var($_GET['aluno'], FILTER_VALIDATE_INT) : (int) ($filhos[0]['COD_ALUNO'] ?? 0);
$filho = null;
foreach ($filhos as $item) if ((int) $item['COD_ALUNO'] === $selecionado) $filho = $item;
if (isset($_GET['aluno']) && !$filho) { http_response_code(404); exit('Atleta não encontrado.'); }
$dados = $filho ? responsavelAcompanhamento($db, $filho) : null;
$q = $db->prepare('SELECT TITULO, DESCRICAO, LIDA, CRIADO_EM FROM TB_NOTIFICACAO WHERE COD_ESCOLINHA = ? AND COD_USUARIO = ? ORDER BY CRIADO_EM DESC, COD_NOTIFICACAO DESC LIMIT 4');
$q->execute([currentEscolinhaId(), (int) $_SESSION['user_id']]);
$avisos = $q->fetchAll();
function familiaNota($valor) { return $valor === null ? '—' : number_format($valor, 1, ',', '.'); }
function familiaData($data) { return date('d/m/Y', strtotime($data)); }
pageStart('Meus filhos');
?>
<div class="familia">
    <header class="familia-heading"><div><h2>Acompanhe seu filho</h2><p>Cada treino, uma nova conquista. Veja como ele está evoluindo.</p></div><a href="notificacoes.php">Ver notificações →</a></header>
    <?php if (!$filho): ?>
        <section class="familia-card"><h3>Nenhum atleta vinculado</h3><p>Peça à secretaria da sua escolinha para vincular seu cadastro ao do seu filho.</p></section>
    <?php else: ?>
    <form method="get" class="familia-selector"><label for="filho">Atleta que você quer acompanhar</label><div><select id="filho" name="aluno"><?php foreach ($filhos as $item): ?><option value="<?= (int) $item['COD_ALUNO'] ?>" <?= (int) $item['COD_ALUNO'] === $selecionado ? 'selected' : '' ?>><?= e($item['NOME']) ?></option><?php endforeach; ?></select><button class="botao-principal" type="submit">Acompanhar</button></div></form>
    <section class="familia-profile" aria-label="Perfil do atleta">
        <span class="familia-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($filho['NOME'], 0, 1))) ?></span>
        <div class="familia-identity"><h2><?= e($filho['NOME']) ?></h2><p><?= e($filho['POSICAO']) ?> · <?= (new DateTimeImmutable($filho['DATA_NASCIMENTO']))->diff(new DateTimeImmutable())->y ?> anos · <?= e($filho['STATUS']) ?></p><p><?= e(implode(' · ', array_column($dados['turmas'], 'NOME')) ?: 'Sem turma ativa') ?></p></div>
        <div class="familia-shirt"><span>Camisa</span><strong><?= $filho['NUMERO_CAMISA'] === null ? '—' : '#' . (int) $filho['NUMERO_CAMISA'] ?></strong></div>
        <div class="familia-overall"><span>Overall</span><strong><?= familiaNota($dados['overall']) ?><small>/10</small></strong><span><?= $dados['notas'] ? 'Avaliação de ' . familiaData($dados['notas'][0]['DATA_AVALIACAO']) : 'Aguardando avaliação' ?></span></div>
    </section>
    <nav class="familia-shortcuts" aria-label="Acompanhamento do atleta"><?php foreach (['treinos'=>'Treinos', 'evolucao'=>'Evolução', 'presenca'=>'Presença', 'jogos'=>'Jogos', 'metas'=>'Metas', 'observacoes'=>'Observações'] as $id=>$nome): ?><a href="#<?= $id ?>"><?= $nome ?></a><?php endforeach; ?></nav>
    <div class="familia-stats">
        <div><span>Presença</span><strong><?= $dados['frequencia'] === null ? '—' : $dados['frequencia'] . '%' ?></strong><small><?= $dados['presentes'] ?> de <?= count($dados['presencas']) ?> chamadas</small></div>
        <?php foreach (['JOGOS'=>'Jogos', 'GOLS'=>'Gols', 'ASSISTENCIAS'=>'Assistências'] as $chave=>$nome): ?><div><span><?= $nome ?></span><strong><?= $dados['stats'][$chave] ?? '—' ?></strong><small><?= $dados['stats']['JOGOS'] ? 'Participações registradas' : 'Sem participações registradas' ?></small></div><?php endforeach; ?>
    </div>
    <div class="familia-grid">
        <section class="familia-card familia-training" id="treinos"><h3>Próximos treinos</h3><p>Agenda das turmas ativas do atleta.</p>
            <?php if (!$dados['treinos']): ?><p class="familia-empty">Nenhum treino agendado. Consulte os horários regulares abaixo.</p><?php endif; ?>
            <?php foreach ($dados['treinos'] as $treino): ?><article class="familia-row"><time datetime="<?= e($treino['DATA_AULA']) ?>"><?= familiaData($treino['DATA_AULA']) ?><b><?= $treino['HORARIO'] ? e(substr($treino['HORARIO'], 0, 5)) : 'A confirmar' ?></b></time><div><strong><?= e($treino['TEMA_TREINO']) ?></strong><p><?= e($treino['TURMA']) ?></p></div></article><?php endforeach; ?>
            <?php if ($dados['turmas']): ?><details><summary>Horários regulares</summary><?php foreach ($dados['turmas'] as $turma): ?><p><strong><?= e($turma['NOME']) ?></strong><br><?= e($turma['DIAS_TREINO']) ?> · <?= e($turma['HORARIO']) ?></p><?php endforeach; ?></details><?php endif; ?>
        </section>
        <section class="familia-card" id="evolucao"><h3>Evolução e atributos</h3><p>Overall da última avaliação; atributos pela média das avaliações registradas.</p>
            <?php if (count($dados['notas']) > 1): $variacao = $dados['overall'] - (float) $dados['notas'][count($dados['notas']) - 1]['NOTA_GERAL']; ?><p class="familia-change"><?= $variacao > 0 ? '+' : '' ?><?= familiaNota($variacao) ?> pontos desde a primeira avaliação</p><?php endif; ?>
            <div class="familia-evolution"><?php foreach (array_reverse(array_slice($dados['notas'], 0, 5)) as $av): ?><div><strong><?= familiaNota((float) $av['NOTA_GERAL']) ?></strong><time><?= familiaData($av['DATA_AVALIACAO']) ?></time></div><?php endforeach; ?></div>
            <?php foreach (avaliacaoCatalogoAtributos() as $chave=>$nome): $nota = $dados['medias'][$chave] ?? null; ?><div class="familia-attribute"><div><span><?= e($nome) ?></span><strong><?= familiaNota($nota) ?> /10</strong></div><progress max="10" value="<?= $nota ?? 0 ?>" aria-label="<?= e($nome) ?>: <?= $nota === null ? 'sem avaliação' : familiaNota($nota) ?>"></progress></div><?php endforeach; ?>
            <?php if (!$dados['notas']): ?><p class="familia-empty">A evolução aparecerá quando houver avaliações com nota.</p><?php endif; ?>
        </section>
        <section class="familia-card" id="presenca"><h3>Presença</h3><p>Últimas 8 chamadas. O percentual considera todo o histórico.</p><?php if (!$dados['presencas']): ?><p class="familia-empty">Ainda não há chamadas registradas.</p><?php endif; ?>
            <?php foreach (array_slice($dados['presencas'], 0, 8) as $p): ?><div class="familia-row"><div><strong><?= familiaData($p['DATA_AULA']) ?></strong><p><?= e($p['TURMA']) ?></p><?php if ($p['JUSTIFICATIVA']): ?><p><?= e($p['JUSTIFICATIVA']) ?></p><?php endif; ?></div><span class="familia-status <?= $p['PRESENTE'] ? '' : 'familia-absent' ?>"><?= $p['PRESENTE'] ? 'Presente' : 'Ausente' ?></span></div><?php endforeach; ?>
        </section>
        <section class="familia-card" id="jogos"><h3>Jogos</h3><p>Agenda da turma e participações do seu filho. Últimos 8 registros por data.</p><?php if (!$dados['jogos']): ?><p class="familia-empty">Nenhum jogo registrado.</p><?php endif; ?>
            <?php foreach (array_slice($dados['jogos'], 0, 8) as $j): ?><article class="familia-row"><div><strong><?= e($j['TURMA']) ?> × <?= e($j['ADVERSARIO']) ?></strong><p><?= familiaData($j['DATA_JOGO']) ?> · <?= $j['HORARIO'] ? e(substr($j['HORARIO'], 0, 5)) : 'Horário a confirmar' ?></p><p><?= e($j['LOCAL'] ?: 'Local a confirmar') ?></p><?php if ($j['DATA_JOGO'] > date('Y-m-d')): ?><small>Agenda da turma · participação a confirmar</small><?php elseif ((int) $j['PARTICIPOU'] === 1): ?><small><?= (int) $j['GOLS'] ?> gols · <?= (int) $j['ASSISTENCIAS'] ?> assistências</small><?php else: ?><small>Sem participação registrada</small><?php endif; ?></div><b><?= $j['GOLS_ESCOLINHA'] !== null && $j['GOLS_ADVERSARIO'] !== null ? (int) $j['GOLS_ESCOLINHA'] . ' × ' . (int) $j['GOLS_ADVERSARIO'] : '—' ?></b></article><?php endforeach; ?>
        </section>
        <section class="familia-card" id="metas"><h3>Metas</h3><p>Objetivos registrados pelo atleta.</p><?php if (!$dados['metas']): ?><p class="familia-empty">Seu filho ainda não cadastrou metas.</p><?php endif; ?>
            <?php foreach ($dados['metas'] as $meta): $tipo = $meta['TIPO']; $valor = $tipo === 'ATRIBUTO' ? ($dados['medias'][$meta['ATRIBUTO']] ?? null) : ($tipo === 'OVERALL' ? $dados['overall'] : ($dados['stats'][$tipo] ?? null)); $rotulo = $tipo === 'ATRIBUTO' ? (avaliacaoCatalogoAtributos()[$meta['ATRIBUTO']] ?? 'Atributo') : ['OVERALL'=>'Overall','GOLS'=>'Gols','ASSISTENCIAS'=>'Assistências'][$tipo]; $alvo = (float) $meta['VALOR_ALVO']; ?>
                <div class="familia-attribute"><div><strong><?= e($rotulo) ?></strong><span><?= familiaNota($valor) ?> / <?= familiaNota($alvo) ?></span></div><progress max="100" value="<?= $alvo > 0 && $valor !== null ? max(0, min(100, $valor / $alvo * 100)) : 0 ?>" aria-label="Progresso da meta de <?= e($rotulo) ?>"></progress><small><?= $valor === null ? 'Aguardando dados' : ($valor >= $alvo ? 'Meta alcançada' : 'Em andamento') ?></small></div>
            <?php endforeach; ?>
        </section>
        <section class="familia-card" id="observacoes"><h3>Observações</h3><h4>Cuidados com o atleta</h4><p><?= nl2br(e($filho['OBSERVACOES_MEDICAS'] ?: 'Nenhuma observação médica registrada.')) ?></p><h4>Recados nas avaliações</h4><?php $recados = array_filter($dados['avaliacoes'], static function ($av) { return trim($av['OBSERVACAO'] ?? '') !== ''; }); ?><?php if (!$recados): ?><p class="familia-empty">Nenhuma observação do professor registrada.</p><?php endif; ?><?php foreach (array_slice($recados, 0, 3) as $av): ?><blockquote><p><?= nl2br(e($av['OBSERVACAO'])) ?></p><footer><?= e($av['PROFESSOR']) ?> · <?= familiaData($av['DATA_AVALIACAO']) ?></footer></blockquote><?php endforeach; ?></section>
    </div>
    <?php endif; ?>
    <section class="familia-card" id="notificacoes"><div class="familia-heading"><h3>Notificações</h3><a href="notificacoes.php">Ver todas →</a></div><?php if (!$avisos): ?><p class="familia-empty">Você está em dia. Nenhuma notificação recebida.</p><?php endif; ?><?php foreach ($avisos as $aviso): ?><article class="familia-row"><div><strong><?= e($aviso['TITULO']) ?></strong><p><?= e($aviso['DESCRICAO']) ?></p><small><?= familiaData($aviso['CRIADO_EM']) ?></small></div><span class="familia-status"><?= $aviso['LIDA'] ? 'Lida' : 'Nova' ?></span></article><?php endforeach; ?></section>
</div>
<?php pageEnd(); ?>
