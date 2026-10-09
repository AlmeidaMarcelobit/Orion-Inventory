<?php
session_start();
require_once dirname(__DIR__) . '/includes/auditoria.php';
orionRegistrarAtividade();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['erro' => 'Não autenticado']);
    exit;
}

$base = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR;
$ler = static function (string $arquivo) use ($base): array {
    $caminho = $base . $arquivo;
    if (!is_file($caminho)) return [];
    $dados = json_decode(file_get_contents($caminho), true);
    return is_array($dados) ? $dados : [];
};

$colaboradores = $ler('colaboradores/ativos.json');
$estoque = $ler('equipamentos/estoque.json');
$alocados = $ler('equipamentos/alocados.json');
$emprestados = $ler('equipamentos/emprestados.json');
$internos = $ler('equipamentos/internos.json');
$manutencao = $ler('equipamentos/manutencao.json');
$foraUso = $ler('equipamentos/fora_uso.json');
$linhas = $ler('linhas.json');

$equipamentosTotal = count($estoque) + count($alocados) + count($emprestados) + count($internos) + count($manutencao) + count($foraUso);
$linhasAlocadas = count(array_filter($linhas, static fn($linha) => in_array($linha['status'] ?? '', ['alocado', 'emprestado'], true)));
$recentes = $colaboradores;
usort($recentes, static fn($a, $b) => strcmp($b['data_cadastro'] ?? $b['data_atualizacao'] ?? '', $a['data_cadastro'] ?? $a['data_atualizacao'] ?? ''));

echo json_encode([
    'colaboradores' => [
        'total' => count($colaboradores),
        'home' => count(array_filter($colaboradores, static fn($c) => ($c['tipo_trabalho'] ?? '') === 'home')),
        'local' => count(array_filter($colaboradores, static fn($c) => ($c['tipo_trabalho'] ?? '') !== 'home'))
    ],
    'equipamentos' => [
        'total' => $equipamentosTotal,
        'estoque' => count($estoque),
        'alocados' => count($alocados)
    ],
    'linhas' => [
        'total' => count($linhas),
        'disponiveis' => count($linhas) - $linhasAlocadas,
        'alocadas' => $linhasAlocadas
    ],
    'ocupacao' => $equipamentosTotal ? round((count($alocados) / $equipamentosTotal) * 100) : 0,
    'recentes' => array_slice($recentes, 0, 5)
], JSON_UNESCAPED_UNICODE);
