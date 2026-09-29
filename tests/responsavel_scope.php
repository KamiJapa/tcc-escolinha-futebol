<?php
// Execute em um banco de teste com database.sql aplicado. Todas as fixtures são revertidas.
// TEST_DB_DSN='mysql:host=127.0.0.1;dbname=bdescolinha;charset=utf8mb4' php tests/responsavel_scope.php
require_once __DIR__ . '/../includes/avaliacao_helpers.php';
require_once __DIR__ . '/../includes/responsavel_helpers.php';
$dsn = getenv('TEST_DB_DSN');
if (!$dsn) { fwrite(STDERR, "Defina TEST_DB_DSN para um banco de teste.\n"); exit(1); }
$db = new PDO($dsn, getenv('TEST_DB_USER') ?: 'root', getenv('TEST_DB_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
function verify($condition, $message) { if (!$condition) throw new RuntimeException($message); echo "OK: $message\n"; }
function insertFixture($db, $table, $values) {
    $q = $db->prepare('INSERT INTO ' . $table . ' (' . implode(',', array_keys($values)) . ') VALUES (' . implode(',', array_fill(0, count($values), '?')) . ')');
    $q->execute(array_values($values));
    return (int) $db->lastInsertId();
}
$db->beginTransaction();
try {
    $s1 = insertFixture($db, 'TB_ESCOLINHA', ['NOME'=>'QA escola 1']);
    $s2 = insertFixture($db, 'TB_ESCOLINHA', ['NOME'=>'QA escola 2']);
    $users = [];
    foreach ([$s1,$s1,$s2] as $i=>$s) $users[] = insertFixture($db, 'TB_USUARIO', ['COD_ESCOLINHA'=>$s,'NOME'=>'QA conta','EMAIL'=>uniqid('scope', true).'@example.test','SENHA_HASH'=>'unused','PERFIL'=>'RESPONSAVEL']);
    $r1 = insertFixture($db,'TB_RESPONSAVEL',['COD_ESCOLINHA'=>$s1,'COD_USUARIO'=>$users[0],'NOME'=>'QA pai','CPF'=>'qa-1']);
    $r2 = insertFixture($db,'TB_RESPONSAVEL',['COD_ESCOLINHA'=>$s1,'COD_USUARIO'=>$users[1],'NOME'=>'QA outro pai','CPF'=>'qa-2']);
    $children = [];
    foreach ([[$s1,$r1],[$s1,$r1],[$s1,$r2],[$s2,$r1]] as $i=>$pair) $children[] = insertFixture($db,'TB_ALUNO',['COD_ESCOLINHA'=>$pair[0],'COD_RESPONSAVEL'=>$pair[1],'NOME'=>'QA atleta '.$i,'DATA_NASCIMENTO'=>'2015-01-01']);
    $filhos = responsavelFilhos($db,$users[0],$s1);
    verify(array_map('intval',array_column($filhos,'COD_ALUNO')) === array_slice($children,0,2), 'Somente os dois filhos vinculados, sem outro responsável nem outra escola');
    verify(responsavelFilhos($db,$users[0],$s2) === [], 'Escola adulterada não autoriza vínculo cruzado');
    verify(responsavelFilhos($db,$users[2],$s2) === [], 'Conta sem vínculo retorna lista vazia');
    $turmas=[];
    foreach ([$s1,$s2] as $s) $turmas[]=insertFixture($db,'TB_TURMA',['COD_ESCOLINHA'=>$s,'NOME'=>'QA turma','FAIXA_ETARIA'=>'Sub-12','DIAS_TREINO'=>'Segunda','HORARIO'=>'14:00']);
    foreach ($turmas as $t) {
        insertFixture($db,'TB_MATRICULA',['COD_ALUNO'=>$children[0],'COD_TURMA'=>$t,'DATA_MATRICULA'=>date('Y-m-d')]);
        $aula=insertFixture($db,'TB_AULA',['COD_TURMA'=>$t,'DATA_AULA'=>date('Y-m-d')]);
        insertFixture($db,'TB_PRESENCA',['COD_ALUNO'=>$children[0],'COD_AULA'=>$aula,'PRESENTE'=>1]);
        $jogo=insertFixture($db,'TB_JOGO',['COD_TURMA'=>$t,'ADVERSARIO'=>'QA adversário','DATA_JOGO'=>date('Y-m-d')]);
        insertFixture($db,'TB_JOGO_ATLETA',['COD_ALUNO'=>$children[0],'COD_JOGO'=>$jogo,'GOLS'=>2,'ASSISTENCIAS'=>1]);
    }
    foreach ([$users[0],$users[2]] as $u) {
        insertFixture($db,'TB_AVALIACAO',['COD_ALUNO'=>$children[0],'COD_PROFESSOR'=>$u,'DATA_AVALIACAO'=>date('Y-m-d'),'CRITERIOS_JSON'=>'{"passe":8}','NOTA_GERAL'=>8]);
        insertFixture($db,'TB_META_ATLETA',['COD_ALUNO'=>$children[0],'CRIADO_POR'=>$u,'TIPO'=>'GOLS','VALOR_ALVO'=>3]);
    }
    $d=responsavelAcompanhamento($db,$filhos[0]);
    foreach (['turmas','treinos','presencas','jogos','avaliacoes','metas'] as $key) verify(count($d[$key])===1, "$key exclui relações inconsistentes com outra escola");
    verify($d['stats']===['JOGOS'=>1,'GOLS'=>2,'ASSISTENCIAS'=>1], 'Estatísticas individuais reais');
    verify($d['overall']===8.0 && $d['medias']['passe']==8 && $d['frequencia']==100, 'Notas, atributos e frequência calculados');
    $vazio=responsavelAcompanhamento($db,$filhos[1]);
    verify($vazio['overall']===null && $vazio['frequencia']===null && $vazio['stats']['GOLS']===null, 'Ausência de dados não vira nota ou gols zero');
} finally { $db->rollBack(); }
