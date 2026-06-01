<?php
/**
 * HashWeb Server - Backend PHP
 * Armazena e serve páginas por hash
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Diretório de armazenamento dos sites
define('SITES_DIR', __DIR__ . '/sites/');
define('INDEX_FILE', __DIR__ . '/sites_index.json');

if (!is_dir(SITES_DIR)) {
    mkdir(SITES_DIR, 0755, true);
}

// Carrega ou inicializa o índice
function loadIndex() {
    if (file_exists(INDEX_FILE)) {
        return json_decode(file_get_contents(INDEX_FILE), true) ?? [];
    }
    return [];
}

function saveIndex($index) {
    file_put_contents(INDEX_FILE, json_encode($index, JSON_PRETTY_PRINT));
}

function generateHash($name, $timestamp) {
    return hash('sha256', $name . $timestamp . random_bytes(16));
}

function generatePrivateKey() {
    return bin2hex(random_bytes(32));
}

function hashPrivateKey($key) {
    return hash('sha256', $key . 'hashweb_salt_2024');
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// =======================
// ROTA: Criar site
// =======================
if ($action === 'create' && $method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $name = trim($data['name'] ?? '');
    $html = $data['html'] ?? '';

    if (empty($name)) {
        http_response_code(400);
        echo json_encode(['error' => 'Nome do site é obrigatório']);
        exit();
    }

    if (empty($html)) {
        http_response_code(400);
        echo json_encode(['error' => 'Código HTML é obrigatório']);
        exit();
    }

    $timestamp = time();
    $hash = generateHash($name, $timestamp);
    $privateKey = generatePrivateKey();
    $hashedKey = hashPrivateKey($privateKey);

    // Salva o arquivo HTML do site
    $siteFile = SITES_DIR . $hash . '.html';
    file_put_contents($siteFile, $html);

    // Atualiza o índice
    $index = loadIndex();
    $index[$hash] = [
        'name'       => $name,
        'hash'       => $hash,
        'created_at' => $timestamp,
        'hashed_key' => $hashedKey,
        'size'       => strlen($html),
    ];
    saveIndex($index);

    echo json_encode([
        'success'     => true,
        'hash'        => $hash,
        'private_key' => $privateKey,
        'name'        => $name,
        'url'         => '?action=view&hash=' . $hash,
    ]);
    exit();
}

// =======================
// ROTA: Listar sites
// =======================
if ($action === 'list' && $method === 'GET') {
    $index = loadIndex();
    $list = array_values(array_map(function($site) {
        return [
            'name'       => $site['name'],
            'hash'       => $site['hash'],
            'created_at' => $site['created_at'],
            'size'       => $site['size'],
        ];
    }, $index));

    // Ordenar por data de criação (mais recente primeiro)
    usort($list, fn($a, $b) => $b['created_at'] - $a['created_at']);

    echo json_encode(['sites' => $list]);
    exit();
}

// =======================
// ROTA: Ver site (HTML)
// =======================
if ($action === 'view' && $method === 'GET') {
    $hash = $_GET['hash'] ?? '';
    if (empty($hash) || !preg_match('/^[a-f0-9]{64}$/', $hash)) {
        http_response_code(400);
        header('Content-Type: text/html');
        echo '<h1>Hash inválido</h1>';
        exit();
    }

    $siteFile = SITES_DIR . $hash . '.html';
    if (!file_exists($siteFile)) {
        http_response_code(404);
        header('Content-Type: text/html');
        echo '<h1>Site não encontrado</h1>';
        exit();
    }

    header('Content-Type: text/html; charset=utf-8');
    readfile($siteFile);
    exit();
}

// =======================
// ROTA: Recuperar site com chave privada
// =======================
if ($action === 'recover' && $method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $privateKey = trim($data['private_key'] ?? '');

    if (empty($privateKey)) {
        http_response_code(400);
        echo json_encode(['error' => 'Chave privada é obrigatória']);
        exit();
    }

    $hashedKey = hashPrivateKey($privateKey);
    $index = loadIndex();

    foreach ($index as $hash => $site) {
        if ($site['hashed_key'] === $hashedKey) {
            $siteFile = SITES_DIR . $hash . '.html';
            $html = file_exists($siteFile) ? file_get_contents($siteFile) : '';
            echo json_encode([
                'success' => true,
                'hash'    => $hash,
                'name'    => $site['name'],
                'html'    => $html,
                'created_at' => $site['created_at'],
            ]);
            exit();
        }
    }

    http_response_code(404);
    echo json_encode(['error' => 'Chave privada inválida ou site não encontrado']);
    exit();
}

// =======================
// ROTA: Atualizar site
// =======================
if ($action === 'update' && $method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $hash = trim($data['hash'] ?? '');
    $privateKey = trim($data['private_key'] ?? '');
    $html = $data['html'] ?? '';
    $newName = trim($data['name'] ?? '');

    if (empty($hash) || empty($privateKey)) {
        http_response_code(400);
        echo json_encode(['error' => 'Hash e chave privada são obrigatórios']);
        exit();
    }

    $index = loadIndex();
    if (!isset($index[$hash])) {
        http_response_code(404);
        echo json_encode(['error' => 'Site não encontrado']);
        exit();
    }

    $hashedKey = hashPrivateKey($privateKey);
    if ($index[$hash]['hashed_key'] !== $hashedKey) {
        http_response_code(403);
        echo json_encode(['error' => 'Chave privada inválida']);
        exit();
    }

    $siteFile = SITES_DIR . $hash . '.html';
    file_put_contents($siteFile, $html);

    if (!empty($newName)) {
        $index[$hash]['name'] = $newName;
    }
    $index[$hash]['size'] = strlen($html);
    $index[$hash]['updated_at'] = time();
    saveIndex($index);

    echo json_encode(['success' => true, 'hash' => $hash]);
    exit();
}

// =======================
// ROTA: Apagar site
// =======================
if ($action === 'delete' && $method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $hash = trim($data['hash'] ?? '');
    $privateKey = trim($data['private_key'] ?? '');

    if (empty($hash) || empty($privateKey)) {
        http_response_code(400);
        echo json_encode(['error' => 'Hash e chave privada são obrigatórios']);
        exit();
    }

    $index = loadIndex();
    if (!isset($index[$hash])) {
        http_response_code(404);
        echo json_encode(['error' => 'Site não encontrado']);
        exit();
    }

    $hashedKey = hashPrivateKey($privateKey);
    if ($index[$hash]['hashed_key'] !== $hashedKey) {
        http_response_code(403);
        echo json_encode(['error' => 'Chave privada inválida']);
        exit();
    }

    $siteFile = SITES_DIR . $hash . '.html';
    if (file_exists($siteFile)) {
        unlink($siteFile);
    }

    unset($index[$hash]);
    saveIndex($index);

    echo json_encode(['success' => true]);
    exit();
}

// =======================
// ROTA: Info do servidor
// =======================
if ($action === 'info' && $method === 'GET') {
    $index = loadIndex();
    echo json_encode([
        'status'      => 'online',
        'version'     => '1.0.0',
        'total_sites' => count($index),
        'php_version' => PHP_VERSION,
        'timestamp'   => time(),
    ]);
    exit();
}

// Rota não encontrada
http_response_code(404);
echo json_encode(['error' => 'Rota não encontrada', 'action' => $action]);
