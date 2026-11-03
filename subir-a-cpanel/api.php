<?php
/**
 * Backend Reporte de Terreno V6.1 - Compatible con cPanel (Vínculo.cl)
 * Incluye: Folios cortos correlativos (RT-0001), Recepción offline/online, edición (incluyendo Solicita), eliminación y ficha PDF/Excel MYC SpA.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// PIN de acceso para la pestaña de Logística (puedes cambiarlo aquí)
const ADMIN_PIN = '1234';

$baseDir = __DIR__;
$dataDir = $baseDir . DIRECTORY_SEPARATOR . 'data';
$uploadsDir = $baseDir . DIRECTORY_SEPARATOR . 'uploads';
$dbFile = $dataDir . DIRECTORY_SEPARATOR . 'reportes.json';

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}
if (!is_dir($uploadsDir)) {
    mkdir($uploadsDir, 0755, true);
}
if (!file_exists($dbFile)) {
    file_put_contents($dbFile, "[]", LOCK_EX);
}

$dataHtaccess = $dataDir . DIRECTORY_SEPARATOR . '.htaccess';
if (!file_exists($dataHtaccess)) {
    @file_put_contents($dataHtaccess, "Order deny,allow\nDeny from all\n");
}

function readReports(string $dbFile): array {
    if (!file_exists($dbFile)) {
        return [];
    }
    $content = file_get_contents($dbFile);
    if ($content === false || trim($content) === '') {
        return [];
    }
    $decoded = json_decode($content, true);
    return is_array($decoded) ? $decoded : [];
}

function writeReports(string $dbFile, array $reports): bool {
    $fp = fopen($dbFile, 'c+');
    if (!$fp) {
        return false;
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return false;
    }
    ftruncate($fp, 0);
    rewind($fp);
    $json = json_encode(array_values($reports), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    fwrite($fp, $json);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

/**
 * Normaliza folios antiguos largos a folios cortos correlativos (RT-0001, RT-0002...)
 */
function ensureShortFolios(string $dbFile, array $reports): array {
    $changed = false;
    $maxNum = 0;

    foreach ($reports as $r) {
        $id = (string)($r['id'] ?? '');
        if (preg_match('/^RT-(\d{1,6})$/', $id, $m)) {
            $n = (int)$m[1];
            if ($n > $maxNum) {
                $maxNum = $n;
            }
        }
    }

    usort($reports, function ($a, $b) {
        return strcmp($a['createdAt'] ?? '', $b['createdAt'] ?? '');
    });

    foreach ($reports as &$r) {
        $id = (string)($r['id'] ?? '');
        if (!preg_match('/^RT-(\d{1,6})$/', $id)) {
            if (empty($r['clientUid']) && $id !== '') {
                $r['clientUid'] = $id;
            }
            $maxNum++;
            $r['id'] = sprintf('RT-%04d', $maxNum);
            $changed = true;
        }
    }
    unset($r);

    if ($changed) {
        writeReports($dbFile, $reports);
    }

    return [$reports, $maxNum];
}

function checkAdminPin(): void {
    $pin = $_SERVER['HTTP_X_ADMIN_PIN'] ?? ($_GET['pin'] ?? '');
    if ($pin !== ADMIN_PIN) {
        http_response_code(401);
        echo json_encode(['error' => 'No autorizado. Ingrese el PIN de Logística.']);
        exit;
    }
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

if ($method === 'GET' && $action === 'health') {
    echo json_encode(['ok' => true, 'server' => 'php-cpanel-v6.1', 'timestamp' => gmdate('c')]);
    exit;
}

if ($method === 'POST' && $action === 'auth') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $pin = trim((string)($input['pin'] ?? ''));
    if ($pin === ADMIN_PIN) {
        echo json_encode(['ok' => true]);
    } else {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'PIN incorrecto']);
    }
    exit;
}

if ($method === 'POST' && $action === 'delete') {
    checkAdminPin();
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = trim((string)($input['id'] ?? ''));
    if ($id === '') {
        http_response_code(400);
        echo json_encode(['error' => 'ID de reporte requerido']);
        exit;
    }

    $reports = readReports($dbFile);
    [$reports, ] = ensureShortFolios($dbFile, $reports);
    $remaining = [];
    $deleted = false;

    foreach ($reports as $r) {
        if (($r['id'] ?? '') === $id) {
            $deleted = true;
            if (!empty($r['fotos']) && is_array($r['fotos'])) {
                foreach ($r['fotos'] as $f) {
                    $relUrl = ltrim((string)($f['url'] ?? ''), '/');
                    if (strpos($relUrl, 'uploads/') === 0) {
                        $filePath = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relUrl);
                        if (is_file($filePath)) {
                            @unlink($filePath);
                        }
                    }
                }
            }
        } else {
            $remaining[] = $r;
        }
    }

    if (!$deleted) {
        http_response_code(404);
        echo json_encode(['error' => 'Reporte no encontrado']);
        exit;
    }

    writeReports($dbFile, $remaining);
    echo json_encode(['ok' => true, 'deletedId' => $id]);
    exit;
}

if ($method === 'POST' && $action === 'update') {
    checkAdminPin();
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = trim((string)($input['id'] ?? ''));
    if ($id === '') {
        http_response_code(400);
        echo json_encode(['error' => 'ID de reporte requerido']);
        exit;
    }

    $reports = readReports($dbFile);
    [$reports, ] = ensureShortFolios($dbFile, $reports);
    $updatedReport = null;

    foreach ($reports as &$r) {
        if (($r['id'] ?? '') === $id) {
            if (isset($input['estado']) && trim($input['estado']) !== '') {
                $r['estado'] = trim($input['estado']);
            }
            if (isset($input['trabajador']) && trim($input['trabajador']) !== '') {
                $r['trabajador'] = trim($input['trabajador']);
            }
            if (isset($input['solicita'])) {
                $r['solicita'] = trim((string)$input['solicita']);
            }
            if (isset($input['lugar']) && trim($input['lugar']) !== '') {
                $r['lugar'] = trim($input['lugar']);
            }
            if (isset($input['trabajo']) && trim($input['trabajo']) !== '') {
                $r['trabajo'] = trim($input['trabajo']);
            }
            if (isset($input['tipoHorario']) && in_array(trim($input['tipoHorario']), ['Normal', 'Emergencia'], true)) {
                $r['tipoHorario'] = trim($input['tipoHorario']);
            }
            $r['updatedAt'] = gmdate('Y-m-d\TH:i:s.v\Z');
            $updatedReport = $r;
            break;
        }
    }
    unset($r);

    if (!$updatedReport) {
        http_response_code(404);
        echo json_encode(['error' => 'Reporte no encontrado']);
        exit;
    }

    writeReports($dbFile, $reports);
    echo json_encode(['ok' => true, 'report' => $updatedReport], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'POST' && $action === 'upload_photo') {
    $reportId = trim((string)($_POST['id'] ?? ''));
    $clientUid = trim((string)($_POST['clientUid'] ?? ''));
    if ($reportId === '' && $clientUid === '') {
        http_response_code(400);
        echo json_encode(['error' => 'ID de reporte requerido']);
        exit;
    }

    $reports = readReports($dbFile);
    [$reports, ] = ensureShortFolios($dbFile, $reports);
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic'];
    $found = false;
    $addedPhotos = [];

    foreach ($reports as &$r) {
        if (($reportId !== '' && ($r['id'] ?? '') === $reportId) || ($clientUid !== '' && ($r['clientUid'] ?? '') === $clientUid)) {
            $found = true;
            if (!isset($r['fotos']) || !is_array($r['fotos'])) {
                $r['fotos'] = [];
            }
            if (isset($_FILES['fotos'])) {
                $files = $_FILES['fotos'];
                $count = is_array($files['name']) ? count($files['name']) : 1;
                for ($i = 0; $i < $count; $i++) {
                    if (count($r['fotos']) >= 8) break;
                    $err = is_array($files['error']) ? $files['error'][$i] : $files['error'];
                    if ($err !== UPLOAD_ERR_OK) continue;
                    $tmpName = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
                    $origName = is_array($files['name']) ? $files['name'][$i] : $files['name'];
                    $ext = strtolower(pathinfo($origName ?: 'foto.jpg', PATHINFO_EXTENSION));
                    if (!in_array($ext, $allowedExtensions, true)) $ext = 'jpg';
                    $idxNum = count($r['fotos']) + 1;
                    $newFilename = $r['id'] . '-' . $idxNum . '-' . bin2hex(random_bytes(3)) . '.' . $ext;
                    $destPath = $uploadsDir . DIRECTORY_SEPARATOR . $newFilename;
                    if (move_uploaded_file($tmpName, $destPath)) {
                        $photoEntry = ['nombre' => $origName, 'url' => 'uploads/' . $newFilename];
                        $r['fotos'][] = $photoEntry;
                        $addedPhotos[] = $photoEntry;
                    }
                }
            }
            break;
        }
    }
    unset($r);

    if (!$found) {
        http_response_code(404);
        echo json_encode(['error' => 'Reporte no encontrado para adjuntar foto']);
        exit;
    }

    writeReports($dbFile, $reports);
    echo json_encode(['ok' => true, 'added' => count($addedPhotos)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'GET') {
    checkAdminPin();

    $reports = readReports($dbFile);
    [$reports, ] = ensureShortFolios($dbFile, $reports);

    $q = isset($_GET['q']) ? mb_strtolower(trim($_GET['q']), 'UTF-8') : '';
    $desde = isset($_GET['desde']) ? trim($_GET['desde']) : '';
    $hasta = isset($_GET['hasta']) ? trim($_GET['hasta']) : '';
    $estado = isset($_GET['estado']) ? trim($_GET['estado']) : '';

    if ($q !== '') {
        $reports = array_filter($reports, function ($r) use ($q) {
            $haystack = mb_strtolower(
                ($r['id'] ?? '') . ' ' .
                ($r['lugar'] ?? '') . ' ' .
                ($r['trabajo'] ?? '') . ' ' .
                ($r['trabajador'] ?? '') . ' ' .
                ($r['solicita'] ?? ''),
                'UTF-8'
            );
            return mb_strpos($haystack, $q) !== false;
        });
    }

    if ($desde !== '') {
        $reports = array_filter($reports, function ($r) use ($desde) {
            return ($r['fechaInicio'] ?? '') >= $desde;
        });
    }

    if ($hasta !== '') {
        $reports = array_filter($reports, function ($r) use ($hasta) {
            return ($r['fechaInicio'] ?? '') <= $hasta;
        });
    }

    if ($estado !== '') {
        $reports = array_filter($reports, function ($r) use ($estado) {
            return ($r['estado'] ?? '') === $estado;
        });
    }

    usort($reports, function ($a, $b) {
        return strcmp($b['createdAt'] ?? '', $a['createdAt'] ?? '');
    });

    echo json_encode(array_values($reports), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'POST') {
    $rawReport = $_POST['report'] ?? '';
    if (!$rawReport) {
        $body = json_decode(file_get_contents('php://input'), true);
        if (is_array($body) && isset($body['report'])) {
            $r = is_array($body['report']) ? $body['report'] : json_decode($body['report'], true);
        } else {
            $r = $body;
        }
    } else {
        $r = json_decode($rawReport, true);
    }

    if (!is_array($r)) {
        http_response_code(400);
        echo json_encode(['error' => 'Formato de reporte inválido']);
        exit;
    }

    if (
        empty(trim((string)($r['trabajador'] ?? ''))) ||
        empty(trim((string)($r['lugar'] ?? ''))) ||
        empty(trim((string)($r['trabajo'] ?? ''))) ||
        empty(trim((string)($r['horaInicio'] ?? ''))) ||
        empty(trim((string)($r['horaTermino'] ?? '')))
    ) {
        http_response_code(400);
        echo json_encode(['error' => 'Faltan datos obligatorios (trabajador, lugar, trabajo u horario)']);
        exit;
    }

    $reports = readReports($dbFile);
    [$reports, $maxNum] = ensureShortFolios($dbFile, $reports);

    $clientUid = trim((string)($r['clientUid'] ?? ($r['id'] ?? '')));
    if ($clientUid !== '') {
        foreach ($reports as $existing) {
            if (($existing['clientUid'] ?? '') === $clientUid || ($existing['id'] ?? '') === $clientUid) {
                echo json_encode([
                    'ok' => true,
                    'deduplicated' => true,
                    'report' => $existing
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
    }

    $nextNum = $maxNum + 1;
    $r['id'] = sprintf('RT-%04d', $nextNum);
    $r['clientUid'] = $clientUid ?: ('UID-' . round(microtime(true) * 1000));
    $r['trabajador'] = trim((string)$r['trabajador']);
    $r['tipoHorario'] = (isset($r['tipoHorario']) && trim((string)$r['tipoHorario']) === 'Emergencia') ? 'Emergencia' : 'Normal';
    $r['createdAt'] = gmdate('Y-m-d\TH:i:s.v\Z');
    $r['estadoEnvio'] = 'RECIBIDO';

    $savedPhotos = [];
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic'];

    if (isset($_FILES['fotos'])) {
        $files = $_FILES['fotos'];
        $count = is_array($files['name']) ? count($files['name']) : 1;
        $count = min($count, 8);

        for ($i = 0; $i < $count; $i++) {
            $err = is_array($files['error']) ? $files['error'][$i] : $files['error'];
            if ($err !== UPLOAD_ERR_OK) {
                continue;
            }
            $tmpName = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
            $origName = is_array($files['name']) ? $files['name'][$i] : $files['name'];
            $ext = strtolower(pathinfo($origName ?: 'foto.jpg', PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExtensions, true)) {
                $ext = 'jpg';
            }

            $newFilename = $r['id'] . '-' . ($i + 1) . '-' . bin2hex(random_bytes(3)) . '.' . $ext;
            $destPath = $uploadsDir . DIRECTORY_SEPARATOR . $newFilename;

            if (move_uploaded_file($tmpName, $destPath)) {
                $savedPhotos[] = [
                    'nombre' => $origName,
                    'url' => 'uploads/' . $newFilename
                ];
            }
        }
    }

    if (empty($savedPhotos) && !empty($r['fotosBase64']) && is_array($r['fotosBase64'])) {
        foreach (array_slice($r['fotosBase64'], 0, 8) as $idx => $fObj) {
            $dataUrl = $fObj['dataUrl'] ?? '';
            $origName = $fObj['nombre'] ?? 'foto.jpg';
            if (preg_match('/^data:image\/(\w+);base64,(.+)$/', $dataUrl, $matches)) {
                $ext = strtolower($matches[1]);
                if ($ext === 'jpeg') $ext = 'jpg';
                if (!in_array($ext, $allowedExtensions, true)) $ext = 'jpg';
                $binary = base64_decode($matches[2]);
                if ($binary !== false) {
                    $newFilename = $r['id'] . '-' . ($idx + 1) . '-' . bin2hex(random_bytes(3)) . '.' . $ext;
                    $destPath = $uploadsDir . DIRECTORY_SEPARATOR . $newFilename;
                    if (file_put_contents($destPath, $binary) !== false) {
                        $savedPhotos[] = [
                            'nombre' => $origName,
                            'url' => 'uploads/' . $newFilename
                        ];
                    }
                }
            }
        }
    }
    unset($r['fotosBase64']);

    $r['fotos'] = $savedPhotos;
    $reports[] = $r;

    if (!writeReports($dbFile, $reports)) {
        http_response_code(500);
        echo json_encode(['error' => 'No se pudo guardar el reporte en el disco']);
        exit;
    }

    http_response_code(201);
    echo json_encode([
        'ok' => true,
        'report' => $r
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Método no permitido']);
