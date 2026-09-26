<?php

function avaliacaoCatalogoAtributos() {
    return [
        'velocidade' => 'Velocidade',
        'finalizacao' => 'Finalização',
        'passe' => 'Passe',
        'drible' => 'Drible',
        'fisico' => 'Físico',
        'posicionamento' => 'Posicionamento'
    ];
}

function avaliacaoTiposTreino() {
    return [
        'VELOCIDADE' => ['nome' => 'Velocidade', 'atributos' => ['velocidade']],
        'FINALIZACAO_PASSE' => ['nome' => 'Finalização + Passe', 'atributos' => ['finalizacao', 'passe']],
        'DRIBLE' => ['nome' => 'Drible', 'atributos' => ['drible']],
        'FISICO' => ['nome' => 'Preparação física', 'atributos' => ['fisico']],
        'POSICIONAMENTO' => ['nome' => 'Posicionamento', 'atributos' => ['posicionamento']],
        'TECNICO_GERAL' => ['nome' => 'Fundamentos gerais', 'atributos' => array_keys(avaliacaoCatalogoAtributos())],
        'PERSONALIZADO' => ['nome' => 'Personalizado', 'atributos' => null]
    ];
}

function avaliacaoAtributosPersonalizados($json) {
    $dados = is_string($json) ? json_decode($json, true) : $json;
    $dados = is_array($dados) ? $dados : [];
    return array_values(array_filter(array_keys(avaliacaoCatalogoAtributos()), static function ($atributo) use ($dados) {
        return in_array($atributo, $dados, true);
    }));
}

function avaliacaoAtributosDoTreino($tipo, $personalizados = null) {
    $tipos = avaliacaoTiposTreino();
    if (!isset($tipos[$tipo])) {
        return [];
    }
    if ($tipo === 'PERSONALIZADO') {
        return avaliacaoAtributosPersonalizados($personalizados);
    }
    return $tipos[$tipo]['atributos'];
}
