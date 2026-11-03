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
$notifyConfigFile = $dataDir . DIRECTORY_SEPARATOR . 'notificaciones.json';

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}
if (!is_dir($uploadsDir)) {
    mkdir($uploadsDir, 0755, true);
}
if (!file_exists($dbFile)) {
    file_put_contents($dbFile, "[]", LOCK_EX);
}

$defaultNotifyConfig = [
    'subfolderName' => 'notificacion reports',
    'emails' => [
        'c.huerta@mycmantencion.cl',
        'f.abarca@mycmantencion.cl',
        'j.miranda@mycmantencion.cl'
    ],
    'phones' => [
        ['phone' => '+56968352481', 'apikey' => '', 'nombre' => 'Teléfono 1'],
        ['phone' => '+56953945041', 'apikey' => '', 'nombre' => 'Teléfono 2'],
        ['phone' => '+56953236814', 'apikey' => '', 'nombre' => 'Teléfono 3'],
        ['phone' => '+56995469653', 'apikey' => '', 'nombre' => 'Teléfono 4']
    ]
];

if (!file_exists($notifyConfigFile)) {
    file_put_contents($notifyConfigFile, json_encode($defaultNotifyConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function getNotifyConfig(string $notifyConfigFile, array $defaultNotifyConfig): array {
    if (!file_exists($notifyConfigFile)) {
        return $defaultNotifyConfig;
    }
    $decoded = json_decode((string)file_get_contents($notifyConfigFile), true);
    if (!is_array($decoded)) {
        return $defaultNotifyConfig;
    }
    return array_merge($defaultNotifyConfig, $decoded);
}

/**
 * Detecta el directorio raíz de la cuenta cPanel (/home/usuario)
 */
function getCpanelHomeDir(): string {
    $candidates = [];
    if (!empty($_SERVER['HOME'])) {
        $candidates[] = $_SERVER['HOME'];
    }
    $candidates[] = dirname(__DIR__, 2);
    $candidates[] = dirname(__DIR__, 1);
    if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $pw = @posix_getpwuid(@posix_geteuid());
        if (!empty($pw['dir'])) {
            $candidates[] = $pw['dir'];
        }
    }
    foreach ($candidates as $dir) {
        if ($dir && is_dir($dir . DIRECTORY_SEPARATOR . 'mail')) {
            return rtrim($dir, '/\\');
        }
    }
    return dirname(__DIR__, 2);
}

/**
 * Crea automáticamente la subcarpeta "notificacion reports" dentro de la Bandeja de Entrada (Maildir++)
 * de cada correo de mycmantencion.cl en cPanel y la suscribe en IMAP (Roundcube / Outlook / Celular).
 */
function ensureMailSubfolders(array $emails, string $subfolderName = 'notificacion reports'): array {
    $home = getCpanelHomeDir();
    $cleanFolder = trim(str_replace(['/', '\\', '..'], '', $subfolderName)) ?: 'notificacion reports';
    $results = [];

    foreach ($emails as $email) {
        $email = strtolower(trim((string)$email));
        if (strpos($email, '@') === false) {
            continue;
        }
        [$user, $domain] = explode('@', $email, 2);
        $maildir = $home . DIRECTORY_SEPARATOR . 'mail' . DIRECTORY_SEPARATOR . $domain . DIRECTORY_SEPARATOR . $user;
        $subDir = $maildir . DIRECTORY_SEPARATOR . '.' . $cleanFolder;

        if (!is_dir($maildir)) {
            $results[$email] = ['ok' => false, 'status' => 'Buzón local no encontrado en ' . $maildir . ' (se usará envío estándar SMTP/mail)'];
            continue;
        }

        if (!is_dir($subDir)) {
            @mkdir($subDir, 0751, true);
        }
        foreach (['cur', 'new', 'tmp'] as $part) {
            $pDir = $subDir . DIRECTORY_SEPARATOR . $part;
            if (!is_dir($pDir)) {
                @mkdir($pDir, 0751, true);
            }
        }
        $folderMarker = $subDir . DIRECTORY_SEPARATOR . 'maildirfolder';
        if (!file_exists($folderMarker)) {
            @file_put_contents($folderMarker, '');
            @chmod($folderMarker, 0640);
        }

        // Suscribir la subcarpeta en Dovecot IMAP para que aparezca de inmediato bajo Bandeja de Entrada
        $subsFile = $maildir . DIRECTORY_SEPARATOR . 'subscriptions';
        $subsContent = file_exists($subsFile) ? (string)file_get_contents($subsFile) : '';
        $lines = array_filter(array_map('trim', explode("\n", $subsContent)), 'strlen');
        $changedSubs = false;
        foreach ([$cleanFolder, 'INBOX.' . $cleanFolder] as $subEntry) {
            if (!in_array($subEntry, $lines, true)) {
                $lines[] = $subEntry;
                $changedSubs = true;
            }
        }
        if ($changedSubs) {
            @file_put_contents($subsFile, implode("\n", $lines) . "\n", LOCK_EX);
        }

        // Configurar también filtro Exim de respaldo en /home/<user>/etc/<domain>/<user>/filter
        $etcUserDir = $home . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . $domain . DIRECTORY_SEPARATOR . $user;
        if (!is_dir($etcUserDir)) {
            @mkdir($etcUserDir, 0750, true);
        }
        $filterFile = $etcUserDir . DIRECTORY_SEPARATOR . 'filter';
        $ruleMarker = '# REPORTE-TERRENO-MYC-FILTER';
        $existingFilter = file_exists($filterFile) ? (string)file_get_contents($filterFile) : "# Exim filter <<== do not edit or remove this line!\n";
        if (strpos($existingFilter, $ruleMarker) === false) {
            $rule = "\n$ruleMarker\nif \$header_X-MYC-Report: contains \"yes\" or \$header_subject: contains \"[REPORTE-MYC]\" then\n    save \"\$home/mail/$domain/$user/.$cleanFolder/\" 0660\nendif\n";
            @file_put_contents($filterFile, rtrim($existingFilter) . "\n" . $rule);
        }

        $results[$email] = [
            'ok' => is_dir($subDir . DIRECTORY_SEPARATOR . 'new'),
            'folder' => 'INBOX / ' . $cleanFolder,
            'maildir' => $subDir
        ];
    }

    return $results;
}

/**
 * Analiza horario del reporte según reglas internas de Logística MYC SpA
 */
function analizarHorarioServidor(array $r): array {
    $declarado = (isset($r['tipoHorario']) && trim((string)$r['tipoHorario']) === 'Emergencia') ? 'Emergencia' : 'Normal';
    $fIni = trim((string)($r['fechaInicio'] ?? date('Y-m-d')));
    $fTer = trim((string)($r['fechaTermino'] ?? $fIni)) ?: $fIni;
    $hIniStr = trim((string)($r['horaInicio'] ?? '08:00'));
    $hTerStr = trim((string)($r['horaTermino'] ?? '17:30'));

    $p1 = explode(':', $hIniStr);
    $p2 = explode(':', $hTerStr);
    $minInicio = ((int)($p1[0] ?? 8)) * 60 + ((int)($p1[1] ?? 0));
    $minTerminoBase = ((int)($p2[0] ?? 17)) * 60 + ((int)($p2[1] ?? 30));
    $cruzaDia = ($fTer > $fIni) || ($minTerminoBase < $minInicio);
    $minTerminoEfectivo = $cruzaDia ? ($minTerminoBase + 1440) : $minTerminoBase;

    $ts = strtotime($fIni . ' 12:00:00');
    $diaSemana = $ts ? (int)date('w', $ts) : 1; // 0=Dom, 6=Sab
    $nombres = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
    $nombreDia = $nombres[$diaSemana] ?? 'Día hábil';

    $feriadosFijos = ['01-01', '05-01', '05-21', '06-29', '07-16', '08-15', '09-18', '09-19', '10-31', '11-01', '12-08', '12-25'];
    $feriadosMoviles = [
        '2025-04-18', '2025-04-19', '2025-06-20', '2025-10-12', '2025-11-16', '2025-12-14',
        '2026-04-03', '2026-04-04', '2026-06-21', '2026-06-29', '2026-09-20', '2026-10-12',
        '2027-03-26', '2027-03-27', '2027-06-21', '2027-06-28', '2027-09-17', '2027-10-11', '2027-11-02'
    ];
    $esFestivo = in_array($fIni, $feriadosMoviles, true) || in_array(substr($fIni, 5, 5), $feriadosFijos, true);
    $esFinDeSemanaOFestivo = ($diaSemana === 0 || $diaSemana === 6 || $esFestivo);

    $minEntrada = 480; // 08:00
    $minSalida = ($diaSemana === 5) ? 1020 : 1050; // Viernes 17:00, Lun-Jue 17:30
    $txtSalida = ($diaSemana === 5) ? '17:00' : '17:30';

    $supera1730 = $minTerminoEfectivo > 1050;
    $superaSalida = $minTerminoEfectivo > $minSalida;
    $iniciaAntes0800 = $minInicio < $minEntrada;
    $iniciaDespuesSalida = $minInicio >= $minSalida;

    $fmtMin = function (int $m): string {
        $m = max(0, $m);
        $h = intdiv($m, 60);
        $r = $m % 60;
        if ($h > 0 && $r > 0) return "{$h}h {$r}m";
        if ($h > 0) return "{$h}h";
        return "{$r}m";
    };

    $tieneMarcador = false;
    $etiquetaPago = '✅ HORARIO HABITUAL (Sin recargo)';
    $marcador = '';
    $detalles = [];

    if ($esFinDeSemanaOFestivo) {
        $tieneMarcador = true;
        $motivo = $esFestivo ? "Festivo ($nombreDia)" : $nombreDia;
        $etiquetaPago = "🚨 PAGO EMERGENCIA ($motivo)";
        $marcador = "🚩 " . mb_strtoupper($motivo, 'UTF-8') . ($supera1730 ? " + EXTENDIDO > 17:30 ($hTerStr)" : "");
        $detalles[] = "Trabajo realizado en $motivo: se paga como Emergencia.";
    } elseif ($iniciaDespuesSalida) {
        $tieneMarcador = true;
        $etiquetaPago = "🚨 PAGO EMERGENCIA (Inició después de las $txtSalida)";
        $marcador = "🚩 INICIO POST-HORARIO ($hIniStr–$hTerStr)";
        $detalles[] = "Inició a las $hIniStr (después de las $txtSalida del $nombreDia): se paga como Emergencia.";
    } else {
        if ($iniciaAntes0800) {
            $tieneMarcador = true;
            $etiquetaPago = "⏰ PAGO HORA EXTRA";
            $detalles[] = "Inició antes de las 08:00 ($hIniStr, +" . $fmtMin($minEntrada - $minInicio) . " H. Extra matinal).";
        }
        if ($superaSalida) {
            $tieneMarcador = true;
            $etiquetaPago = "⏰ PAGO HORA EXTRA";
            $marcador = $supera1730
                ? "🚩 EXTENDIDO > 17:30 HRS (hasta $hTerStr, +" . $fmtMin($minTerminoEfectivo - 1050) . ")"
                : "🚩 EXTENDIDO > 17:00 VIE (hasta $hTerStr, +" . $fmtMin($minTerminoEfectivo - $minSalida) . ")";
            $detalles[] = "Se extendió después de las $txtSalida ($nombreDia, +" . $fmtMin($minTerminoEfectivo - $minSalida) . " H. Extra).";
        }
        if (!$tieneMarcador && $declarado === 'Emergencia') {
            $tieneMarcador = true;
            $etiquetaPago = "🚨 PAGO EMERGENCIA (Declarado por trabajador)";
            $marcador = "🚩 DECLARADO EMERGENCIA EN TERRENO";
            $detalles[] = "Declarado manualmente como Emergencia por el trabajador.";
        }
    }

    return [
        'declarado' => $declarado,
        'nombreDia' => $nombreDia,
        'tieneMarcador' => $tieneMarcador,
        'etiquetaPago' => $etiquetaPago,
        'marcador' => $marcador,
        'detalle' => $detalles ? implode(' | ', $detalles) : "Dentro de horario habitual ($nombreDia 08:00–$txtSalida)."
    ];
}

/**
 * Entrega el aviso de reporte directamente en la subcarpeta "notificacion reports" de cada buzón
 * (y envía por WhatsApp a los teléfonos configurados).
 */
function enviarNotificacionesNuevoReporte(array $r, string $notifyConfigFile, array $defaultNotifyConfig): array {
    $cfg = getNotifyConfig($notifyConfigFile, $defaultNotifyConfig);
    $emails = $cfg['emails'] ?? [];
    $subfolderName = $cfg['subfolderName'] ?? 'notificacion reports';
    $folderStatus = ensureMailSubfolders($emails, $subfolderName);
    $infoHor = analizarHorarioServidor($r);

    $folio = htmlspecialchars((string)($r['id'] ?? ''), ENT_QUOTES, 'UTF-8');
    $lugar = htmlspecialchars((string)($r['lugar'] ?? ''), ENT_QUOTES, 'UTF-8');
    $trabajador = htmlspecialchars((string)($r['trabajador'] ?? 'No indicado'), ENT_QUOTES, 'UTF-8');
    $fechaIni = htmlspecialchars((string)($r['fechaInicio'] ?? ''), ENT_QUOTES, 'UTF-8');
    $fechaTer = htmlspecialchars((string)($r['fechaTermino'] ?? $fechaIni), ENT_QUOTES, 'UTF-8');
    $horario = htmlspecialchars(($r['horaInicio'] ?? '') . ' a ' . ($r['horaTermino'] ?? ''), ENT_QUOTES, 'UTF-8');
    $personas = htmlspecialchars((string)($r['personas'] ?? '1'), ENT_QUOTES, 'UTF-8');
    $estado = htmlspecialchars((string)($r['estado'] ?? 'Terminado'), ENT_QUOTES, 'UTF-8');
    $trabajo = nl2br(htmlspecialchars((string)($r['trabajo'] ?? ''), ENT_QUOTES, 'UTF-8'));

    $matList = [];
    foreach (($r['materiales'] ?? []) as $m) {
        $matList[] = htmlspecialchars(trim(($m['cantidad'] ?? '') . ' ' . ($m['unidad'] ?? '') . ' ' . ($m['descripcion'] ?? '')), ENT_QUOTES, 'UTF-8');
    }
    $matHtml = $matList ? implode('<br>• ', $matList) : 'Sin materiales registrados';
    if ($matList) $matHtml = '• ' . $matHtml;

    $maqList = [];
    foreach (($r['maquinaria'] ?? []) as $m) {
        $maqList[] = htmlspecialchars(trim(($m['cantidad'] ?? '') . ' ' . ($m['unidad'] ?? '') . ' ' . ($m['descripcion'] ?? '')), ENT_QUOTES, 'UTF-8');
    }
    $maqHtml = $maqList ? implode('<br>• ', $maqList) : 'Sin maquinaria registrada';
    if ($maqList) $maqHtml = '• ' . $maqHtml;

    $cantFotos = count($r['fotos'] ?? []);
    $alertaSubject = $infoHor['tieneMarcador'] ? ' 🚨 [' . $infoHor['etiquetaPago'] . ']' : '';
    $rawSubject = "[REPORTE-MYC] 📋 {$r['id']} · {$r['lugar']} ({$r['trabajador']}){$alertaSubject}";
    $encodedSubject = '=?UTF-8?B?' . base64_encode($rawSubject) . '?=';

    $bgHorario = $infoHor['tieneMarcador'] ? '#fef2f2' : '#f0fdf4';
    $borderHorario = $infoHor['tieneMarcador'] ? '#fca5a5' : '#86efac';
    $colorHorario = $infoHor['tieneMarcador'] ? '#991b1b' : '#166534';

    $htmlBody = "<!doctype html><html><head><meta charset='utf-8'></head><body style='font-family:Segoe UI,Arial,sans-serif;background:#f3f5f7;padding:18px;color:#1f2937'>
    <div style='max-width:640px;margin:auto;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #cbd5e1'>
      <div style='background:#17324d;color:#ffffff;padding:16px 20px'>
        <h2 style='margin:0;font-size:18px'>📋 Nuevo Reporte de Terreno — Folio {$folio}</h2>
        <p style='margin:4px 0 0;font-size:12.5px;opacity:0.85'>Inversiones y Mantenimiento MYC SpA · Recepción Automática</p>
      </div>
      <div style='padding:18px 20px'>
        <div style='background:{$bgHorario};border:1px solid {$borderHorario};color:{$colorHorario};padding:12px 14px;border-radius:8px;margin-bottom:14px;font-size:13px'>
          <strong>🔒 Control Horario (Exclusivo Logística):</strong> " . htmlspecialchars($infoHor['etiquetaPago'], ENT_QUOTES, 'UTF-8') . "<br>
          " . ($infoHor['marcador'] ? "<strong>Marcador:</strong> " . htmlspecialchars($infoHor['marcador'], ENT_QUOTES, 'UTF-8') . "<br>" : "") . "
          <strong>Declarado por trabajador:</strong> " . htmlspecialchars($infoHor['declarado'], ENT_QUOTES, 'UTF-8') . "<br>
          <span>" . htmlspecialchars($infoHor['detalle'], ENT_QUOTES, 'UTF-8') . "</span>
        </div>
        <table style='width:100%;border-collapse:collapse;font-size:13.5px;margin-bottom:14px'>
          <tr><td style='padding:7px 10px;border:1px solid #e2e8f0;background:#f8fafc;width:32%'><strong>Lugar / Plantel</strong></td><td style='padding:7px 10px;border:1px solid #e2e8f0'><strong>{$lugar}</strong></td></tr>
          <tr><td style='padding:7px 10px;border:1px solid #e2e8f0;background:#f8fafc'><strong>Generado por</strong></td><td style='padding:7px 10px;border:1px solid #e2e8f0'>👷 {$trabajador} ({$personas} pers. en cuadrilla)</td></tr>
          <tr><td style='padding:7px 10px;border:1px solid #e2e8f0;background:#f8fafc'><strong>Fecha y Día</strong></td><td style='padding:7px 10px;border:1px solid #e2e8f0'>{$fechaIni} (" . htmlspecialchars($infoHor['nombreDia'], ENT_QUOTES, 'UTF-8') . ") al {$fechaTer}</td></tr>
          <tr><td style='padding:7px 10px;border:1px solid #e2e8f0;background:#f8fafc'><strong>Horario</strong></td><td style='padding:7px 10px;border:1px solid #e2e8f0'>{$horario}</td></tr>
          <tr><td style='padding:7px 10px;border:1px solid #e2e8f0;background:#f8fafc'><strong>Estado</strong></td><td style='padding:7px 10px;border:1px solid #e2e8f0'>{$estado} ({$cantFotos} foto(s) adjuntas)</td></tr>
        </table>
        <div style='margin-bottom:12px'>
          <strong style='font-size:13px;color:#17324d'>🛠️ Trabajo Realizado:</strong>
          <div style='background:#f8fafc;border:1px solid #e2e8f0;padding:10px 12px;border-radius:6px;margin-top:4px;font-size:13.5px'>{$trabajo}</div>
        </div>
        <div style='margin-bottom:12px;font-size:13px'>
          <strong style='color:#17324d'>📦 Materiales:</strong><br>{$matHtml}
        </div>
        <div style='margin-bottom:16px;font-size:13px'>
          <strong style='color:#17324d'>🚜 Maquinaria / Equipos:</strong><br>{$maqHtml}
        </div>
        <div style='text-align:center;margin-top:18px'>
          <a href='https://reporte.mycmantencion.cl/#logistica' style='background:#17324d;color:#ffffff;text-decoration:none;padding:11px 20px;border-radius:8px;font-weight:bold;font-size:13.5px;display:inline-block'>💻 Abrir Panel de Logística y Cotizaciones MYC</a>
        </div>
      </div>
    </div></body></html>";

    $mailResults = [];
    $hostname = gethostname() ?: 'mycmantencion.cl';
    $dateRfc = date('r');
    $msgId = '<' . uniqid('rtmyc-', true) . '@mycmantencion.cl>';

    foreach ($emails as $email) {
        $email = strtolower(trim((string)$email));
        if (!$email) continue;
        $st = $folderStatus[$email] ?? null;

        // Si el buzón existe en el servidor cPanel, depositamos el correo directamente en la subcarpeta "notificacion reports"
        if ($st && !empty($st['ok']) && !empty($st['maildir'])) {
            $newDir = $st['maildir'] . DIRECTORY_SEPARATOR . 'new';
            $rawMime = "Return-Path: <notificaciones@mycmantencion.cl>\r\n"
                . "Delivered-To: {$email}\r\n"
                . "To: <{$email}>\r\n"
                . "From: \"Reporte de Terreno MYC\" <notificaciones@mycmantencion.cl>\r\n"
                . "Reply-To: notificaciones@mycmantencion.cl\r\n"
                . "Subject: {$encodedSubject}\r\n"
                . "Date: {$dateRfc}\r\n"
                . "Message-ID: {$msgId}\r\n"
                . "X-MYC-Report: yes\r\n"
                . "MIME-Version: 1.0\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: 8bit\r\n\r\n"
                . $htmlBody . "\r\n";

            $fileName = time() . '.M' . mt_rand(100000, 999999) . 'P' . getmypid() . '.' . $hostname . ',S=' . strlen($rawMime);
            $written = @file_put_contents($newDir . DIRECTORY_SEPARATOR . $fileName, $rawMime);
            if ($written !== false) {
                @chmod($newDir . DIRECTORY_SEPARATOR . $fileName, 0640);
                $mailResults[$email] = 'Entregado directo en subcarpeta INBOX/' . $subfolderName;
                continue;
            }
        }

        // Respaldo mediante mail() estándar
        $headers = "MIME-Version: 1.0\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "From: Reporte de Terreno MYC <notificaciones@mycmantencion.cl>\r\n"
            . "X-MYC-Report: yes\r\n";
        $sent = @mail($email, $encodedSubject, $htmlBody, $headers);
        $mailResults[$email] = $sent ? 'Enviado por servidor SMTP/mail' : 'Error al enviar correo';
    }

    // Envío automático a los teléfonos por WhatsApp (si tienen API Key de CallMeBot configurada)
    $waText = "📋 *NUEVO REPORTE DE TERRENO {$r['id']}*\n"
        . "📍 *Lugar:* {$r['lugar']}\n"
        . "👷 *Por:* {$r['trabajador']} ({$r['personas']} pers.)\n"
        . "📅 *Fecha:* {$r['fechaInicio']} ({$infoHor['nombreDia']})\n"
        . "🕒 *Horario:* {$r['horaInicio']} a {$r['horaTermino']}\n"
        . "🔒 *Logística:* {$infoHor['etiquetaPago']}" . ($infoHor['marcador'] ? " · {$infoHor['marcador']}" : "") . "\n"
        . "🛠️ *Trabajo:* " . mb_substr(preg_replace('/\s+/', ' ', (string)($r['trabajo'] ?? '')), 0, 180, 'UTF-8') . "\n"
        . "🔗 https://reporte.mycmantencion.cl/#logistica";

    $waResults = [];
    foreach (($cfg['phones'] ?? []) as $phObj) {
        $phone = preg_replace('/[^0-9+]/', '', (string)($phObj['phone'] ?? ''));
        $apikey = trim((string)($phObj['apikey'] ?? ''));
        if ($phone === '' || $apikey === '') {
            $waResults[$phone ?: 'sin-numero'] = 'Pendiente de clave CallMeBot';
            continue;
        }
        $cleanPhone = ltrim($phone, '+');
        $url = 'https://api.callmebot.com/whatsapp.php?phone=' . urlencode($cleanPhone)
            . '&text=' . urlencode($waText)
            . '&apikey=' . urlencode($apikey);
        $ctx = stream_context_create([
            'http' => ['timeout' => 4, 'ignore_errors' => true],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
        ]);
        $resp = @file_get_contents($url, false, $ctx);
        $waResults[$phone] = ($resp !== false) ? 'WhatsApp enviado' : 'Tiempo de espera agotado';
    }

    return [
        'mail' => $mailResults,
        'folders' => $folderStatus,
        'whatsapp' => $waResults
    ];
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
    $cfg = getNotifyConfig($notifyConfigFile, $defaultNotifyConfig);
    $folders = ensureMailSubfolders($cfg['emails'] ?? [], $cfg['subfolderName'] ?? 'notificacion reports');
    echo json_encode(['ok' => true, 'server' => 'php-cpanel-v10', 'timestamp' => gmdate('c'), 'folders' => $folders], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'GET' && $action === 'notify_config') {
    checkAdminPin();
    $cfg = getNotifyConfig($notifyConfigFile, $defaultNotifyConfig);
    $folders = ensureMailSubfolders($cfg['emails'] ?? [], $cfg['subfolderName'] ?? 'notificacion reports');
    echo json_encode(['ok' => true, 'config' => $cfg, 'folders' => $folders], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'POST' && $action === 'save_notify_config') {
    checkAdminPin();
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $cfg = getNotifyConfig($notifyConfigFile, $defaultNotifyConfig);
    if (isset($input['emails']) && is_array($input['emails'])) {
        $cfg['emails'] = array_values(array_filter(array_map('trim', $input['emails'])));
    }
    if (isset($input['subfolderName']) && trim((string)$input['subfolderName']) !== '') {
        $cfg['subfolderName'] = trim((string)$input['subfolderName']);
    }
    if (isset($input['phones']) && is_array($input['phones'])) {
        $cfg['phones'] = $input['phones'];
    }
    file_put_contents($notifyConfigFile, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    $folders = ensureMailSubfolders($cfg['emails'] ?? [], $cfg['subfolderName'] ?? 'notificacion reports');
    echo json_encode(['ok' => true, 'config' => $cfg, 'folders' => $folders], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'POST' && $action === 'test_notify') {
    checkAdminPin();
    $sampleReport = [
        'id' => 'RT-PRUEBA',
        'lugar' => 'OF CENTRAL RGUA (PRUEBA DE AVISO)',
        'trabajador' => 'Sistema de Notificaciones MYC',
        'fechaInicio' => date('Y-m-d'),
        'fechaTermino' => date('Y-m-d'),
        'horaInicio' => '08:30',
        'horaTermino' => '18:15',
        'personas' => '2',
        'tipoHorario' => 'Normal',
        'estado' => 'Terminado',
        'trabajo' => 'Correo y aviso de prueba automático para verificar la creación de la subcarpeta "notificacion reports" en Bandeja de Entrada y el envío de alertas.',
        'materiales' => [],
        'maquinaria' => [],
        'fotos' => []
    ];
    $res = enviarNotificacionesNuevoReporte($sampleReport, $notifyConfigFile, $defaultNotifyConfig);
    echo json_encode(['ok' => true, 'result' => $res], JSON_UNESCAPED_UNICODE);
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

    // Enviar aviso automático a los 3 correos (en subcarpeta "notificacion reports") y WhatsApp configurados
    $notifyResult = [];
    try {
        $notifyResult = enviarNotificacionesNuevoReporte($r, $notifyConfigFile, $defaultNotifyConfig);
    } catch (\Throwable $e) {
        $notifyResult = ['error' => $e->getMessage()];
    }

    http_response_code(201);
    echo json_encode([
        'ok' => true,
        'report' => $r,
        'notifications' => $notifyResult
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Método no permitido']);
