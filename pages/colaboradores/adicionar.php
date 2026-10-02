<?php
session_start();
if (!isset($_SESSION['usuario_id'])) { header('Location: ../../index.php'); exit; }
$arquivo = dirname(__DIR__, 2) . '/data/colaboradores/ativos.json';
$colaboradores = json_decode(file_get_contents($arquivo), true) ?: [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $novo = ['id' => empty($colaboradores) ? 1 : max(array_column($colaboradores, 'id')) + 1, 'matricula' => trim($_POST['matricula'] ?? ''), 'nome' => trim($_POST['nome'] ?? ''), 'cargo' => trim($_POST['cargo'] ?? ''), 'cpf' => preg_replace('/\D/', '', $_POST['cpf'] ?? ''), 'departamento' => trim($_POST['departamento'] ?? ''), 'email' => trim($_POST['email'] ?? ''), 'tipo_trabalho' => $_POST['tipo_trabalho'] ?? 'local', 'data_cadastro' => date('Y-m-d H:i:s')];
    if ($novo['nome'] !== '' && $novo['email'] !== '') { $colaboradores[] = $novo; file_put_contents($arquivo, json_encode($colaboradores, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX); header('Location: colaboradores.php'); exit; }
}
header('Location: colaboradores.php'); exit;
