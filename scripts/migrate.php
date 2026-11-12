<?php
// Atualização incremental; execute somente no terminal, após backup.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/database.php';
$db = getDB();
$hasTable = static function ($table) use ($db) {
    $q=$db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $q->execute([$table]); return (bool)$q->fetchColumn();
};
$hasColumn = static function ($table,$column) use ($db) {
    $q=$db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $q->execute([$table,$column]); return (bool)$q->fetchColumn();
};
if (!$hasTable('TB_USUARIO')) { fwrite(STDERR,"Banco vazio: importe database.sql primeiro.\n"); exit(1); }
$changes = [
    ['TB_AULA','HORARIO','migration_treinos_calendario.sql'],
    ['TB_AULA','TIPO_TREINO','migration_avaliacao_por_treino.sql'],
    ['TB_JOGO',null,'migration_jogos_estatisticas.sql'],
    ['TB_ALUNO','COD_USUARIO','migration_metas_destaques.sql'],
    ['TB_NOTIFICACAO',null,'migration_notificacoes.sql'],
    ['TB_FINANCEIRO',null,'migration_financeiro.sql']
];
try {
    foreach ($changes as [$table,$column,$file]) {
        if ($column ? $hasColumn($table,$column) : $hasTable($table)) { echo "Já aplicada: $file\n"; continue; }
        $db->exec(file_get_contents(__DIR__.'/../'.$file));
        echo "Aplicada: $file\n";
    }
    echo "Atualização concluída.\n";
} catch (PDOException $error) {
    fwrite(STDERR, "Migração interrompida: ".$error->getMessage()."\nDDL pode ter sido aplicado parcialmente; confira o schema antes de repetir.\n");
    exit(1);
}
