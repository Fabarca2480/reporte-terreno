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
$workersFile = $dataDir . DIRECTORY_SEPARATOR . 'trabajadores.json';

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}
if (!is_dir($uploadsDir)) {
    mkdir($uploadsDir, 0755, true);
}
if (!file_exists($dbFile)) {
    file_put_contents($dbFile, "[]", LOCK_EX);
}

$defaultWorkersCatalog = [
    'ABELDAÑO MARTINEZ JAIRO JAVIER',
    'ALIAGA PARDO KEVIN WILLIAMS',
    'ALVAREZ MUÑOZ ALFONSO',
    'ARAUJO CASTILLO WILLIAMS JOSE',
    'AREVALO VASQUEZ RODOLFO ALEJANDRO',
    'BECERRA FLORES MATIAS ANDRES',
    'BETANCOURT OCORO JULIAN ADOLFO',
    'BUZO BECERRA GUSTAVO ERNESTO',
    'CASTILLO VASQUEZ JUAN CAMILO',
    'CENTENO JUAN CARLOS',
    'CERDA OLMEDO MARCELO ALEXANDER',
    'CONTRERAS CORREA SANDRO ADAN',
    'CORREA VENEGAS LUIS HERNAN',
    'DONOSO LABRA RAUL ANTONIO',
    'ESPINOZA DIAZ NELSON ALEJANDRO',
    'FARIAS FUENTES MAURICIO ENRIQUE',
    'FERNANDEZ FERNANDEZ JOSE MIGUEL',
    'FLORES REYES GUILLERMO ENRIQUE',
    'FLORES REYES MARIO JAVIER',
    'FLORES ROJAS JESUS ALEJANDRO',
    'GARCIA-HUIDOBRO FUENTES ORLANDO ANDRES',
    'GARCIA-HUIDOBRO FUENTES PABLO ANDRÉS',
    'GONZALEZ CONTRERAS HECTOR',
    'HERRERA FLORES LUIS ALIRO',
    'HUERTA CONTRERAS GONZALO FRANCISCO',
    'IBARRA ALARCON JULIO ENRIQUE',
    'LOYOLA CORNEJO CLAUDIO OSVALDO',
    'MANZO ALARCON DIEGO ALEJANDRO',
    'MARTINEZ JORQUERA MARCELO ANTONIO',
    'MUÑOZ CEDNA LUIS ANDRES',
    'MUÑOZ CONTRERAS NOLBERTO ANTONIO',
    'MUÑOZ MOYA HECTOR DEL CARMEN',
    'NUÑEZ TORO ISAIAS JESUS',
    'PINEDA RUIZ ANDRES FELIPE',
    'PINTO ARRIAZA JUAN DEL CARMEN',
    'QUINTANILLA CONTREAS CAMILO JOSE',
    'RIOS PEREZ FABIAN ALFREDO',
    'SILVA ARANEDA SEBASTIÁN ANDRÉS',
    'TOBAR GONZALEZ EDUARDO ALEXIS',
    'TOBAR GONZALEZ JULIAN ANSELMO',
    'VIERA CERDA MIGUEL ANDRES',
    'VILLAVICENCIO DEVIA SERGIO DEL CARMEN'
];

if (!file_exists($workersFile)) {
    file_put_contents($workersFile, json_encode($defaultWorkersCatalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function getWorkersCatalog(string $workersFile, array $defaultWorkersCatalog): array {
    if (!file_exists($workersFile)) {
        return $defaultWorkersCatalog;
    }
    $decoded = json_decode((string)file_get_contents($workersFile), true);
    if (!is_array($decoded) || empty($decoded)) {
        return $defaultWorkersCatalog;
    }
    return array_values(array_unique(array_filter(array_map('trim', $decoded))));
}

function registerNewWorkers(string $workersFile, array $defaultWorkersCatalog, array $names): array {
    $current = getWorkersCatalog($workersFile, $defaultWorkersCatalog);
    $lowerMap = [];
    foreach ($current as $w) {
        $lowerMap[mb_strtolower($w, 'UTF-8')] = $w;
    }
    $changed = false;
    foreach ($names as $n) {
        $clean = trim((string)$n);
        if ($clean === '' || mb_strtolower($clean, 'UTF-8') === 'no indicado') continue;
        $key = mb_strtolower($clean, 'UTF-8');
        if (!isset($lowerMap[$key])) {
            $current[] = $clean;
            $lowerMap[$key] = $clean;
            $changed = true;
        }
    }
    if ($changed) {
        @file_put_contents($workersFile, json_encode(array_values($current), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
    return $current;
}

$defaultNotifyConfig = [
    'subfolderName' => 'notificacion reports',
    'ntfyTopic' => 'myc-terreno-77547271',
    'emails' => [
        'c.huerta@mycmantencion.cl',
        'f.abarca@mycmantencion.cl',
        'j.miranda@mycmantencion.cl'
    ],
    'phones' => [
        ['phone' => '+56968352481', 'nombre' => 'Teléfono 1'],
        ['phone' => '+56953945041', 'nombre' => 'Teléfono 2'],
        ['phone' => '+56953236814', 'nombre' => 'Teléfono 3'],
        ['phone' => '+56995469653', 'nombre' => 'Teléfono 4']
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
    $numAviso = htmlspecialchars(trim((string)($r['numeroAviso'] ?? '')), ENT_QUOTES, 'UTF-8');
    $numAvisoRow = $numAviso !== ''
        ? "<tr><td style='padding:7px 10px;border:1px solid #e2e8f0;background:#f8fafc'><strong>N° de Aviso Cliente</strong></td><td style='padding:7px 10px;border:1px solid #e2e8f0'>🔢 <strong>{$numAviso}</strong></td></tr>"
        : '';
    $trabajador = htmlspecialchars((string)($r['trabajador'] ?? 'No indicado'), ENT_QUOTES, 'UTF-8');
    $integrantesArr = isset($r['integrantes']) && is_array($r['integrantes']) ? array_values(array_filter(array_map('trim', $r['integrantes']))) : [];
    $integrantesHtml = $integrantesArr
        ? '<br><span style="color:#475569;font-size:12.5px">👥 <strong>Integrantes cuadrilla:</strong> ' . htmlspecialchars(implode(', ', $integrantesArr), ENT_QUOTES, 'UTF-8') . '</span>'
        : '';
    $recCli = isset($r['recepcionCliente']) && is_array($r['recepcionCliente']) ? $r['recepcionCliente'] : null;
    $recCliHtml = '';
    if ($recCli && (!empty($recCli['firmado']) || !empty($recCli['nombre']))) {
        $estRec = htmlspecialchars((string)($recCli['estado'] ?? 'Recibido Conforme'), ENT_QUOTES, 'UTF-8');
        $nomRec = htmlspecialchars((string)($recCli['nombre'] ?? ''), ENT_QUOTES, 'UTF-8');
        $carRec = htmlspecialchars((string)($recCli['cargo'] ?? ''), ENT_QUOTES, 'UTF-8');
        $obsRec = htmlspecialchars((string)($recCli['observacion'] ?? ''), ENT_QUOTES, 'UTF-8');
        $recCliHtml = "<div style='background:#ecfdf5;border:1px solid #6ee7b7;color:#065f46;padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:13px'>
          <strong>✍️ Recepción del Cliente ({$estRec}):</strong> {$nomRec}" . ($carRec ? " ({$carRec})" : "") . ($obsRec ? "<br><em>\"{$obsRec}\"</em>" : "") . "
        </div>";
    }
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
        {$recCliHtml}
        <table style='width:100%;border-collapse:collapse;font-size:13.5px;margin-bottom:14px'>
          <tr><td style='padding:7px 10px;border:1px solid #e2e8f0;background:#f8fafc;width:32%'><strong>Lugar / Plantel</strong></td><td style='padding:7px 10px;border:1px solid #e2e8f0'><strong>{$lugar}</strong></td></tr>
          {$numAvisoRow}
          <tr><td style='padding:7px 10px;border:1px solid #e2e8f0;background:#f8fafc'><strong>Generado por / Cuadrilla</strong></td><td style='padding:7px 10px;border:1px solid #e2e8f0'>👷 {$trabajador} ({$personas} pers. en cuadrilla){$integrantesHtml}</td></tr>
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

    // Envío automático al Canal Privado de Alertas Push MYC (ntfy.sh) para los teléfonos de supervisión
    $ntfyTopic = trim((string)($cfg['ntfyTopic'] ?? 'myc-terreno-77547271'));
    $pushResult = 'Canal Push desactivado';
    if ($ntfyTopic !== '') {
        $pushTitle = "Reporte {$r['id']} - {$r['lugar']} ({$r['trabajador']})";
        $pushBody = "📍 Lugar: {$r['lugar']}\n"
            . "👷 Por: {$r['trabajador']} ({$r['personas']} pers.)\n"
            . "📅 Fecha: {$r['fechaInicio']} ({$infoHor['nombreDia']}) · {$r['horaInicio']} a {$r['horaTermino']}\n"
            . "🔒 Logística: {$infoHor['etiquetaPago']}" . ($infoHor['marcador'] ? " · {$infoHor['marcador']}" : "") . "\n"
            . "🛠️ Trabajo: " . mb_substr(preg_replace('/\s+/', ' ', (string)($r['trabajo'] ?? '')), 0, 220, 'UTF-8');
        $priority = $infoHor['tieneMarcador'] ? 'high' : 'default';
        $ctxPush = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: text/plain; charset=utf-8\r\n"
                    . "Title: " . preg_replace('/[^\x20-\x7E]/', '', $pushTitle) . "\r\n"
                    . "Priority: {$priority}\r\n"
                    . "Tags: clipboard,construction_worker\r\n"
                    . "Click: https://reporte.mycmantencion.cl/#logistica\r\n",
                'content' => $pushBody,
                'timeout' => 4,
                'ignore_errors' => true
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
        ]);
        $respPush = @file_get_contents('https://ntfy.sh/' . urlencode($ntfyTopic), false, $ctxPush);
        $pushResult = ($respPush !== false)
            ? "Enviado al Canal Push MYC (ntfy.sh/{$ntfyTopic})"
            : "Error o tiempo agotado al conectar con ntfy.sh";
    }

    return [
        'mail' => $mailResults,
        'folders' => $folderStatus,
        'push' => $pushResult
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
    echo json_encode(['ok' => true, 'server' => 'php-cpanel-v11', 'timestamp' => gmdate('c'), 'folders' => $folders], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'GET' && $action === 'workers_catalog') {
    $workers = getWorkersCatalog($workersFile, $defaultWorkersCatalog);
    echo json_encode(['ok' => true, 'workers' => $workers], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'POST' && $action === 'save_workers_catalog') {
    checkAdminPin();
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    if (isset($input['workers']) && is_array($input['workers'])) {
        $clean = array_values(array_unique(array_filter(array_map('trim', $input['workers']))));
        file_put_contents($workersFile, json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        echo json_encode(['ok' => true, 'workers' => $clean], JSON_UNESCAPED_UNICODE);
        exit;
    }
    http_response_code(400);
    echo json_encode(['error' => 'Lista de trabajadores inválida']);
    exit;
}

if ($method === 'GET' && $action === 'client_view') {
    $id = trim((string)($_GET['id'] ?? ''));
    if ($id === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Folio requerido']);
        exit;
    }
    $reports = readReports($dbFile);
    [$reports, ] = ensureShortFolios($dbFile, $reports);
    foreach ($reports as $r) {
        if (($r['id'] ?? '') === $id) {
            // Devolver ÚNICAMENTE datos aptos para el cliente (jamás exponer marcadores internos de horario/horas extra)
            $safeReport = [
                'id' => $r['id'] ?? '',
                'lugar' => $r['lugar'] ?? '',
                'numeroAviso' => $r['numeroAviso'] ?? '',
                'solicita' => $r['solicita'] ?? '',
                'trabajador' => $r['trabajador'] ?? '',
                'integrantes' => $r['integrantes'] ?? [],
                'personas' => $r['personas'] ?? '1',
                'fechaInicio' => $r['fechaInicio'] ?? '',
                'fechaTermino' => $r['fechaTermino'] ?? ($r['fechaInicio'] ?? ''),
                'horaInicio' => $r['horaInicio'] ?? '',
                'horaTermino' => $r['horaTermino'] ?? '',
                'trabajo' => $r['trabajo'] ?? '',
                'materiales' => $r['materiales'] ?? [],
                'maquinaria' => $r['maquinaria'] ?? [],
                'estado' => $r['estado'] ?? 'Terminado',
                'fotos' => $r['fotos'] ?? [],
                'recepcionCliente' => $r['recepcionCliente'] ?? null
            ];
            echo json_encode(['ok' => true, 'report' => $safeReport], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    http_response_code(404);
    echo json_encode(['error' => 'Reporte no encontrado']);
    exit;
}

if ($method === 'POST' && $action === 'client_sign') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = trim((string)($input['id'] ?? ''));
    $nombre = trim((string)($input['nombre'] ?? ''));
    if ($id === '' || $nombre === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Folio y nombre de quien recibe son obligatorios']);
        exit;
    }
    $reports = readReports($dbFile);
    [$reports, ] = ensureShortFolios($dbFile, $reports);
    $foundReport = null;
    foreach ($reports as &$r) {
        if (($r['id'] ?? '') === $id) {
            $prevRec = is_array($r['recepcionCliente'] ?? null) ? $r['recepcionCliente'] : [];
            $r['recepcionCliente'] = array_merge($prevRec, [
                'requerido' => true,
                'firmado' => true,
                'estado' => in_array(trim((string)($input['estado'] ?? '')), ['Recibido Conforme', 'Recibido con Observaciones'], true)
                    ? trim((string)$input['estado'])
                    : 'Recibido Conforme',
                'nombre' => $nombre,
                'cargo' => trim((string)($input['cargo'] ?? '')),
                'observacion' => trim((string)($input['observacion'] ?? '')),
                'firmaDataUrl' => trim((string)($input['firmaDataUrl'] ?? '')),
                'modalidad' => trim((string)($input['modalidad'] ?? 'Link Cliente')),
                'fechaFirma' => gmdate('Y-m-d\TH:i:s.v\Z')
            ]);
            if (!empty($input['numeroAviso'])) {
                $r['numeroAviso'] = trim((string)$input['numeroAviso']);
            }
            if (empty($r['solicita'])) {
                $r['solicita'] = $nombre;
            }
            $r['updatedAt'] = gmdate('Y-m-d\TH:i:s.v\Z');
            $foundReport = $r;
            break;
        }
    }
    unset($r);
    if (!$foundReport) {
        http_response_code(404);
        echo json_encode(['error' => 'Reporte no encontrado']);
        exit;
    }
    writeReports($dbFile, $reports);
    try {
        enviarNotificacionesNuevoReporte($foundReport, $notifyConfigFile, $defaultNotifyConfig);
    } catch (\Throwable $e) {}
    echo json_encode(['ok' => true, 'recepcionCliente' => $foundReport['recepcionCliente']], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'POST' && $action === 'send_client_approval_request') {
    checkAdminPin();
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = trim((string)($input['id'] ?? ''));
    $canal = trim((string)($input['canal'] ?? 'email'));
    $clientName = trim((string)($input['clientName'] ?? ''));
    $clientEmail = strtolower(trim((string)($input['clientEmail'] ?? '')));
    $clientPhone = trim((string)($input['clientPhone'] ?? ''));
    $nota = trim((string)($input['nota'] ?? ''));

    if ($id === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Folio requerido']);
        exit;
    }
    if ($canal === 'email' && ($clientEmail === '' || !filter_var($clientEmail, FILTER_VALIDATE_EMAIL))) {
        http_response_code(400);
        echo json_encode(['error' => 'Ingrese un correo electrónico válido del cliente']);
        exit;
    }

    $reports = readReports($dbFile);
    [$reports, ] = ensureShortFolios($dbFile, $reports);
    $targetReport = null;

    foreach ($reports as &$r) {
        if (($r['id'] ?? '') === $id) {
            $prevRec = is_array($r['recepcionCliente'] ?? null) ? $r['recepcionCliente'] : [];
            $r['recepcionCliente'] = array_merge($prevRec, [
                'requerido' => true,
                'firmado' => !empty($prevRec['firmado']),
                'solicitudEnviada' => true,
                'destinatarioNombre' => $clientName !== '' ? $clientName : ($prevRec['destinatarioNombre'] ?? ($r['solicita'] ?? '')),
                'destinatarioEmail' => $clientEmail !== '' ? $clientEmail : ($prevRec['destinatarioEmail'] ?? ''),
                'destinatarioTelefono' => $clientPhone !== '' ? $clientPhone : ($prevRec['destinatarioTelefono'] ?? ''),
                'canalEnvio' => $canal,
                'notaSolicitud' => $nota,
                'fechaSolicitud' => gmdate('Y-m-d\TH:i:s.v\Z')
            ]);
            if ($clientName !== '' && empty($r['solicita'])) {
                $r['solicita'] = $clientName;
            }
            $r['updatedAt'] = gmdate('Y-m-d\TH:i:s.v\Z');
            $targetReport = $r;
            break;
        }
    }
    unset($r);

    if (!$targetReport) {
        http_response_code(404);
        echo json_encode(['error' => 'Reporte no encontrado']);
        exit;
    }

    writeReports($dbFile, $reports);

    $mailSent = false;
    if ($canal === 'email' && $clientEmail !== '') {
        $folio = htmlspecialchars((string)($targetReport['id'] ?? ''), ENT_QUOTES, 'UTF-8');
        $lugar = htmlspecialchars((string)($targetReport['lugar'] ?? ''), ENT_QUOTES, 'UTF-8');
        $aviso = htmlspecialchars((string)($targetReport['numeroAviso'] ?? ''), ENT_QUOTES, 'UTF-8');
        $fechaStr = htmlspecialchars(($targetReport['fechaInicio'] ?? '') . ' (' . ($targetReport['horaInicio'] ?? '') . ' a ' . ($targetReport['horaTermino'] ?? '') . ' hrs)', ENT_QUOTES, 'UTF-8');
        $integArr = isset($targetReport['integrantes']) && is_array($targetReport['integrantes']) ? $targetReport['integrantes'] : [];
        $cuadrillaStr = htmlspecialchars(($targetReport['trabajador'] ?? '') . (count($integArr) ? ' + ' . implode(', ', $integArr) : '') . ' (' . ($targetReport['personas'] ?? '1') . ' pers.)', ENT_QUOTES, 'UTF-8');
        $trabajoHtml = nl2br(htmlspecialchars((string)($targetReport['trabajo'] ?? ''), ENT_QUOTES, 'UTF-8'));
        $saludo = $clientName !== '' ? "Estimado(a) <strong>" . htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8') . "</strong>," : "Estimado(a) Cliente,";
        $notaHtml = $nota !== ''
            ? "<div style='background:#f0fdf4;border-left:4px solid #0f766e;padding:10px 12px;margin:12px 0;font-size:13.5px;color:#134e4a'><strong>Nota de MYC SpA:</strong> " . nl2br(htmlspecialchars($nota, ENT_QUOTES, 'UTF-8')) . "</div>"
            : "";

        $matItems = '';
        foreach (($targetReport['materiales'] ?? []) as $m) {
            $matItems .= "• " . htmlspecialchars(($m['cantidad'] ?? '') . ' ' . ($m['unidad'] ?? '') . ' ' . ($m['descripcion'] ?? ''), ENT_QUOTES, 'UTF-8') . "<br>";
        }
        if ($matItems === '') $matItems = "<span style='color:#64748b'>Sin materiales adicionales registrados</span>";

        $maqItems = '';
        foreach (($targetReport['maquinaria'] ?? []) as $m) {
            $maqItems .= "• " . htmlspecialchars(($m['cantidad'] ?? '') . ' ' . ($m['unidad'] ?? '') . ' ' . ($m['descripcion'] ?? ''), ENT_QUOTES, 'UTF-8') . "<br>";
        }
        if ($maqItems === '') $maqItems = "<span style='color:#64748b'>Sin maquinaria registrada</span>";

        $linkRecepcion = 'https://reporte.mycmantencion.cl/?recepcion=' . rawurlencode((string)($targetReport['id'] ?? ''));
        $avisoRow = $aviso !== '' ? "<tr><td style='padding:7px 10px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:bold'>N° de Aviso Cliente</td><td style='padding:7px 10px;border:1px solid #cbd5e1'><strong>{$aviso}</strong></td></tr>" : "";

        $htmlClient = "<!doctype html><html lang='es'><head><meta charset='utf-8'></head><body style='font-family:Segoe UI,Arial,sans-serif;background:#f1f5f9;margin:0;padding:20px;color:#1f2937'>
        <div style='max-width:640px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #cbd5e1'>
          <div style='background:#0f766e;color:#ffffff;padding:18px 22px'>
            <div style='font-size:12px;opacity:.9;text-transform:uppercase;letter-spacing:.5px'>Inversiones y Mantenimiento MYC SpA · RUT 77.547.271-5</div>
            <h2 style='margin:4px 0 0;font-size:19px'>✍️ Solicitud de Recepción Conforme de Trabajo — {$folio}</h2>
          </div>
          <div style='padding:20px 22px'>
            <p style='margin:0 0 10px;font-size:14px;line-height:1.5'>{$saludo}</p>
            <p style='margin:0 0 14px;font-size:13.5px;line-height:1.5;color:#334155'>Junto con saludar, enviamos el resumen del trabajo ejecutado en terreno en <strong>{$lugar}</strong> para su revisión y aprobación digital (Recepción Conforme):</p>
            {$notaHtml}
            <table style='width:100%;border-collapse:collapse;font-size:13.5px;margin-bottom:14px'>
              <tr><td style='padding:7px 10px;border:1px solid #cbd5e1;background:#f8fafc;width:34%;font-weight:bold'>Folio Trabajo</td><td style='padding:7px 10px;border:1px solid #cbd5e1'><strong>{$folio}</strong></td></tr>
              {$avisoRow}
              <tr><td style='padding:7px 10px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:bold'>Lugar / Plantel</td><td style='padding:7px 10px;border:1px solid #cbd5e1'><strong>{$lugar}</strong></td></tr>
              <tr><td style='padding:7px 10px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:bold'>Fecha y Horario</td><td style='padding:7px 10px;border:1px solid #cbd5e1'>{$fechaStr}</td></tr>
              <tr><td style='padding:7px 10px;border:1px solid #cbd5e1;background:#f8fafc;font-weight:bold'>Cuadrilla MYC SpA</td><td style='padding:7px 10px;border:1px solid #cbd5e1'>{$cuadrillaStr}</td></tr>
            </table>
            <div style='background:#f8fafc;border:1px solid #cbd5e1;border-radius:8px;padding:12px;margin-bottom:12px'>
              <strong style='color:#17324d;font-size:13px;display:block;margin-bottom:4px'>🛠️ Descripción del Trabajo Realizado:</strong>
              <div style='font-size:13.5px;line-height:1.45'>{$trabajoHtml}</div>
            </div>
            <div style='margin-bottom:10px;font-size:13px'><strong style='color:#17324d'>📦 Materiales:</strong><br>{$matItems}</div>
            <div style='margin-bottom:18px;font-size:13px'><strong style='color:#17324d'>🚜 Maquinaria / Equipos:</strong><br>{$maqItems}</div>
            <div style='text-align:center;margin:22px 0 14px'>
              <a href='{$linkRecepcion}' style='background:#059669;color:#ffffff;text-decoration:none;padding:13px 24px;border-radius:8px;font-weight:bold;font-size:14.5px;display:inline-block'>✍️ REVISAR Y FIRMAR RECEPCIÓN DEL TRABAJO</a>
            </div>
            <p style='margin:0;font-size:12px;color:#64748b;text-align:center'>También puede abrir directamente este enlace en su celular o computador:<br><a href='{$linkRecepcion}' style='color:#0f766e'>{$linkRecepcion}</a></p>
          </div>
        </div></body></html>";

        $subj = "Solicitud de Recepción de Trabajo {$targetReport['id']} - {$targetReport['lugar']} | MYC SpA";
        $encodedSubj = '=?UTF-8?B?' . base64_encode($subj) . '?=';
        $headers = "MIME-Version: 1.0\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "From: Inversiones y Mantenimiento MYC SpA <notificaciones@mycmantencion.cl>\r\n"
            . "Reply-To: c.huerta@mycmantencion.cl, f.abarca@mycmantencion.cl\r\n";
        $mailSent = @mail($clientEmail, $encodedSubj, $htmlClient, $headers);
    }

    echo json_encode([
        'ok' => true,
        'mailSent' => $mailSent,
        'recepcionCliente' => $targetReport['recepcionCliente']
    ], JSON_UNESCAPED_UNICODE);
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
    if (isset($input['ntfyTopic']) && trim((string)$input['ntfyTopic']) !== '') {
        $cfg['ntfyTopic'] = trim((string)$input['ntfyTopic']);
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
            if (isset($input['estado']) && trim((string)$input['estado']) !== '') {
                $r['estado'] = trim((string)$input['estado']);
            }
            if (isset($input['trabajador']) && trim((string)$input['trabajador']) !== '') {
                $r['trabajador'] = trim((string)$input['trabajador']);
            }
            if (isset($input['integrantes']) && is_array($input['integrantes'])) {
                $r['integrantes'] = array_values(array_unique(array_filter(array_map('trim', $input['integrantes']))));
            }
            if (isset($input['recepcionCliente']) && is_array($input['recepcionCliente'])) {
                $r['recepcionCliente'] = $input['recepcionCliente'];
            }
            if (isset($input['solicita'])) {
                $r['solicita'] = trim((string)$input['solicita']);
            }
            if (isset($input['numeroAviso'])) {
                $r['numeroAviso'] = trim((string)$input['numeroAviso']);
            }
            if (isset($input['lugar']) && trim((string)$input['lugar']) !== '') {
                $r['lugar'] = trim((string)$input['lugar']);
            }
            if (isset($input['fechaInicio']) && trim((string)$input['fechaInicio']) !== '') {
                $r['fechaInicio'] = trim((string)$input['fechaInicio']);
            }
            if (isset($input['fechaTermino']) && trim((string)$input['fechaTermino']) !== '') {
                $r['fechaTermino'] = trim((string)$input['fechaTermino']);
            }
            if (isset($input['horaInicio']) && trim((string)$input['horaInicio']) !== '') {
                $r['horaInicio'] = trim((string)$input['horaInicio']);
            }
            if (isset($input['horaTermino']) && trim((string)$input['horaTermino']) !== '') {
                $r['horaTermino'] = trim((string)$input['horaTermino']);
            }
            if (isset($input['personas']) && (int)$input['personas'] >= 1) {
                $r['personas'] = (string)((int)$input['personas']);
            }
            if (isset($input['trabajo']) && trim((string)$input['trabajo']) !== '') {
                $r['trabajo'] = trim((string)$input['trabajo']);
            }
            if (isset($input['tipoHorario']) && in_array(trim((string)$input['tipoHorario']), ['Normal', 'Emergencia'], true)) {
                $r['tipoHorario'] = trim((string)$input['tipoHorario']);
            }
            if (isset($input['materiales']) && is_array($input['materiales'])) {
                $cleanMat = [];
                foreach ($input['materiales'] as $m) {
                    $desc = trim((string)($m['descripcion'] ?? ''));
                    if ($desc === '') continue;
                    $cleanMat[] = [
                        'cantidad' => trim((string)($m['cantidad'] ?? '1')),
                        'unidad' => trim((string)($m['unidad'] ?? 'UN')),
                        'descripcion' => $desc
                    ];
                }
                $r['materiales'] = $cleanMat;
            }
            if (isset($input['maquinaria']) && is_array($input['maquinaria'])) {
                $cleanMaq = [];
                foreach ($input['maquinaria'] as $m) {
                    $desc = trim((string)($m['descripcion'] ?? ''));
                    if ($desc === '') continue;
                    $cleanMaq[] = [
                        'cantidad' => trim((string)($m['cantidad'] ?? '1')),
                        'unidad' => trim((string)($m['unidad'] ?? 'HRS')),
                        'descripcion' => $desc
                    ];
                }
                $r['maquinaria'] = $cleanMaq;
            }
            if (isset($input['fotos']) && is_array($input['fotos'])) {
                $r['fotos'] = array_values($input['fotos']);
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

if ($method === 'POST' && $action === 'unify') {
    checkAdminPin();
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $ids = isset($input['ids']) && is_array($input['ids']) ? array_values(array_unique(array_filter(array_map('trim', $input['ids'])))) : [];
    if (count($ids) < 2) {
        http_response_code(400);
        echo json_encode(['error' => 'Selecciona al menos 2 reportes para unificar.']);
        exit;
    }

    $reports = readReports($dbFile);
    [$reports, ] = ensureShortFolios($dbFile, $reports);

    $toUnify = [];
    $remaining = [];
    foreach ($reports as $r) {
        if (in_array($r['id'] ?? '', $ids, true)) {
            $toUnify[] = $r;
        } else {
            $remaining[] = $r;
        }
    }

    if (count($toUnify) < 2) {
        http_response_code(404);
        echo json_encode(['error' => 'No se encontraron los reportes seleccionados para unificar.']);
        exit;
    }

    // Ordenar cronológicamente por fechaInicio + horaInicio
    usort($toUnify, function ($a, $b) {
        $ka = ($a['fechaInicio'] ?? '') . ' ' . ($a['horaInicio'] ?? '');
        $kb = ($b['fechaInicio'] ?? '') . ' ' . ($b['horaInicio'] ?? '');
        return strcmp($ka, $kb);
    });

    $first = $toUnify[0];
    $last = $toUnify[count($toUnify) - 1];

    $foliosOriginales = [];
    $trabajadoresSet = [];
    $integrantesSet = [];
    $recepcionConsolidada = null;
    $solicitaVal = '';
    $avisosSet = [];
    $fechaMin = $first['fechaInicio'] ?? date('Y-m-d');
    $fechaMax = $first['fechaTermino'] ?? $fechaMin;
    $personasMax = 0;
    $personasSuma = 0;
    $tieneEmergencia = false;
    $descripciones = [];
    $fotosUnidas = [];
    $jornadas = [];
    $backupOriginales = [];

    $agruparItems = function (array $listaItems): array {
        $mapa = [];
        foreach ($listaItems as $it) {
            $desc = trim((string)($it['descripcion'] ?? ''));
            if ($desc === '') continue;
            $unid = trim((string)($it['unidad'] ?? ''));
            $cantRaw = str_replace(',', '.', trim((string)($it['cantidad'] ?? '1')));
            $cantNum = is_numeric($cantRaw) ? (float)$cantRaw : 1.0;
            $key = mb_strtolower($desc . '|' . $unid, 'UTF-8');
            if (!isset($mapa[$key])) {
                $mapa[$key] = [
                    'cantidad' => $cantNum,
                    'unidad' => $unid,
                    'descripcion' => $desc
                ];
            } else {
                $mapa[$key]['cantidad'] = round($mapa[$key]['cantidad'] + $cantNum, 2);
            }
        }
        return array_values(array_map(function ($x) {
            $x['cantidad'] = (string)$x['cantidad'];
            return $x;
        }, $mapa));
    };

    $rawMat = [];
    $rawMaq = [];

    foreach ($toUnify as $item) {
        // Si ya era un reporte unificado antes, conservar sus sub-reportes originales
        if (!empty($item['reportesOriginalesBackup']) && is_array($item['reportesOriginalesBackup'])) {
            foreach ($item['reportesOriginalesBackup'] as $b) {
                $backupOriginales[] = $b;
            }
        } else {
            $backupOriginales[] = $item;
        }

        if (!empty($item['foliosUnificados']) && is_array($item['foliosUnificados'])) {
            foreach ($item['foliosUnificados'] as $fId) {
                if (!in_array($fId, $foliosOriginales, true)) $foliosOriginales[] = $fId;
            }
        } else {
            if (!in_array($item['id'], $foliosOriginales, true)) $foliosOriginales[] = $item['id'];
        }

        $trab = trim((string)($item['trabajador'] ?? ''));
        if ($trab !== '' && !in_array($trab, $trabajadoresSet, true)) {
            $trabajadoresSet[] = $trab;
        }
        if (!empty($item['integrantes']) && is_array($item['integrantes'])) {
            foreach ($item['integrantes'] as $integ) {
                $integClean = trim((string)$integ);
                if ($integClean !== '' && !in_array($integClean, $integrantesSet, true)) {
                    $integrantesSet[] = $integClean;
                }
            }
        }
        if (!$recepcionConsolidada && !empty($item['recepcionCliente']) && is_array($item['recepcionCliente'])) {
            $recepcionConsolidada = $item['recepcionCliente'];
        }
        if ($solicitaVal === '' && !empty($item['solicita'])) {
            $solicitaVal = trim((string)$item['solicita']);
        }
        $avItem = trim((string)($item['numeroAviso'] ?? ''));
        if ($avItem !== '' && !in_array($avItem, $avisosSet, true)) {
            $avisosSet[] = $avItem;
        }
        $fi = $item['fechaInicio'] ?? $fechaMin;
        $ft = $item['fechaTermino'] ?? $fi;
        if ($fi < $fechaMin) $fechaMin = $fi;
        if ($ft > $fechaMax) $fechaMax = $ft;

        $p = max(1, (int)($item['personas'] ?? 1));
        if ($p > $personasMax) $personasMax = $p;
        $personasSuma += $p;

        if (($item['tipoHorario'] ?? '') === 'Emergencia') {
            $tieneEmergencia = true;
        }

        $encabezadoTramo = "[{$fi} · " . ($item['horaInicio'] ?? '') . "–" . ($item['horaTermino'] ?? '') . " · " . ($trab ?: 'Cuadrilla') . " ({$p} pers.)]: ";
        $descripciones[] = $encabezadoTramo . trim((string)($item['trabajo'] ?? ''));

        foreach (($item['materiales'] ?? []) as $m) $rawMat[] = $m;
        foreach (($item['maquinaria'] ?? []) as $m) $rawMaq[] = $m;
        foreach (($item['fotos'] ?? []) as $f) $fotosUnidas[] = $f;

        if (!empty($item['jornadasUnificadas']) && is_array($item['jornadasUnificadas'])) {
            foreach ($item['jornadasUnificadas'] as $j) $jornadas[] = $j;
        } else {
            $jornadas[] = [
                'idOriginal' => $item['id'] ?? '',
                'fechaInicio' => $fi,
                'fechaTermino' => $ft,
                'horaInicio' => $item['horaInicio'] ?? '08:00',
                'horaTermino' => $item['horaTermino'] ?? '17:30',
                'trabajador' => $trab ?: 'No indicado',
                'personas' => (string)$p,
                'tipoHorario' => $item['tipoHorario'] ?? 'Normal'
            ];
        }
    }

    $lugarFinal = !empty($input['lugar']) ? trim((string)$input['lugar']) : ($first['lugar'] ?? '');
    $estadoFinal = !empty($input['estado']) ? trim((string)$input['estado']) : ($last['estado'] ?? 'Terminado');

    $unifiedReport = [
        'id' => $first['id'],
        'clientUid' => $first['clientUid'] ?? ('UNIF-' . time()),
        'esUnificado' => true,
        'foliosUnificados' => $foliosOriginales,
        'jornadasUnificadas' => $jornadas,
        'reportesOriginalesBackup' => $backupOriginales,
        'trabajador' => implode(', ', $trabajadoresSet) ?: 'No indicado',
        'integrantes' => $integrantesSet,
        'recepcionCliente' => $recepcionConsolidada,
        'solicita' => $solicitaVal,
        'numeroAviso' => implode(' / ', $avisosSet),
        'lugar' => $lugarFinal,
        'fechaInicio' => $fechaMin,
        'fechaTermino' => $fechaMax,
        'horaInicio' => $first['horaInicio'] ?? '08:00',
        'horaTermino' => $last['horaTermino'] ?? '17:30',
        'personas' => (string)$personasMax,
        'personasAcumuladas' => (string)$personasSuma,
        'tipoHorario' => $tieneEmergencia ? 'Emergencia' : 'Normal',
        'trabajo' => implode("\n", $descripciones),
        'materiales' => $agruparItems($rawMat),
        'maquinaria' => $agruparItems($rawMaq),
        'estado' => $estadoFinal,
        'fotos' => $fotosUnidas,
        'createdAt' => $last['createdAt'] ?? gmdate('Y-m-d\TH:i:s.v\Z'),
        'updatedAt' => gmdate('Y-m-d\TH:i:s.v\Z'),
        'estadoEnvio' => 'UNIFICADO EN LOGÍSTICA'
    ];

    $remaining[] = $unifiedReport;
    writeReports($dbFile, $remaining);
    echo json_encode(['ok' => true, 'report' => $unifiedReport], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'POST' && $action === 'split_unified') {
    checkAdminPin();
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = trim((string)($input['id'] ?? ''));
    if ($id === '') {
        http_response_code(400);
        echo json_encode(['error' => 'ID de reporte unificado requerido']);
        exit;
    }

    $reports = readReports($dbFile);
    $newList = [];
    $restored = false;

    foreach ($reports as $r) {
        if (($r['id'] ?? '') === $id && !empty($r['reportesOriginalesBackup']) && is_array($r['reportesOriginalesBackup'])) {
            $restored = true;
            foreach ($r['reportesOriginalesBackup'] as $orig) {
                $newList[] = $orig;
            }
        } else {
            $newList[] = $r;
        }
    }

    if (!$restored) {
        http_response_code(404);
        echo json_encode(['error' => 'No se encontró respaldo de reportes originales para separar.']);
        exit;
    }

    writeReports($dbFile, $newList);
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
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
            $integrantesStr = isset($r['integrantes']) && is_array($r['integrantes']) ? implode(' ', $r['integrantes']) : '';
            $haystack = mb_strtolower(
                ($r['id'] ?? '') . ' ' .
                ($r['numeroAviso'] ?? '') . ' ' .
                ($r['lugar'] ?? '') . ' ' .
                ($r['trabajo'] ?? '') . ' ' .
                ($r['trabajador'] ?? '') . ' ' .
                $integrantesStr . ' ' .
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
    $r['numeroAviso'] = trim((string)($r['numeroAviso'] ?? ''));
    $r['integrantes'] = isset($r['integrantes']) && is_array($r['integrantes'])
        ? array_values(array_unique(array_filter(array_map('trim', $r['integrantes']))))
        : [];
    // Registrar automáticamente nuevos nombres (opción "Otro") en el catálogo de trabajadores
    registerNewWorkers($workersFile, $defaultWorkersCatalog, array_merge([$r['trabajador']], $r['integrantes']));
    if (isset($r['recepcionCliente']) && is_array($r['recepcionCliente'])) {
        if (empty($r['solicita']) && !empty($r['recepcionCliente']['nombre'])) {
            $r['solicita'] = trim((string)$r['recepcionCliente']['nombre']);
        }
    } else {
        $r['recepcionCliente'] = null;
    }
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
