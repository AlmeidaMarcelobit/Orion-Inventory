<?php
function manutencaoLer(string $arquivo): array {
    $dados = json_decode(file_get_contents($arquivo), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($dados) || array_values($dados) !== $dados) throw new RuntimeException('Formato de dados inválido.');
    return $dados;
}
function manutencaoInventario(string $base): array {
    $listas = [];
    foreach (['alocados', 'emprestados', 'estoque', 'internos', 'fora_uso', 'manutencao'] as $nome) $listas[$nome] = manutencaoLer($base . '/data/equipamentos/' . $nome . '.json');
    return $listas;
}
function manutencaoSalvar(string $arquivo, array $dados): void {
    $json = json_encode(array_values($dados), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $temp = $arquivo . '.' . bin2hex(random_bytes(6)) . '.tmp';
    try {
        if (file_put_contents($temp, $json, LOCK_EX) !== strlen($json)) throw new RuntimeException('Não foi possível gravar os dados.');
        for ($i = 0; $i < 5; $i++) { if (@rename($temp, $arquivo)) return; usleep(50000); }
        throw new RuntimeException('Não foi possível atualizar os dados.');
    } finally { if (is_file($temp)) unlink($temp); }
}
function manutencaoExecutar(string $base, array $post, array $ativos): string {
    $locks = []; $alterados = []; $originais = []; $auditEquipAntes = $auditEquipDepois = null;
    try {
        foreach ([$base . '/data/solicitacoes_manutencao/.manutencao.lock', $base . '/data/equipamentos/.equipamentos.lock'] as $path) {
            $lock = fopen($path, 'c'); if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Não foi possível acessar os dados para gravação.'); $locks[] = $lock;
        }
        $pathSolic = $base . '/data/solicitacoes_manutencao/solicitacoes.json';
        $solicitacoes = manutencaoLer($pathSolic); $antesSolic = $solicitacoes;
        $listas = manutencaoInventario($base); $antesListas = $listas;
        $acao = (string)($post['acao'] ?? ''); $agora = date('Y-m-d H:i:s'); $usuario = $_SESSION['usuario_nome'] ?? 'Usuário';
        $registroAntes = null; $indiceSolic = null;
        if ($acao === 'abrir') {
            $chave = (string)($post['equipamento'] ?? ''); $origem = null; $indiceEquip = null;
            foreach ($listas as $nome => $itens) foreach ($itens as $i => $item) if ($nome . ':' . $item['id'] === $chave) { $origem = $nome; $indiceEquip = $i; }
            if ($indiceEquip === null) throw new RuntimeException('Selecione um equipamento cadastrado.');
            $equip = $listas[$origem][$indiceEquip];
            if (in_array($equip['status'] ?? '', ['manutencao', 'fora_uso', 'pendente_devolucao'], true)) throw new RuntimeException('Este equipamento não está disponível para iniciar uma manutenção.');
            foreach ($solicitacoes as $s) if (($s['status'] ?? '') !== 'concluido' && (string)($s['equipamento_relacionado_id'] ?? '') === (string)$equip['id']) throw new RuntimeException('Este equipamento já possui uma solicitação ativa.');
            $modalidade = (string)($post['modalidade'] ?? '');
            if (!in_array($modalidade, ['interna', 'externa'], true)) throw new RuntimeException('Selecione manutenção interna ou externa.');
            $descricao = trim((string)($post['descricao_problema'] ?? '')); $destino = trim((string)($post['destino_reparo'] ?? ''));
            if ($descricao === '' || strlen($descricao) > 5000) throw new RuntimeException('Descreva o problema em até 5.000 caracteres.');
            if ($modalidade === 'externa' && ($destino === '' || strlen($destino) > 255)) throw new RuntimeException('Informe o responsável ou prestador externo.');
            $prioridade = (string)($post['prioridade'] ?? 'normal'); if (!in_array($prioridade, ['baixa', 'normal', 'alta', 'urgente'], true)) throw new RuntimeException('Prioridade inválida.');
            $auditEquipAntes = $equip; $equip['status_anterior'] = $equip['status']; $equip['status'] = 'manutencao'; $equip['data_atualizacao'] = $agora; $equip['data_manutencao'] = $agora;
            $equip['modalidade_manutencao'] = $modalidade;
            array_splice($listas[$origem], $indiceEquip, 1); $listas['manutencao'][] = $equip; $auditEquipDepois = $equip;
            $id = 0; foreach ($solicitacoes as $s) $id = max($id, (int)$s['id']);
            $status = $modalidade === 'externa' ? 'aguardando_envio' : 'em_manutencao';
            $registro = ['id' => $id + 1, 'tipo_equipamento' => $equip['tipo'], 'equipamento_relacionado_id' => (string)$equip['id'], 'patrimonio' => $equip['patrimonio'] ?? null, 'colaborador_id' => $equip['colaborador_id'] ?? null, 'colaborador_nome' => $equip['colaborador_nome'] ?? null, 'modalidade' => $modalidade, 'destino_reparo' => $modalidade === 'interna' ? 'interno' : $destino, 'descricao_problema' => $descricao, 'prioridade' => $prioridade, 'responsavel_envio' => $usuario, 'data_envio' => $agora, 'status' => $status, 'historico_status' => [['status' => $status, 'data' => $agora, 'usuario' => $usuario]], 'usuario_cadastro_id' => $_SESSION['usuario_id'], 'usuario_cadastro_nome' => $usuario];
            $solicitacoes[] = $registro;
        } elseif (in_array($acao, ['iniciar', 'concluir'], true)) {
            foreach ($solicitacoes as $i => $s) if ((string)$s['id'] === (string)($post['id'] ?? '')) { $indiceSolic = $i; break; }
            if ($indiceSolic === null) throw new RuntimeException('Solicitação não encontrada.');
            $registro = $solicitacoes[$indiceSolic]; $registroAntes = $registro;
            if ($registro['status'] === 'concluido') throw new RuntimeException('Esta solicitação já está concluída.');
            if ($acao === 'iniciar') {
                if ($registro['status'] !== 'aguardando_envio') throw new RuntimeException('Somente solicitações aguardando envio podem ser iniciadas.');
                $registro['status'] = 'em_manutencao'; $registro['data_inicio'] = $agora;
            } else {
                $solucao = trim((string)($post['solucao'] ?? '')); if ($solucao === '' || strlen($solucao) > 5000) throw new RuntimeException('Informe o resultado do reparo em até 5.000 caracteres.');
                $destino = (string)($post['destino_conclusao'] ?? ''); $mapa = ['estoque' => 'estoque', 'interno' => 'internos', 'fora_uso' => 'fora_uso', 'alocado' => 'alocados']; if (!isset($mapa[$destino])) throw new RuntimeException('Selecione o destino do equipamento.');
                $indiceEquip = null; foreach ($listas['manutencao'] as $i => $item) if ((string)$item['id'] === (string)$registro['equipamento_relacionado_id']) { $indiceEquip = $i; break; }
                if ($indiceEquip === null) throw new RuntimeException('O equipamento não está no banco de manutenção. Confira o inventário antes de concluir.');
                $equip = $listas['manutencao'][$indiceEquip]; $auditEquipAntes = $equip;
                $equip['status'] = $destino; $equip['data_atualizacao'] = $agora; $equip['data_conclusao_manutencao'] = $agora;
                if ($destino === 'alocado') {
                    $idPessoa = (string)($post['colaborador_id'] ?? ''); if (!isset($ativos[$idPessoa])) throw new RuntimeException('Selecione um colaborador ativo.');
                    $centro = trim((string)($ativos[$idPessoa]['centro_custo'] ?? '')); if ($centro === '') throw new RuntimeException('O colaborador não possui centro de custo.');
                    $equip['colaborador_id'] = $ativos[$idPessoa]['id']; $equip['colaborador_nome'] = $ativos[$idPessoa]['nome']; $equip['centro_custo'] = $centro; $equip['data_atribuicao'] = $agora; $equip['tipo_atribuicao'] = 'alocacao';
                } else { $equip['colaborador_id'] = null; $equip['colaborador_nome'] = null; $equip['centro_custo'] = '11001'; $equip['data_atribuicao'] = null; $equip['tipo_atribuicao'] = null; }
                array_splice($listas['manutencao'], $indiceEquip, 1); $listas[$mapa[$destino]][] = $equip; $auditEquipDepois = $equip;
                $registro['status'] = 'concluido'; $registro['data_conclusao'] = $agora; $registro['destino_conclusao'] = $destino; $registro['solucao'] = $solucao; $registro['usuario_conclusao'] = $usuario;
            }
            $registro['historico_status'][] = ['status' => $registro['status'], 'data' => $agora, 'usuario' => $usuario]; $solicitacoes[$indiceSolic] = $registro;
        } else { throw new RuntimeException('Ação inválida.'); }
        $gravar = [$pathSolic => $solicitacoes]; $originais[$pathSolic] = $antesSolic;
        foreach ($listas as $nome => $itens) if ($itens !== $antesListas[$nome]) { $path = $base . '/data/equipamentos/' . $nome . '.json'; $gravar[$path] = $itens; $originais[$path] = $antesListas[$nome]; }
        try { foreach ($gravar as $path => $dados) { manutencaoSalvar($path, $dados); $alterados[] = $path; } }
        catch (Throwable $e) { foreach (array_reverse($alterados) as $path) manutencaoSalvar($path, $originais[$path]); throw $e; }
        orionRegistrarMovimentacao($acao, 'manutencao', $registroAntes, $registro, ['modalidade' => $registro['modalidade'] ?? 'externa', 'destino_reparo' => $registro['destino_reparo'], 'equipamento_id' => $registro['equipamento_relacionado_id']]);
        if ($auditEquipDepois) orionRegistrarMovimentacao($acao === 'abrir' ? 'enviar_manutencao' : 'concluir_manutencao', 'equipamento', $auditEquipAntes, $auditEquipDepois, ['solicitacao_id' => $registro['id']]);
        return 'Solicitação #' . $registro['id'] . ' ' . ['abrir' => 'aberta', 'iniciar' => 'iniciada', 'concluir' => 'concluída'][$acao] . ' com sucesso.';
    } finally { foreach (array_reverse($locks) as $lock) { flock($lock, LOCK_UN); fclose($lock); } }
}
