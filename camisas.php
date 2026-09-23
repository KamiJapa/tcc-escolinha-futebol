<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/config/database.php';

checkRole(['ADMIN', 'SECRETARIA']);
$db = getDB();
$escolinha = currentEscolinhaId();
$retorno = 'camisas.php' . (isset($_GET['mini']) ? '?mini=1' : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'atribuir') {
        $aluno = (int) ($_POST['aluno'] ?? 0);
        $numeroTexto = trim($_POST['numero'] ?? '');
        $numero = $numeroTexto === '' ? null : filter_var($numeroTexto, FILTER_VALIDATE_INT);

        if ($aluno <= 0 || ($numeroTexto !== '' && ($numero === false || $numero < 0 || $numero > 99))) {
            flash('error', 'Escolha um aluno e informe um número de 0 a 99.');
            go($retorno);
        }

        $verificarAluno = $db->prepare('SELECT 1 FROM TB_ALUNO WHERE COD_ALUNO = ? AND COD_ESCOLINHA = ?');
        $verificarAluno->execute([$aluno, $escolinha]);
        if (!$verificarAluno->fetchColumn()) {
            flash('error', 'Aluno não encontrado nesta escolinha.');
            go($retorno);
        }

        if ($numero !== null) {
            $verificarNumero = $db->prepare(
                'SELECT NOME FROM TB_ALUNO WHERE COD_ESCOLINHA = ? AND NUMERO_CAMISA = ? AND COD_ALUNO <> ? LIMIT 1'
            );
            $verificarNumero->execute([$escolinha, $numero, $aluno]);
            $nomeDonoNumero = $verificarNumero->fetchColumn();
            if ($nomeDonoNumero) {
                flash('error', 'Esse número já está com ' . $nomeDonoNumero . '. Use a opção de troca de camisas.');
                go($retorno);
            }
        }

        $atualizar = $db->prepare('UPDATE TB_ALUNO SET NUMERO_CAMISA = ? WHERE COD_ALUNO = ? AND COD_ESCOLINHA = ?');
        $atualizar->execute([$numero, $aluno, $escolinha]);
        flash('success', $numero === null ? 'Número de camisa removido.' : 'Número de camisa atualizado.');
        go($retorno);
    }

    if ($acao === 'trocar') {
        $primeiro = (int) ($_POST['primeiro_aluno'] ?? 0);
        $segundo = (int) ($_POST['segundo_aluno'] ?? 0);

        if ($primeiro <= 0 || $segundo <= 0 || $primeiro === $segundo) {
            flash('error', 'Escolha dois alunos diferentes para fazer a troca.');
            go($retorno);
        }

        $buscar = $db->prepare('SELECT COD_ALUNO, NUMERO_CAMISA FROM TB_ALUNO WHERE COD_ESCOLINHA = ? AND COD_ALUNO IN (?, ?)');
        $buscar->execute([$escolinha, $primeiro, $segundo]);
        $alunos = $buscar->fetchAll();
        if (count($alunos) !== 2) {
            flash('error', 'Não foi possível localizar os dois alunos.');
            go($retorno);
        }

        $numeros = [];
        foreach ($alunos as $aluno) {
            $numeros[(int) $aluno['COD_ALUNO']] = $aluno['NUMERO_CAMISA'];
        }

        $db->beginTransaction();
        try {
            $atualizar = $db->prepare('UPDATE TB_ALUNO SET NUMERO_CAMISA = ? WHERE COD_ALUNO = ? AND COD_ESCOLINHA = ?');
            $atualizar->execute([$numeros[$segundo], $primeiro, $escolinha]);
            $atualizar->execute([$numeros[$primeiro], $segundo, $escolinha]);
            $db->commit();
            flash('success', 'Os números das camisas foram trocados.');
        } catch (PDOException $e) {
            $db->rollBack();
            flash('error', 'Não foi possível trocar os números das camisas.');
        }
        go($retorno);
    }
}

$consulta = $db->prepare('SELECT COD_ALUNO, NOME, DATA_NASCIMENTO, NUMERO_CAMISA FROM TB_ALUNO WHERE COD_ESCOLINHA = ? ORDER BY NOME');
$consulta->execute([$escolinha]);
$alunos = $consulta->fetchAll();

pageStart('Números das camisas');
?>
<section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <h2 class="mb-4 text-lg font-bold text-emerald-950">Escolher um número</h2>
    <form method="post" action="camisas.php<?= isset($_GET['mini']) ? '?mini=1' : '' ?>" class="grid gap-4 sm:grid-cols-[1fr_180px_auto] sm:items-end">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="acao" value="atribuir">
        <label>Aluno<select name="aluno" required><option value="">Selecione</option><?php foreach ($alunos as $aluno): ?><option value="<?= (int) $aluno['COD_ALUNO'] ?>"><?= e($aluno['NOME']) ?> — camisa <?= $aluno['NUMERO_CAMISA'] === null ? 'sem número' : (int) $aluno['NUMERO_CAMISA'] ?></option><?php endforeach; ?></select></label>
        <label>Novo número<input type="number" name="numero" min="0" max="99" placeholder="0 a 99"></label>
        <button class="botao-principal rounded-lg bg-amber-400 px-4 py-2 font-semibold text-emerald-950 shadow-sm transition hover:bg-amber-300" type="submit">Salvar número</button>
    </form>
    <p class="mt-2 text-sm text-slate-500">Deixe o número em branco para removê-lo. Para um número já usado, faça uma troca abaixo.</p>
</section>

<section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <h2 class="mb-4 text-lg font-bold text-emerald-950">Trocar camisas entre alunos</h2>
    <form method="post" action="camisas.php<?= isset($_GET['mini']) ? '?mini=1' : '' ?>" class="grid gap-4 sm:grid-cols-2">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="acao" value="trocar">
        <label>Primeiro aluno<select name="primeiro_aluno" required><option value="">Selecione</option><?php foreach ($alunos as $aluno): ?><option value="<?= (int) $aluno['COD_ALUNO'] ?>"><?= e($aluno['NOME']) ?> — <?= $aluno['NUMERO_CAMISA'] === null ? 'sem número' : '#' . (int) $aluno['NUMERO_CAMISA'] ?></option><?php endforeach; ?></select></label>
        <label>Segundo aluno<select name="segundo_aluno" required><option value="">Selecione</option><?php foreach ($alunos as $aluno): ?><option value="<?= (int) $aluno['COD_ALUNO'] ?>"><?= e($aluno['NOME']) ?> — <?= $aluno['NUMERO_CAMISA'] === null ? 'sem número' : '#' . (int) $aluno['NUMERO_CAMISA'] ?></option><?php endforeach; ?></select></label>
        <button class="botao-principal rounded-lg bg-amber-400 px-4 py-2 font-semibold text-emerald-950 shadow-sm transition hover:bg-amber-300 sm:col-span-2" type="submit">Trocar números</button>
    </form>
</section>

<section class="overflow-x-auto rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <h2 class="mb-4 text-lg font-bold text-emerald-950">Elenco e números atuais</h2>
    <table class="w-full text-left text-sm">
        <thead><tr><th>Aluno</th><th>Data de nascimento</th><th>Camisa</th></tr></thead>
        <tbody><?php foreach ($alunos as $aluno): ?><tr><td><?= e($aluno['NOME']) ?></td><td><?= e(date('d/m/Y', strtotime($aluno['DATA_NASCIMENTO']))) ?></td><td><?= $aluno['NUMERO_CAMISA'] === null ? 'Sem número' : '#' . (int) $aluno['NUMERO_CAMISA'] ?></td></tr><?php endforeach; ?></tbody>
    </table>
    <?php if (!$alunos): ?><p>Nenhum aluno cadastrado.</p><?php endif; ?>
</section>
<?php pageEnd(); ?>
