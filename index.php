<?php
/**
 * ============================================================================
 * ____        _     _   _   _             _____ _____ _____  ____  
 * / ___| ___  | | __| | | | | | ___ _ __  |  ___|_   _|  __ \|  _ \ 
 * | |  _ / _ \ | |/ _` | | |_| |/ _ \ '_ \ | |_    | | | |__) | |_) |
 * | |_| | (_) || | (_| | |  _  |  __/ | | ||  _|   | | |  ___/|  __/ 
 * \____|\___/ |_|\__,_| |_| |_|\___|_| |_||_|     |_| |_|    |_|    
 * * ====================================================================
 * DEVELOPED BY: SeBaS
 * VERSION: V20 (Definitive Edition)
 * DESCRIPTION: Ultimate PS4 FTP Manager, Modding Suite & File Explorer
 * WARNING: Prohibida su copia o distribución sin créditos al autor.
 * ====================================================================
 */

// ==========================================
// 1. AUTO-CREACIÓN PWA E ICONO
// ==========================================
$manifest_content = '{
  "name": "Gold Hen Suite Pro by SeBaS",
  "short_name": "Gold Hen",
  "description": "Gestor de transferencia FTP y Modding para PS4 creado por SeBaS",
  "start_url": "./index.php",
  "display": "standalone",
  "orientation": "portrait",
  "background_color": "#050810",
  "theme_color": "#050810",
  "icons": [{"src": "icon-512.png?v=4", "sizes": "512x512", "type": "image/png", "purpose": "any maskable"}]
}';
@file_put_contents('manifest.json', $manifest_content);

$sw_content = "self.addEventListener('install', (e) => self.skipWaiting());\nself.addEventListener('activate', (e) => self.clients.claim());\nself.addEventListener('fetch', (e) => e.respondWith(fetch(e.request)));";
if (!file_exists('sw.js')) { @file_put_contents('sw.js', $sw_content); }

if (!file_exists('icon-512.png')) {
    $icon_url = 'https://raw.githubusercontent.com/ElNoNo26/Ps4-SeBaS/main/icon-512.png';
    $ch = curl_init($icon_url); $fp = fopen('icon-512.png', 'wb');
    curl_setopt($ch, CURLOPT_FILE, $fp); curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch); curl_close($ch); fclose($fp);
}

// ==========================================
// 2. AUTO-DESCUBRIMIENTO GALERÍA
// ==========================================
$iconos_locales = [];
if (!file_exists('iconos')) { @mkdir('iconos', 0777, true); }
if (file_exists('iconos') && is_dir('iconos')) {
    $archivos = glob('iconos/*.{jpg,jpeg,png,gif}', GLOB_BRACE);
    foreach($archivos as $archivo) { $iconos_locales[] = ['nombre' => basename($archivo), 'url' => $archivo]; }
}

// ==========================================
// 3. DESCARGAR IMÁGENES A LA GALERÍA LOCAL (FIX RCE)
// ==========================================
if (isset($_POST['action']) && $_POST['action'] == 'download_to_gallery') {
    header('Content-Type: application/json');
    $url = $_POST['url'];
    
    // FIX: Sanitización segura y extensión estricta de imágenes
    $raw_name = isset($_POST['name']) ? $_POST['name'] : 'icon_' . time() . '.png';
    $ext = strtolower(pathinfo($raw_name, PATHINFO_EXTENSION));
    if (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif'])) { $ext = 'png'; }
    $name_saneado = preg_replace('/[^a-zA-Z0-9_-]/', '', pathinfo($raw_name, PATHINFO_FILENAME));
    
    $destino = 'iconos/' . $name_saneado . '.' . $ext;
    
    $ch = curl_init($url); $fp = fopen($destino, 'wb');
    curl_setopt($ch, CURLOPT_FILE, $fp); curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'GoldHen-FTP-App');
    curl_exec($ch); $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch); fclose($fp);
    if ($http_code == 200 || $http_code == 0) { echo json_encode(['status' => 'success', 'file' => $destino]); } 
    else { @unlink($destino); echo json_encode(['status' => 'error', 'message' => "Fallo HTTP $http_code"]); }
    exit;
}

// ==========================================
// 4. LÓGICA ESCÁNER DE RED (AJAX PING)
// ==========================================
if (isset($_GET['action']) && $_GET['action'] == 'ping' && isset($_GET['ip'])) {
    header('Content-Type: application/json');
    $ip = $_GET['ip']; $port = 2121;
    if (strpos($ip, '127.') === 0) { echo json_encode(['status' => 'error']); exit; }
    $fp = @fsockopen($ip, $port, $errno, $errstr, 0.3);
    if ($fp) {
        stream_set_timeout($fp, 0, 300000); $banner = fgets($fp, 256); fclose($fp);
        if ($banner !== false && stripos($banner, 'KSWEB') === false && stripos($banner, 'bftpd') === false) {
            echo json_encode(['status' => 'success', 'ip' => $ip]); 
        } else { echo json_encode(['status' => 'error']); }
    } else { echo json_encode(['status' => 'error']); }
    exit;
}

// ==========================================
// 5. GESTOR DE ARCHIVOS FTP (EXPLORADOR)
// ==========================================
if (isset($_POST['action']) && in_array($_POST['action'], ['list_dir', 'delete_item', 'mkdir', 'rename'])) {
    header('Content-Type: application/json');
    $host_ip = $_POST['host_ip'];
    $conn_id = @ftp_connect($host_ip, 2121, 5);
    
    if ($conn_id && @ftp_login($conn_id, "anonymous", "")) {
        ftp_pasv($conn_id, true);
        
        if ($_POST['action'] == 'list_dir') {
            $path = $_POST['path'];
            $raw = @ftp_rawlist($conn_id, $path);
            $list = [];
            if (is_array($raw)) {
                foreach ($raw as $line) {
                    $parts = preg_split('/\s+/', $line, 9);
                    if (count($parts) < 9) continue;
                    $name = $parts[8];
                    if ($name == '.' || $name == '..') continue;
                    $is_dir = ($parts[0][0] === 'd');
                    $size = $parts[4];
                    $list[] = ['name' => $name, 'is_dir' => $is_dir, 'size' => $size];
                }
                usort($list, function($a, $b) {
                    if ($a['is_dir'] == $b['is_dir']) return strcasecmp($a['name'], $b['name']);
                    return $a['is_dir'] ? -1 : 1;
                });
            }
            echo json_encode(['status' => 'success', 'data' => $list, 'path' => $path]);
        }
        elseif ($_POST['action'] == 'delete_item') {
            $path = $_POST['path']; $is_dir = $_POST['is_dir'] === 'true';
            $res = $is_dir ? @ftp_rmdir($conn_id, $path) : @ftp_delete($conn_id, $path);
            if ($res) echo json_encode(['status' => 'success']);
            else echo json_encode(['status' => 'error', 'message' => 'No se pudo borrar. (¿Carpeta llena o archivo protegido?)']);
        }
        elseif ($_POST['action'] == 'mkdir') {
            $path = $_POST['path'];
            if (@ftp_mkdir($conn_id, $path)) echo json_encode(['status' => 'success']);
            else echo json_encode(['status' => 'error', 'message' => 'No se pudo crear la carpeta.']);
        }
        elseif ($_POST['action'] == 'rename') {
            $old = $_POST['old_path']; $new = $_POST['new_path'];
            if (@ftp_rename($conn_id, $old, $new)) echo json_encode(['status' => 'success']);
            else echo json_encode(['status' => 'error', 'message' => 'Error al mover o renombrar.']);
        }
    } else { echo json_encode(['status' => 'error', 'message' => 'No conectado a la PS4.']); }
    @ftp_close($conn_id);
    exit;
}

// ==========================================
// 6. ENVÍO DE ICONOS Y PKGS
// ==========================================
if (isset($_POST['action']) && $_POST['action'] == 'upload_icon') {
    header('Content-Type: application/json');
    $host_ip = $_POST['host_ip']; $cusa_id = strtoupper(trim($_POST['cusa_id']));
    $source_type = $_POST['source_type']; $puerto_ftp = 2121;
    $ruta_remota = "/user/appmeta/" . $cusa_id . "/icon0.png";
    $archivo_temporal = "";
    try {
        if ($source_type == 'local_gallery') {
            $path = $_POST['icon_path'];
            if (strpos($path, 'iconos/') === 0 && file_exists($path)) { $archivo_temporal = $path; } else { throw new Exception("Archivo no encontrado."); }
        } else {
            if (!isset($_FILES['local_icon']) || $_FILES['local_icon']['error'] !== UPLOAD_ERR_OK) { throw new Exception("Error en imagen local."); }
            $archivo_temporal = $_FILES['local_icon']['tmp_name'];
        }
        $conn_id = @ftp_connect($host_ip, $puerto_ftp, 10);
        if (!$conn_id || !@ftp_login($conn_id, "anonymous", "")) throw new Exception("Error FTP.");
        ftp_pasv($conn_id, true); @ftp_mkdir($conn_id, "/user/appmeta/" . $cusa_id);
        if (@ftp_put($conn_id, $ruta_remota, $archivo_temporal, FTP_BINARY)) { echo json_encode(['status' => 'success', 'message' => "Icono aplicado en $cusa_id."]); } 
        else { throw new Exception("Error al subir icono."); }
        @ftp_close($conn_id);
    } catch (Exception $e) { echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); }
    exit;
}

if (isset($_POST['action']) && $_POST['action'] == 'upload_chunk') {
    header('Content-Type: application/json');
    @set_time_limit(0); @ini_set('memory_limit', '-1');
    $host_ip = $_POST['host_ip']; $ruta_destino = rtrim($_POST['selected_path'], '/') . '/';
    $file_name = $_POST['file_name']; $chunk_index = (int)$_POST['chunk_index'];
    $puerto_ftp = 2121; $ruta_remota = $ruta_destino . $file_name;
    
    if (isset($_FILES['archivo_subida']) && $_FILES['archivo_subida']['error'] === UPLOAD_ERR_OK) {
        $archivo_temporal = $_FILES['archivo_subida']['tmp_name'];
        $conn_id = @ftp_connect($host_ip, $puerto_ftp, 10);
        if ($conn_id && @ftp_login($conn_id, "anonymous", "")) {
            ftp_pasv($conn_id, true);
            if ($chunk_index === 0) { @ftp_delete($conn_id, $ruta_remota); $result = @ftp_put($conn_id, $ruta_remota, $archivo_temporal, FTP_BINARY); } 
            else {
                if (function_exists('ftp_append')) { $result = @ftp_append($conn_id, $ruta_remota, $archivo_temporal, FTP_BINARY); } 
                else {
                    $ch = curl_init(); $fp = fopen($archivo_temporal, 'r');
                    curl_setopt($ch, CURLOPT_URL, "ftp://$host_ip:$puerto_ftp$ruta_remota"); curl_setopt($ch, CURLOPT_UPLOAD, 1); 
                    curl_setopt($ch, CURLOPT_INFILE, $fp); curl_setopt($ch, CURLOPT_INFILESIZE, filesize($archivo_temporal)); 
                    curl_setopt($ch, CURLOPT_FTPAPPEND, true); $result = curl_exec($ch); curl_close($ch); fclose($fp);
                }
            }
            if ($result) { echo json_encode(['status' => 'success']); } else { echo json_encode(['status' => 'error']); }
        } else { echo json_encode(['status' => 'error']); }
    } else { echo json_encode(['status' => 'error']); }
    exit;
}

$ip_servidor = isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : '192.168.0.1';
if (strpos($ip_servidor, '127.') === 0 || $ip_servidor == '::1') $ip_servidor = getHostByName(getHostName());
$subred_actual = (strpos($ip_servidor, '192.168.') === 0) ? substr($ip_servidor, 0, strrpos($ip_servidor, '.') + 1) : '192.168.0.';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Gold Hen Suite Pro by SeBaS</title>
    
    <link rel="manifest" href="manifest.json?v=4">
    <meta name="theme-color" content="#050810">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="apple-touch-icon" href="icon-512.png?v=4">

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,400;0,700;0,900;1,800&display=swap" rel="stylesheet">
    
    <style>
        body { font-family: 'Montserrat', sans-serif; background-color: #050810; color: #ffffff; overflow-x: hidden; padding-bottom: 140px; 
               -webkit-user-select: none; user-select: none; touch-action: pan-y; }
        
        .banner-wrapper { width: 100%; position: relative; display: flex; flex-direction: column; pointer-events: none; }
        .banner-wrapper img { width: 100%; height: auto; display: block; }
        .banner-fade { position: absolute; bottom: 0; left: 0; width: 100%; height: 50%; background: linear-gradient(to bottom, transparent, #050810); pointer-events: none; }
        
        .btn-3d { transition: transform 0.1s, box-shadow 0.1s, background-color 0.3s; position: relative; outline: none; }
        .btn-3d-cyan { background-color: #00b4d8; box-shadow: 0 5px 0 #0077b6, 0 6px 8px rgba(0,0,0,0.4); color: #050810; }
        .btn-3d-cyan:active { transform: translateY(5px); box-shadow: 0 0px 0 #0077b6, 0 2px 4px rgba(0,0,0,0.4); }
        .btn-3d-green { background-color: #10b981; box-shadow: 0 5px 0 #047857, 0 6px 8px rgba(0,0,0,0.4); color: #050810; }
        .btn-3d-green:active { transform: translateY(5px); box-shadow: 0 0px 0 #047857, 0 2px 4px rgba(0,0,0,0.4); }
        
        .btn-3d-red { background-color: #ef233c; box-shadow: 0 5px 0 #8d0801, 0 6px 8px rgba(0,0,0,0.4); color: white; }
        .btn-3d-red:active { transform: translateY(5px); box-shadow: 0 0px 0 #8d0801, 0 2px 4px rgba(0,0,0,0.4); }
        .btn-3d-red.is-scanning { transform: translateY(5px); box-shadow: 0 0px 0 #8d0801; animation: pulse-radar 1.5s infinite; }
        @keyframes pulse-radar { 0% { box-shadow: 0 0 0 0 rgba(239, 35, 60, 0.8), 0 0px 0 #8d0801; } 70% { box-shadow: 0 0 0 15px rgba(239, 35, 60, 0), 0 0px 0 #8d0801; } 100% { box-shadow: 0 0 0 0 rgba(239, 35, 60, 0), 0 0px 0 #8d0801; } }

        .btn-3d-indigo { background-color: #6366f1; box-shadow: 0 5px 0 #4338ca, 0 6px 8px rgba(0,0,0,0.4); color: white; }
        .btn-3d-indigo:active { transform: translateY(5px); box-shadow: 0 0px 0 #4338ca, 0 2px 4px rgba(0,0,0,0.4); }
        
        /* BOTÓN FLOTANTE GLOBAL */
        .floating-btn-global {
            position: fixed; bottom: 85px; left: 50%; transform: translateX(-50%); width: 100%; max-width: 32rem; 
            padding: 0 1.25rem; z-index: 40; transition: opacity 0.3s, transform 0.3s;
        }
        .floating-btn-global button { box-shadow: 0 -5px 30px rgba(5,8,16,0.9), 0 10px 20px rgba(0,0,0,0.8); }
        .floating-hidden { opacity: 0; pointer-events: none; transform: translate(-50%, 20px); }

        .folder-btn { width: 100%; padding: 14px 10px; border-radius: 12px; font-family: monospace; font-size: 14px; text-align: center; transition: all 0.2s; border: 1px solid #1e293b; background-color: transparent; color: #94a3b8; cursor: pointer; position: relative; display: flex; align-items: center; justify-content: center; }
        .folder-btn.active { background-color: #5c5bf4; color: #ffffff; border-color: transparent; box-shadow: 0 4px 10px rgba(92, 91, 244, 0.3); }
        
        .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background-color: #4f46e5; border-radius: 10px; }
        .custom-scrollbar::-webkit-scrollbar-track { background-color: transparent; }

        .modal-glass { background: rgba(5, 8, 16, 0.95); backdrop-filter: blur(10px); }
        .progress-glow { box-shadow: 0 0 15px rgba(0, 180, 216, 0.6); }

        .tab-content { display: none; animation: slideIn 0.3s ease-out; }
        .tab-content.active { display: block; }
        @keyframes slideIn { from { opacity: 0; transform: translateX(10px); } to { opacity: 1; transform: translateX(0); } }
        
        .nav-item { color: #475569; transition: all 0.3s; flex: 1; }
        .nav-item.active { color: #00b4d8; transform: translateY(-5px); }
        .nav-item.active::after { content: ''; position: absolute; bottom: -8px; left: 50%; transform: translateX(-50%); width: 20px; height: 3px; background-color: #00b4d8; border-radius: 5px; box-shadow: 0 0 10px #00b4d8; }

        .toggle-checkbox { display: none; }
        .toggle-label { display: inline-block; position: relative; width: 50px; height: 26px; background-color: #374151; border-radius: 9999px; transition: background-color 0.3s ease; cursor: pointer; }
        .toggle-label::after { content: ''; position: absolute; top: 3px; left: 3px; width: 20px; height: 20px; background-color: white; border-radius: 50%; transition: transform 0.3s cubic-bezier(0.4, 0.0, 0.2, 1); box-shadow: 0 2px 4px rgba(0,0,0,0.3); }
        .toggle-checkbox:checked + .toggle-label { background-color: #10b981; }
        .toggle-checkbox:checked + .toggle-label::after { transform: translateX(24px); }
        
        .gallery-item { position: relative; cursor: pointer; border-radius: 12px; overflow: hidden; border: 2px solid transparent; transition: all 0.2s; background-color: #111; width: 100%; padding-bottom: 100%; }
        .gallery-item.selected { border-color: #6366f1; box-shadow: 0 0 15px rgba(99, 102, 241, 0.5); transform: scale(0.95); }
        .gallery-item img { position: absolute; top: 0; left: 0; width: 100%; height: 100%; object-fit: cover; display: block; pointer-events: none;}
        .gallery-label { position: absolute; bottom: 0; left: 0; right: 0; background: rgba(0,0,0,0.8); color: white; text-align: center; font-size: 8px; padding: 4px 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; z-index: 10; pointer-events: none;}

        /* BOTTOM SHEET (EXPLORADOR) */
        .bottom-sheet { position: fixed; bottom: 0; left: 50%; transform: translateX(-50%) translateY(100%); width: 100%; max-width: 32rem; background: #0d121c; border-top-left-radius: 20px; border-top-right-radius: 20px; z-index: 60; transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1); border-top: 1px solid #1e293b; padding-bottom: env(safe-area-inset-bottom); }
        .bottom-sheet.open { transform: translateX(-50%) translateY(0); }
        .overlay-sheet { position: fixed; inset: 0; background: rgba(0,0,0,0.7); z-index: 55; opacity: 0; pointer-events: none; transition: opacity 0.3s; backdrop-filter: blur(2px); }
        .overlay-sheet.open { opacity: 1; pointer-events: auto; }
    </style>
</head>
<body class="flex flex-col min-h-screen" id="swipe-area">

    <div class="w-full relative z-0">
        <div class="banner-wrapper">
            <img src="https://raw.githubusercontent.com/ElNoNo26/Ps4-SeBaS/refs/heads/main/Bannerps4%20ftp.png" alt="Banner Gold Hen">
            
            <div class="absolute top-3 right-4 z-20 pointer-events-auto">
                <span class="text-[9px] font-black text-cyan-300 tracking-[0.2em] uppercase bg-black/50 px-2.5 py-1 rounded-md border border-cyan-500/30 backdrop-blur-md shadow-[0_0_10px_rgba(0,180,216,0.3)]">
                    by SeBaS
                </span>
            </div>

            <div class="banner-fade"></div>
        </div>
        
        <div id="badge-detectada" class="hidden absolute bottom-6 right-4 bg-black/80 border border-green-500 rounded-full px-3 py-1.5 text-[10px] font-bold flex items-center gap-2 z-20 shadow-lg shadow-green-900/50 transition-all duration-300">
            <div id="badge-dot" class="w-2.5 h-2.5 rounded-full bg-green-400 animate-pulse"></div>
            <span id="badge-text" class="text-white tracking-widest">PS4 DETECTADA</span>
        </div>
    </div>

    <div class="w-full max-w-lg mx-auto px-5 relative z-10 -mt-6 mb-4">
        <div class="bg-[#0d121c] border border-cyan-900/50 rounded-2xl p-3 flex gap-3 shadow-lg">
            <div class="flex-1 bg-[#050810] rounded-xl flex items-center px-3 border border-gray-800 focus-within:border-cyan-500 relative">
                <i class="fa-solid fa-network-wired text-cyan-500 text-xs mr-2"></i>
                <input type="text" value="" placeholder="IP PS4 (192.168.x.x)" class="bg-transparent text-sm font-mono text-white w-full outline-none py-2 pr-6" id="host-ip" autocomplete="off">
                <i class="fa-solid fa-xmark absolute right-3 text-gray-600 hover:text-red-500 cursor-pointer" onclick="clearIP()"></i>
            </div>
            <button type="button" id="btn-scan" onclick="toggleRealScan()" class="btn-3d btn-3d-cyan w-10 h-10 rounded-xl flex items-center justify-center text-sm relative">
                <i class="fa-solid fa-satellite-dish" id="scan-icon"></i>
            </button>
            <button type="button" onclick="connectManualIP()" class="btn-3d btn-3d-green w-10 h-10 rounded-xl flex items-center justify-center text-sm relative">
                <i class="fa-solid fa-plug" id="connect-icon"></i>
            </button>
        </div>
        <p id="global-status" class="text-[10px] text-center mt-2 font-mono text-cyan-400 hidden">
            <i class="fa-solid fa-satellite-dish fa-fade mr-1 text-red-500"></i> 
            <span id="scan-text">BUSCANDO...</span>
        </p>
    </div>

    <main class="flex-1 w-full max-w-lg mx-auto px-5 relative z-10">
        
        <div id="tab-ftp" class="tab-content active">
            <form id="ftp-form" onsubmit="enviarArchivoChunks(event)">
                <div class="mb-6">
                    <label class="text-[10px] font-bold text-gray-500 tracking-widest mb-3 block"><i class="fa-regular fa-folder-open mr-1"></i> RUTAS DE DESTINO</label>
                    <div class="grid grid-cols-2 gap-3" id="paths-grid">
                        <button type="button" class="folder-btn active" onclick="selectPath(this, '/data/')">/data/</button>
                        <button type="button" class="folder-btn" onclick="selectPath(this, '/data/pkg/')">/data/pkg/</button>
                        <button type="button" class="folder-btn" onclick="selectPath(this, '/mnt/usb0/')">USB (usb0)</button>
                        <button type="button" id="btn-otra" class="folder-btn border-dashed border-gray-600 text-gray-500" onclick="showAddPathUI()"><i class="fa-solid fa-plus mr-1"></i> Otra</button>
                    </div>
                    <input type="hidden" id="selected-path-input" value="/data/">
                    <div id="add-path-ui" class="hidden mt-3 flex gap-2">
                        <input type="text" id="new-path-input" placeholder="/ruta/nueva/" class="flex-1 bg-[#0a0d14] border border-cyan-500/50 rounded-xl px-3 text-sm font-mono text-white outline-none">
                        <button type="button" onclick="saveNewPath()" class="bg-green-500 text-black px-4 rounded-xl"><i class="fa-solid fa-check"></i></button>
                        <button type="button" onclick="hideAddPathUI()" class="bg-gray-700 text-white px-4 rounded-xl"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                </div>

                <div class="mb-6">
                    <label class="text-[10px] font-bold text-gray-500 tracking-widest block text-center mb-3">SUBIR JUEGOS O APPS (PKG/BIN)</label>
                    <div onclick="document.getElementById('file-upload').click()" class="bg-[#0a0d14] border-2 border-dashed border-gray-800 hover:border-cyan-500 rounded-2xl p-6 flex flex-col items-center justify-center cursor-pointer transition-all group">
                        <div id="upload-icon-container" class="w-14 h-14 bg-[#121826] rounded-full flex items-center justify-center text-cyan-400 mb-3 shadow-md border border-gray-800 group-hover:scale-110 transition-transform">
                            <i class="fa-solid fa-folder-plus text-2xl"></i>
                        </div>
                        <span id="file-name-display" class="text-xs font-bold text-gray-400 group-hover:text-cyan-400 text-center px-2 break-all">TOCA PARA SELECCIONAR ARCHIVOS</span>
                        <input type="file" id="file-upload" class="hidden" multiple onchange="updateFileName(this)">
                    </div>
                </div>

                <button type="submit" class="w-full btn-3d btn-3d-cyan rounded-xl py-4 font-black tracking-widest text-sm flex items-center justify-center gap-2">
                    <i class="fa-brands fa-playstation text-xl"></i> INICIAR TRANSFERENCIA
                </button>
            </form>
        </div>

        <div id="tab-icons" class="tab-content relative">
            <div class="mb-4 text-center">
                <h2 class="text-lg font-black text-indigo-400 italic tracking-wider">MODDING DE PORTADAS</h2>
            </div>

            <form id="icon-form" onsubmit="enviarIcono(event)">
                <div class="mb-4">
                    <label class="text-[10px] font-bold text-gray-400 tracking-widest mb-2 block">ID DEL JUEGO (Ej: CUSA00000)</label>
                    <input type="text" id="icon-cusa" placeholder="CUSA12345" class="w-full bg-[#0a0d14] rounded-xl px-4 py-3 border border-indigo-900 focus:border-indigo-500 font-mono text-white text-center uppercase text-lg outline-none" required>
                </div>

                <div class="flex gap-2 mb-4 bg-[#0a0d14] p-1.5 rounded-xl border border-gray-800">
                    <button type="button" id="btn-src-gallery" class="flex-1 py-2 text-[10px] font-bold rounded-lg bg-indigo-600 text-white" onclick="switchIconSource('gallery')"><i class="fa-solid fa-images mr-1"></i> GALERÍA</button>
                    <button type="button" id="btn-src-import" class="flex-1 py-2 text-[10px] font-bold rounded-lg bg-transparent text-gray-500" onclick="switchIconSource('import')"><i class="fa-solid fa-cloud-arrow-down mr-1"></i> IMPORTAR</button>
                    <button type="button" id="btn-src-local" class="flex-1 py-2 text-[10px] font-bold rounded-lg bg-transparent text-gray-500" onclick="switchIconSource('local')"><i class="fa-solid fa-upload mr-1"></i> LOCAL</button>
                </div>

                <div id="box-src-gallery" class="animate-fade-in bg-[#050810] border border-gray-800 rounded-xl p-3 mb-4">
                    <div class="grid grid-cols-3 gap-3 h-[350px] overflow-y-auto custom-scrollbar pr-2 pb-6 content-start" id="gallery-container">
                        </div>
                </div>

                <div id="box-src-import" class="hidden animate-fade-in mb-4">
                    <div class="bg-[#050810] border border-indigo-900/50 rounded-xl p-5 text-center">
                        <i class="fa-solid fa-cloud-arrow-down text-4xl text-indigo-500 mb-3"></i>
                        <p class="text-xs text-gray-300 mb-4">Pega un link de una <b>imagen</b> o de una <b>carpeta de GitHub</b> para descargarla y guardarla en tu Galería local.</p>
                        <input type="url" id="import-url" placeholder="https://ejemplo.com/icono.png" class="w-full bg-[#0a0d14] rounded-lg px-3 py-3 border border-gray-700 focus:border-indigo-500 font-mono text-xs text-white outline-none mb-4">
                        <button type="button" id="btn-cargar-url" onclick="importarURL()" class="w-full bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg py-3 font-bold text-xs tracking-widest transition-colors">
                            INICIAR IMPORTACIÓN
                        </button>
                    </div>
                </div>

                <div id="box-src-local" class="hidden animate-fade-in mb-4">
                    <div onclick="document.getElementById('icon-file').click()" class="w-full h-40 bg-[#050810] rounded-xl border border-dashed border-gray-700 hover:border-indigo-500 flex flex-col items-center justify-center cursor-pointer overflow-hidden relative">
                        <i id="icon-file-placeholder" class="fa-solid fa-upload text-3xl text-gray-600 mb-2 relative z-10"></i>
                        <span id="icon-file-name" class="text-[10px] text-gray-500 relative z-10 text-center px-4">Toca para seleccionar imagen de tu celular</span>
                        <img id="preview-img-local" src="" class="hidden absolute inset-0 w-full h-full object-contain z-20 bg-[#050810]">
                        <input type="file" id="icon-file" accept="image/png, image/jpeg" class="hidden" onchange="previewLocal(this)">
                    </div>
                </div>
            </form>
        </div>

        <div id="tab-explorer" class="tab-content relative">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-lg font-black text-yellow-500 italic tracking-wider">EXPLORADOR FTP</h2>
                <div class="flex gap-3">
                    <button onclick="promptCreateFolder()" class="text-gray-400 hover:text-white text-lg"><i class="fa-solid fa-folder-plus"></i></button>
                    <button onclick="loadExplorerPath('/')" class="text-gray-400 hover:text-white text-lg"><i class="fa-solid fa-home"></i></button>
                </div>
            </div>

            <div id="clipboard-panel" class="hidden mb-3 bg-yellow-900/30 border border-yellow-600/50 rounded-lg p-2 flex justify-between items-center">
                <div class="flex items-center gap-2 overflow-hidden pr-2">
                    <i class="fa-solid fa-scissors text-yellow-500 text-xs"></i>
                    <span id="clipboard-text" class="text-[10px] text-yellow-400 font-mono truncate">Moviendo...</span>
                </div>
                <div class="flex gap-2 shrink-0">
                    <button onclick="cancelPaste()" class="text-red-400 hover:text-red-300 text-[10px] font-bold px-2 py-1"><i class="fa-solid fa-xmark"></i></button>
                    <button onclick="executePaste()" class="bg-yellow-600 text-black px-3 py-1 rounded text-[10px] font-bold shadow-lg"><i class="fa-solid fa-paste mr-1"></i> PEGAR</button>
                </div>
            </div>

            <div class="bg-[#0a0d14] border border-gray-800 rounded-xl overflow-hidden shadow-lg">
                <div class="bg-[#121826] p-3 border-b border-gray-700 flex items-center gap-2 overflow-x-auto custom-scrollbar">
                    <i class="fa-regular fa-folder-open text-yellow-500"></i>
                    <span id="explorer-path-text" class="text-xs font-mono text-gray-300 whitespace-nowrap">/</span>
                </div>
                <div id="explorer-list" class="h-[400px] overflow-y-auto custom-scrollbar bg-[#050810] pb-6">
                    <div class="text-center text-gray-600 text-xs py-10">
                        <i class="fa-solid fa-folder-tree text-4xl mb-3 block opacity-30"></i>Configura tu IP para explorar la PS4.
                    </div>
                </div>
            </div>
        </div>

        <div id="tab-settings" class="tab-content mt-2">
            <div class="bg-[#0a0d14] border border-gray-800 rounded-2xl p-5 mb-4 shadow-lg">
                
                <div id="install-pwa-container" class="hidden mb-6 bg-gradient-to-r from-cyan-900/40 to-blue-900/40 border border-cyan-500/50 rounded-xl p-4">
                    <div class="flex items-center gap-3 mb-2">
                        <i class="fa-solid fa-download text-cyan-400 text-2xl"></i>
                        <div>
                            <span class="text-sm font-bold text-white block">Instalar como App Nativa</span>
                            <span class="text-[10px] text-gray-400">Añade Gold Hen Suite al inicio.</span>
                        </div>
                    </div>
                    <button onclick="installPWA()" class="w-full bg-cyan-600 hover:bg-cyan-500 text-white font-bold text-[10px] tracking-widest py-2 rounded-lg mt-2 transition-colors">
                        INSTALAR AHORA
                    </button>
                </div>
                
                <div class="flex items-start justify-between mb-4">
                    <div class="flex-1 pr-4">
                        <div class="flex items-center mb-1">
                            <i class="fa-solid fa-mobile-screen text-cyan-500 mr-2 text-lg"></i>
                            <span class="text-sm font-bold text-white">Anti-Apagado de Pantalla</span>
                        </div>
                        <p class="text-[10px] text-gray-500 leading-relaxed pl-6">Mantiene tu pantalla encendida forzosamente. Es vital tener esto activado si vas a enviar juegos pesados para evitar cortes.</p>
                    </div>
                    <div class="pt-1">
                        <input type="checkbox" id="toggle_wakelock" class="toggle-checkbox" checked/>
                        <label for="toggle_wakelock" class="toggle-label"></label>
                    </div>
                </div>

                <div class="w-full h-px bg-gray-800/50 mb-4 ml-6"></div>

                <div class="flex items-start justify-between">
                    <div class="flex-1 pr-4">
                        <div class="flex items-center mb-2">
                            <i class="fa-solid fa-download text-green-500 mr-2 text-lg"></i>
                            <span class="text-sm font-bold text-white">Auto-Instalar (RPI)</span>
                        </div>
                        <div class="ml-6">
                            <span class="text-[10px] text-green-400 font-bold block mb-1 tracking-wide">¿CÓMO FUNCIONA?</span>
                            <ul class="text-[10px] text-gray-500 list-disc pl-4 space-y-1.5">
                                <li>Requiere tener abierta la app <b class="text-gray-300">Remote Package Installer</b> en tu PS4.</li>
                                <li>Solo funciona con archivos <b class="text-gray-300">.pkg</b>.</li>
                                <li>Al terminar, envía un comando al puerto 12800 para instalar automáticamente.</li>
                            </ul>
                        </div>
                    </div>
                    <div class="pt-1">
                        <input type="checkbox" id="toggle_autoinstall" class="toggle-checkbox"/>
                        <label for="toggle_autoinstall" class="toggle-label"></label>
                    </div>
                </div>
            </div>
            
            <div class="text-center mt-6 opacity-40">
                <p class="text-[10px] font-mono tracking-widest">DEVELOPED BY SeBaS</p>
                <p class="text-[8px] font-mono mt-1">v20 Definitive Edition</p>
            </div>
        </div>

    </main>

    <div class="floating-btn-global floating-hidden" id="floating-btn-aplicar">
        <button onclick="ejecutarFormIconos()" class="w-full btn-3d btn-3d-indigo rounded-xl py-4 font-black tracking-widest text-sm flex items-center justify-center gap-2 bg-[#6366f1]">
            <i class="fa-solid fa-wand-magic-sparkles"></i> APLICAR ICONO A PS4
        </button>
    </div>

    <nav class="fixed bottom-0 left-0 w-full bg-[#050810]/95 backdrop-blur-lg border-t border-gray-800 z-40 pb-safe shadow-[0_-5px_15px_rgba(0,0,0,0.5)]">
        <div class="max-w-lg mx-auto flex justify-between items-center px-4 py-3">
            <button class="nav-item active flex flex-col items-center gap-1 relative" onclick="switchTab('tab-ftp', this, 0)">
                <i class="fa-solid fa-exchange-alt text-lg"></i>
                <span class="text-[8px] font-bold tracking-widest">ENVIAR</span>
            </button>
            <button class="nav-item flex flex-col items-center gap-1 relative" onclick="switchTab('tab-icons', this, 1)">
                <i class="fa-solid fa-palette text-lg"></i>
                <span class="text-[8px] font-bold tracking-widest">ICONOS</span>
            </button>
            <button class="nav-item flex flex-col items-center gap-1 relative" onclick="switchTab('tab-explorer', this, 2)">
                <i class="fa-solid fa-folder-tree text-lg"></i>
                <span class="text-[8px] font-bold tracking-widest">EXPLORAR</span>
            </button>
            <button class="nav-item flex flex-col items-center gap-1 relative" onclick="switchTab('tab-settings', this, 3)">
                <i class="fa-solid fa-gear text-lg"></i>
                <span class="text-[8px] font-bold tracking-widest">AJUSTES</span>
            </button>
        </div>
    </nav>

    <div id="overlay-sheet" class="overlay-sheet" onclick="closeItemOptions()"></div>
    <div id="bottom-sheet" class="bottom-sheet">
        <div class="w-12 h-1 bg-gray-600 rounded-full mx-auto mt-3 mb-2"></div>
        <div class="px-5 pb-5">
            <h4 id="sheet-title" class="text-white font-bold text-sm mb-4 truncate border-b border-gray-800 pb-3">...</h4>
            <div class="flex flex-col gap-2">
                <button onclick="renameCurrentItem()" class="flex items-center gap-4 p-3 rounded-xl hover:bg-gray-800 text-cyan-400 text-left transition-colors"><i class="fa-solid fa-pen w-5 text-center"></i> Renombrar</button>
                <button onclick="cutCurrentItem()" class="flex items-center gap-4 p-3 rounded-xl hover:bg-gray-800 text-yellow-400 text-left transition-colors"><i class="fa-solid fa-scissors w-5 text-center"></i> Mover (Cortar)</button>
                <button onclick="deleteCurrentItem()" class="flex items-center gap-4 p-3 rounded-xl hover:bg-red-900/30 text-red-500 text-left transition-colors"><i class="fa-solid fa-trash w-5 text-center"></i> Eliminar Archivo</button>
            </div>
        </div>
    </div>

    <div id="custom-modal" class="hidden fixed inset-0 modal-glass z-50 flex items-center justify-center px-4 opacity-0 transition-opacity duration-300">
        <div class="bg-[#0d121c] border border-cyan-500/50 rounded-3xl p-6 w-full max-w-sm text-center shadow-[0_0_40px_rgba(0,180,216,0.2)] relative transform scale-95 transition-transform duration-300" id="modal-card">
            <div id="modal-icon" class="w-16 h-16 mx-auto rounded-full bg-cyan-900/30 flex items-center justify-center mb-4 relative"></div>
            <h3 id="modal-title" class="text-lg font-black text-white tracking-widest mb-1">...</h3>
            <div id="modal-text" class="text-xs text-gray-400 mb-5 font-medium px-2 break-all">...</div>
            
            <div id="modal-progress-container" class="w-full mb-3 hidden">
                <div class="w-full bg-gray-800 rounded-full h-4 overflow-hidden border border-gray-700 relative">
                    <div id="modal-progress-bar" class="bg-gradient-to-r from-cyan-600 to-cyan-400 h-full progress-glow transition-all duration-300" style="width: 0%"></div>
                    <span id="modal-bytes" class="absolute inset-0 flex items-center justify-center text-[9px] font-bold text-white mix-blend-difference"></span>
                </div>
                <div class="flex justify-between items-center mt-2 px-1">
                    <span id="modal-speed" class="text-[10px] font-bold text-green-400 font-mono"></span>
                    <span id="modal-percentage" class="text-lg font-black text-cyan-400 font-mono drop-shadow-[0_0_8px_rgba(0,180,216,0.8)]"></span>
                </div>
            </div>

            <button id="modal-cancel-btn" type="button" onclick="cancelarEnvio()" class="hidden w-full btn-3d btn-3d-red rounded-xl py-3.5 font-bold text-xs tracking-widest mt-3 transition-transform hover:scale-105">
                <i class="fa-solid fa-ban mr-2"></i> ABORTAR
            </button>
            <button id="modal-close-btn" type="button" onclick="closeCustomModal()" class="hidden w-full btn-3d btn-3d-cyan rounded-xl py-3.5 font-bold text-xs tracking-widest mt-3 transition-transform hover:scale-105">
                CERRAR
            </button>
        </div>
    </div>

    <script>
        // **********************************************
        // DESARROLLADO POR SEBAS - NO BORRAR LOS CREDITOS
        // **********************************************

        // ==========================================
        // GESTOS TÁCTILES (SWIPE)
        // ==========================================
        let touchstartX = 0, touchendX = 0, touchstartY = 0, touchendY = 0;
        const tabsOrder = ['tab-ftp', 'tab-icons', 'tab-explorer', 'tab-settings'];
        let currentTabIndex = 0;

        document.addEventListener('touchstart', e => {
            touchstartX = e.changedTouches[0].screenX; touchstartY = e.changedTouches[0].screenY;
        }, {passive: true});

        document.addEventListener('touchend', e => {
            touchendX = e.changedTouches[0].screenX; touchendY = e.changedTouches[0].screenY; handleSwipe();
        }, {passive: true});

        function handleSwipe() {
            if(!document.getElementById('custom-modal').classList.contains('hidden') || document.getElementById('bottom-sheet').classList.contains('open')) return;
            const deltaX = touchendX - touchstartX; const deltaY = touchendY - touchstartY;
            if (Math.abs(deltaX) > Math.abs(deltaY) && Math.abs(deltaX) > 60) {
                const navButtons = document.querySelectorAll('.nav-item');
                if (deltaX < 0) { if (currentTabIndex < tabsOrder.length - 1) { currentTabIndex++; switchTab(tabsOrder[currentTabIndex], navButtons[currentTabIndex], currentTabIndex); }
                } else { if (currentTabIndex > 0) { currentTabIndex--; switchTab(tabsOrder[currentTabIndex], navButtons[currentTabIndex], currentTabIndex); } }
            }
        }

        // ==========================================
        // UI: TABS Y BOTÓN FLOTANTE
        // ==========================================
        function switchTab(tabId, btnElement, targetIndex = null) {
            document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.nav-item').forEach(b => b.classList.remove('active'));
            document.getElementById(tabId).classList.add('active'); 
            btnElement.classList.add('active'); 
            window.scrollTo({ top: 0, behavior: 'smooth' });
            
            if(targetIndex !== null) currentTabIndex = targetIndex;
            else currentTabIndex = tabsOrder.indexOf(tabId);

            const btnFlotante = document.getElementById('floating-btn-aplicar');
            if (tabId === 'tab-icons' && currentIconSource !== 'import') { btnFlotante.classList.remove('floating-hidden'); } 
            else { btnFlotante.classList.add('floating-hidden'); }

            if (tabId === 'tab-explorer' && currentExplorerPath === '') { loadExplorerPath('/'); }
        }
        function ejecutarFormIconos() { enviarIcono(); }

        // ==========================================
        // EXPLORADOR DE ARCHIVOS (CON DOBLE CONFIRMACIÓN)
        // ==========================================
        let currentExplorerPath = '';
        let optionsPath = '', optionsName = '', optionsIsDir = false;
        let clipboardPath = '', clipboardName = ''; 

        async function loadExplorerPath(path) {
            const ip = document.getElementById('host-ip').value;
            const listContainer = document.getElementById('explorer-list');
            const pathText = document.getElementById('explorer-path-text');
            if(!ip) { listContainer.innerHTML = '<div class="text-center text-red-500 text-xs py-10"><i class="fa-solid fa-triangle-exclamation mb-2 text-3xl block"></i>Configura tu IP primero.</div>'; return; }

            let displayPath = path.length > 25 ? '...' + path.slice(-25) : path;
            pathText.innerText = displayPath; currentExplorerPath = path;
            listContainer.innerHTML = '<div class="text-center text-cyan-500 text-xs py-10"><i class="fa-solid fa-circle-notch fa-spin text-3xl mb-3 block"></i>Leyendo sistema...</div>';

            const fd = new FormData(); fd.append('action', 'list_dir'); fd.append('host_ip', ip); fd.append('path', path);
            try {
                let res = await fetch('index.php', { method: 'POST', body: fd }); let data = await res.json();
                if (data.status === 'success') renderExplorer(data.data, path);
                else listContainer.innerHTML = `<div class="text-center text-red-500 text-xs py-10"><i class="fa-solid fa-folder-closed mb-2 text-3xl block"></i>${data.message}</div>`;
            } catch(e) { listContainer.innerHTML = '<div class="text-center text-red-500 text-xs py-10"><i class="fa-solid fa-wifi mb-2 text-3xl block"></i>Error de red.</div>'; }
        }

        function renderExplorer(items, currentPath) {
            const listContainer = document.getElementById('explorer-list'); listContainer.innerHTML = '';
            if (currentPath !== '/') {
                let parentPath = currentPath.substring(0, currentPath.lastIndexOf('/', currentPath.length - 2)) + '/';
                if(parentPath === '') parentPath = '/';
                listContainer.innerHTML += `<div onclick="loadExplorerPath('${parentPath}')" class="flex items-center gap-3 p-4 border-b border-gray-800/50 cursor-pointer bg-[#0a0d14] hover:bg-gray-800 transition-colors"><i class="fa-solid fa-level-up-alt text-gray-500 text-lg w-6 text-center"></i><span class="text-xs font-bold text-gray-400">.. (Carpeta Anterior)</span></div>`;
            }
            if (items.length === 0) { listContainer.innerHTML += '<div class="text-center text-gray-600 text-xs py-10"><i class="fa-regular fa-folder-open text-4xl mb-3 block opacity-30"></i>Carpeta vacía.</div>'; return; }
            items.forEach(item => {
                let isDir = item.is_dir; let icon = isDir ? 'fa-folder text-yellow-500' : 'fa-file text-gray-400';
                if(!isDir) {
                    if(item.name.endsWith('.pkg')) icon = 'fa-box-open text-indigo-400';
                    else if(item.name.endsWith('.png') || item.name.endsWith('.jpg')) icon = 'fa-image text-green-400';
                    else if(item.name.endsWith('.json') || item.name.endsWith('.xml') || item.name.endsWith('.ini')) icon = 'fa-code text-pink-400';
                }
                let nextPath = currentPath.endsWith('/') ? currentPath + item.name : currentPath + '/' + item.name;
                if(isDir) nextPath += '/';
                let clickAction = isDir ? `onclick="loadExplorerPath('${nextPath}')"` : '';
                let sizeStr = isDir ? '' : `<span class="text-[9px] text-gray-600">${(item.size / (1024*1024)).toFixed(2)} MB</span>`;

                listContainer.innerHTML += `
                    <div class="flex items-center justify-between p-3 border-b border-gray-800/30 hover:bg-gray-800/30 transition-colors">
                        <div class="flex items-center gap-3 flex-1 cursor-pointer overflow-hidden py-1" ${clickAction}>
                            <i class="fa-solid ${icon} text-2xl w-8 text-center"></i>
                            <div class="flex flex-col overflow-hidden"><span class="text-[11px] font-medium text-gray-200 truncate pr-2">${item.name}</span>${sizeStr}</div>
                        </div>
                        <button onclick="openItemOptions('${nextPath}', '${item.name}', ${isDir})" class="text-gray-500 hover:text-white p-2 px-4 ml-2"><i class="fa-solid fa-ellipsis-vertical text-lg"></i></button>
                    </div>`;
            });
        }

        function openItemOptions(path, name, isDir) {
            optionsPath = path; optionsName = name; optionsIsDir = isDir;
            document.getElementById('sheet-title').innerText = name;
            document.getElementById('overlay-sheet').classList.add('open'); document.getElementById('bottom-sheet').classList.add('open');
        }
        function closeItemOptions() { document.getElementById('overlay-sheet').classList.remove('open'); document.getElementById('bottom-sheet').classList.remove('open'); }

        async function renameCurrentItem() {
            closeItemOptions();
            let newName = prompt(`Renombrar:\n${optionsName}`, optionsName);
            if(!newName || newName === optionsName) return;
            let basePath = optionsPath.substring(0, optionsPath.lastIndexOf(optionsName));
            let newPath = basePath + newName; if(optionsIsDir) newPath += '/';
            const fd = new FormData(); fd.append('action', 'rename'); fd.append('host_ip', document.getElementById('host-ip').value); fd.append('old_path', optionsPath); fd.append('new_path', newPath);
            try {
                let res = await fetch('index.php', { method: 'POST', body: fd }); let data = await res.json();
                if(data.status === 'success') loadExplorerPath(currentExplorerPath); else alert(data.message);
            } catch(e) { alert("Error de red."); }
        }

        function cutCurrentItem() {
            closeItemOptions(); clipboardPath = optionsPath; clipboardName = optionsName;
            document.getElementById('clipboard-panel').classList.remove('hidden'); document.getElementById('clipboard-text').innerText = `Mover: ${optionsName}`;
        }
        function cancelPaste() { clipboardPath = ''; clipboardName = ''; document.getElementById('clipboard-panel').classList.add('hidden'); }
        async function executePaste() {
            if(!clipboardPath) return;
            let newPath = currentExplorerPath.endsWith('/') ? currentExplorerPath + clipboardName : currentExplorerPath + '/' + clipboardName;
            const fd = new FormData(); fd.append('action', 'rename'); fd.append('host_ip', document.getElementById('host-ip').value); fd.append('old_path', clipboardPath); fd.append('new_path', newPath);
            document.getElementById('clipboard-text').innerText = "Pegando...";
            try {
                let res = await fetch('index.php', { method: 'POST', body: fd }); let data = await res.json();
                if(data.status === 'success') { cancelPaste(); loadExplorerPath(currentExplorerPath); } else alert(data.message);
            } catch(e) { alert("Error al pegar."); }
        }

        // --- SISTEMA DE DOBLE CONFIRMACIÓN DE SEGURIDAD (SeBaS Security) ---
        async function deleteCurrentItem() {
            closeItemOptions();
            
            // Confirmación 1
            let tipoItem = optionsIsDir ? "la carpeta vacía" : "el archivo";
            let confirm1 = confirm(`¿Estás seguro de que deseas eliminar permanentemente ${tipoItem}:\n\n"${optionsName}"?`);
            if(!confirm1) return;

            // Confirmación 2 (Alerta Crítica)
            let confirm2 = confirm(`⚠️ ¡ADVERTENCIA CRÍTICA! ⚠️\n\nBorrar archivos internos de la PS4 puede corromper juegos o dañar el sistema operativo si no sabes lo que estás haciendo.\n\n¿Estás TOTALMENTE SEGURO de querer proceder a destruir:\n"${optionsName}"?`);
            if(!confirm2) return;

            // Si pasa ambas, borramos.
            const fd = new FormData(); fd.append('action', 'delete_item'); fd.append('host_ip', document.getElementById('host-ip').value);
            fd.append('path', optionsPath); fd.append('is_dir', optionsIsDir);
            try {
                let res = await fetch('index.php', { method: 'POST', body: fd }); let data = await res.json();
                if(data.status === 'success') loadExplorerPath(currentExplorerPath); else alert(data.message);
            } catch(e) { alert("Error al eliminar."); }
        }

        async function promptCreateFolder() {
            let name = prompt("Nombre de la nueva carpeta:");
            if(!name) return;
            let newPath = currentExplorerPath.endsWith('/') ? currentExplorerPath + name : currentExplorerPath + '/' + name;
            const fd = new FormData(); fd.append('action', 'mkdir'); fd.append('host_ip', document.getElementById('host-ip').value); fd.append('path', newPath);
            try {
                let res = await fetch('index.php', { method: 'POST', body: fd }); let data = await res.json();
                if(data.status === 'success') loadExplorerPath(currentExplorerPath); else alert(data.message);
            } catch(e) { alert("Error al crear carpeta."); }
        }

        // ==========================================
        // PWA REGISTRATION E INSTALACIÓN
        // ==========================================
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => { navigator.serviceWorker.register('sw.js').catch(err => console.log('SW fallo', err)); });
        }
        let deferredPrompt;
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault(); deferredPrompt = e;
            const installContainer = document.getElementById('install-pwa-container');
            if(installContainer) installContainer.classList.remove('hidden');
        });
        async function installPWA() {
            if (deferredPrompt) {
                deferredPrompt.prompt();
                const { outcome } = await deferredPrompt.userChoice;
                if (outcome === 'accepted') { document.getElementById('install-pwa-container').classList.add('hidden'); }
                deferredPrompt = null;
            }
        }

        // ==========================================
        // GALERÍA E IMPORTADOR INTELIGENTE
        // ==========================================
        const APP_PRINCIPAL_CUSA = "CUSA99999"; 
        let ICONOS_LOCALES = <?php echo json_encode($iconos_locales); ?>;
        let selectedIconType = "", selectedIconValue = "";

        function cargarGaleria(items) {
            const container = document.getElementById('gallery-container'); container.innerHTML = '';
            if(items.length === 0) { container.innerHTML = `<div class="col-span-3 text-center py-8 text-gray-500 text-xs"><i class="fa-solid fa-folder-open text-3xl mb-3 block opacity-50"></i>Galería vacía.<br>Ve a "IMPORTAR" para llenarla.</div>`; return; }
            items.forEach((item, index) => {
                if(!item.url) return;
                const urlClean = item.url.replace(/'/g, "\\'"); 
                container.innerHTML += `<div class="gallery-item group" onclick="seleccionarIconoGaleria(this, 'local_gallery', '${urlClean}')"><img src="${item.url}" alt="${item.nombre}" loading="lazy"><div class="gallery-label opacity-0 group-hover:opacity-100 transition-opacity">${item.nombre}</div></div>`;
            });
        }
        
        function seleccionarIconoGaleria(elementoDiv, tipo, urlODireccion) {
            document.querySelectorAll('.gallery-item').forEach(el => el.classList.remove('selected'));
            elementoDiv.classList.add('selected'); selectedIconType = tipo; selectedIconValue = urlODireccion;
            const cusaInput = document.getElementById('icon-cusa');
            if(!cusaInput.value) cusaInput.value = APP_PRINCIPAL_CUSA;
        }

        async function importarURL() {
            const urlInput = document.getElementById('import-url').value.trim();
            if(!urlInput) return alert("Ingresa un enlace.");
            const btnCargar = document.getElementById('btn-cargar-url'); btnCargar.disabled = true; btnCargar.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> IMPORTANDO...';

            const match = urlInput.match(/github\.com\/([^\/]+)\/([^\/]+)\/tree\/([^\/]+)\/(.+)/);
            try {
                if(match) {
                    const user = match[1], repo = match[2], branch = match[3], path = match[4];
                    const apiUrl = `https://api.github.com/repos/${user}/${repo}/contents/${path}?ref=${branch}`;
                    const res = await fetch(apiUrl); if(!res.ok) throw new Error("No se pudo leer la carpeta de GitHub.");
                    const data = await res.json();
                    const files = data.filter(f => f.name.match(/\.(jpeg|jpg|gif|png)$/i));
                    if(files.length === 0) throw new Error("No hay imágenes ahí.");

                    for(let i=0; i<files.length; i++) {
                        btnCargar.innerText = `DESCARGANDO ${i+1}/${files.length}`;
                        await descargarAGaleria(files[i].download_url, files[i].name);
                    }
                } else { await descargarAGaleria(urlInput, "icon_" + Date.now() + ".png"); }
                
                // FIX: Evitamos recargar toda la página bruscamente.
                alert("¡Importación completada! Recarga la app para ver los nuevos iconos.");
                btnCargar.innerText = "INICIAR IMPORTACIÓN"; 
                btnCargar.disabled = false;
                document.getElementById('import-url').value = '';

            } catch(e) { alert(e.message); btnCargar.disabled = false; btnCargar.innerText = "INICIAR IMPORTACIÓN"; }
        }

        async function descargarAGaleria(url, name) {
            const fd = new FormData(); fd.append('action', 'download_to_gallery'); fd.append('url', url); fd.append('name', name);
            await fetch('index.php', {method: 'POST', body: fd});
        }

        let currentIconSource = 'gallery';
        function switchIconSource(type) {
            currentIconSource = type;
            const bG = document.getElementById('btn-src-gallery'), bI = document.getElementById('btn-src-import'), bL = document.getElementById('btn-src-local');
            const bxG = document.getElementById('box-src-gallery'), bxI = document.getElementById('box-src-import'), bxL = document.getElementById('box-src-local');
            const btnFlotante = document.getElementById('floating-btn-aplicar');
            
            [bG, bI, bL].forEach(b => b.className = "flex-1 py-2 text-[10px] font-bold rounded-lg bg-transparent text-gray-500");
            [bxG, bxI, bxL].forEach(b => b.classList.add('hidden'));

            if (type === 'gallery') { bG.className = "flex-1 py-2 text-[10px] font-bold rounded-lg bg-indigo-600 text-white"; bxG.classList.remove('hidden'); btnFlotante.classList.remove('floating-hidden'); } 
            else if (type === 'import') { bI.className = "flex-1 py-2 text-[10px] font-bold rounded-lg bg-indigo-600 text-white"; bxI.classList.remove('hidden'); btnFlotante.classList.add('floating-hidden'); } 
            else { bL.className = "flex-1 py-2 text-[10px] font-bold rounded-lg bg-indigo-600 text-white"; bxL.classList.remove('hidden'); btnFlotante.classList.remove('floating-hidden'); }
        }

        function previewLocal(input) {
            const img = document.getElementById('preview-img-local'), placeholder = document.getElementById('icon-file-placeholder'), name = document.getElementById('icon-file-name');
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) { img.src = e.target.result; img.classList.remove('hidden'); placeholder.classList.add('hidden'); name.classList.add('hidden'); }
                reader.readAsDataURL(input.files[0]); selectedIconType = 'local';
            }
        }

        async function enviarIcono(e) {
            if(e) e.preventDefault();
            const ip = document.getElementById('host-ip').value; const cusa = document.getElementById('icon-cusa').value;
            if(!ip) { alert("Configura la IP de la PS4 en la pestaña ENVIAR."); switchTab('tab-ftp', document.querySelectorAll('.nav-item')[0], 0); return; }

            const formData = new FormData(); formData.append('action', 'upload_icon'); formData.append('host_ip', ip); formData.append('cusa_id', cusa);

            if (currentIconSource === 'gallery') {
                if(!selectedIconValue) return alert("Toca un icono de la galería primero.");
                formData.append('source_type', selectedIconType); formData.append('icon_path', selectedIconValue);
            } else if (currentIconSource === 'local') {
                const file = document.getElementById('icon-file').files[0];
                if(!file) return alert("Selecciona una imagen de tu celular.");
                formData.append('source_type', 'local'); formData.append('local_icon', file);
            } else { return; } 

            mostrarCarga("APLICANDO ICONO", `Actualizando /user/appmeta/${cusa.toUpperCase()}/...`);
            try {
                let res = await fetch('index.php', { method: 'POST', body: formData }); let data = await res.json();
                if(data.status === 'success') {
                    let avisoReinicio = `${data.message}<br><br><div class="bg-yellow-900/40 border border-yellow-600/50 rounded-xl p-3 mt-2 text-left"><span class="text-[10px] text-yellow-400 font-bold block tracking-widest mb-1"><i class="fa-solid fa-power-off mr-1"></i> REINICIO NECESARIO</span><span class="text-[9px] text-yellow-500/80">Reinicia la PS4 o reconstruye la base de datos para ver los cambios.</span></div>`;
                    mostrarExitoSimple("PORTADA ACTUALIZADA", avisoReinicio);
                } else { mostrarErrorFinal("ERROR DE MODDING", data.message); }
            } catch(e) { mostrarErrorFinal("ERROR", "Fallo de red al aplicar el icono."); }
        }

        // ==========================================
        // SISTEMA DE MONITOREO Y CONEXIÓN
        // ==========================================
        let connectionMonitorInterval = null;

        function setPS4State(isConnected) {
            const badge = document.getElementById('badge-detectada'), dot = document.getElementById('badge-dot'), text = document.getElementById('badge-text');
            badge.classList.remove('hidden');
            if(isConnected) {
                dot.className = "w-2.5 h-2.5 rounded-full bg-green-400 animate-pulse"; text.innerText = "PS4 DETECTADA";
                badge.className = "absolute bottom-6 right-4 bg-black/80 border border-green-500 rounded-full px-3 py-1.5 text-[10px] font-bold flex items-center gap-2 z-20 shadow-lg shadow-green-900/50 transition-all duration-300";
            } else {
                dot.className = "w-2.5 h-2.5 rounded-full bg-red-500"; text.innerText = "DESCONECTADA";
                badge.className = "absolute bottom-6 right-4 bg-red-900/80 border border-red-500 rounded-full px-3 py-1.5 text-[10px] font-bold flex items-center gap-2 z-20 shadow-lg shadow-red-900/50 transition-all duration-300";
            }
        }

        function startConnectionMonitor(ip) {
            if(connectionMonitorInterval) clearInterval(connectionMonitorInterval);
            connectionMonitorInterval = setInterval(async () => {
                if(!navigator.onLine) { setPS4State(false); return; }
                try { let res = await fetch(`index.php?action=ping&ip=${ip}`); let data = await res.json(); setPS4State(data.status === 'success'); } catch(e) { setPS4State(false); }
            }, 10000); 
        }

        window.addEventListener('offline', () => setPS4State(false));
        window.addEventListener('online', () => { const ip = document.getElementById('host-ip').value; if(ip) startConnectionMonitor(ip); });

        // ==========================================
        // ESCANER ULTRA RÁPIDO Y CARGA INICIAL
        // ==========================================
        const SUBRED_PHP = "<?php echo $subred_actual; ?>";
        
        document.addEventListener('DOMContentLoaded', () => {
            if(ICONOS_LOCALES.length > 0) cargarGaleria(ICONOS_LOCALES);
            const savedIp = localStorage.getItem('ps4_ip_guardada');
            if(savedIp) { document.getElementById('host-ip').value = savedIp; setPS4State(true); startConnectionMonitor(savedIp); }
            const savedFolders = JSON.parse(localStorage.getItem('ps4_custom_folders')) || [];
            savedFolders.forEach(folder => crearBotonCarpeta(folder, false));
            if(window.location.hash === '#tab-icons') switchTab('tab-icons', document.querySelectorAll('.nav-item')[1], 1);
        });

        const ipInput = document.getElementById('host-ip'), globalStatus = document.getElementById('global-status'), scanText = document.getElementById('scan-text');
        let isScanning = false, abortController = null, radarAnimInterval = null;

        function clearIP() { if(isScanning) toggleRealScan(); ipInput.value = ''; localStorage.removeItem('ps4_ip_guardada'); document.getElementById('badge-detectada').classList.add('hidden'); if(connectionMonitorInterval) clearInterval(connectionMonitorInterval); }

        async function toggleRealScan() {
            if (isScanning) { if (abortController) abortController.abort(); detenerUI(); return; }
            isScanning = true; ipInput.value = ''; ipInput.disabled = true; globalStatus.classList.remove('hidden'); 
            const btnScan = document.getElementById('btn-scan'); btnScan.classList.replace('btn-3d-cyan', 'btn-3d-red'); btnScan.classList.add('is-scanning'); document.getElementById('scan-icon').className = 'fa-solid fa-stop text-white';
            
            radarAnimInterval = setInterval(() => { scanText.innerText = `BUSCANDO: 192.168.${Math.floor(Math.random() * 255)}.${Math.floor(Math.random() * 254) + 1}`; }, 60);

            abortController = new AbortController(); const signal = abortController.signal;
            try {
                let ipsToScan = ["192.168.0.22"];
                for (let i = 2; i < 255; i++) { let ip = SUBRED_PHP + i; if(!ipsToScan.includes(ip)) ipsToScan.push(ip); }
                for (let i = 2; i < 255; i++) { let ip = "192.168.0." + i; if(!ipsToScan.includes(ip)) ipsToScan.push(ip); }
                for (let i = 2; i < 255; i++) { let ip = "192.168.100." + i; if(!ipsToScan.includes(ip)) ipsToScan.push(ip); }

                const BATCH_SIZE = 20; let foundIp = null;
                for (let i = 0; i < ipsToScan.length; i += BATCH_SIZE) {
                    if (signal.aborted) break;
                    const batch = ipsToScan.slice(i, i + BATCH_SIZE);
                    const promises = batch.map(ip => 
                        fetch(`index.php?action=ping&ip=${ip}`, { signal }).then(res => res.json())
                            .then(data => { if (data.status === 'success') throw data; return null; })
                            .catch(err => { if (err.status === 'success') return err.ip; if (err.name === 'AbortError') throw err; return null; })
                    );
                    try { const results = await Promise.all(promises); const winner = results.find(res => res !== null); if (winner) { foundIp = winner; break; } } catch(e) { if (e.name === 'AbortError') break; }
                }

                if (foundIp) { detenerUI(); ipInput.value = foundIp; localStorage.setItem('ps4_ip_guardada', foundIp); setPS4State(true); startConnectionMonitor(foundIp); } 
                else if (!signal.aborted) { detenerUI(); alert("No se encontró ninguna PS4 con GoldHen."); }
            } catch (e) { detenerUI(); }
        }

        function detenerUI() { isScanning = false; ipInput.disabled = false; globalStatus.classList.add('hidden'); clearInterval(radarAnimInterval); const btnScan = document.getElementById('btn-scan'); btnScan.classList.replace('btn-3d-red', 'btn-3d-cyan'); btnScan.classList.remove('is-scanning'); document.getElementById('scan-icon').className = 'fa-solid fa-satellite-dish text-[#050810]'; }
        function connectManualIP() { const ip = ipInput.value.trim(); if(ip) { localStorage.setItem('ps4_ip_guardada', ip); setPS4State(true); startConnectionMonitor(ip); } }

        // ==========================================
        // SUBIDA FTP CHUNKS Y RUTAS (ENVIAR)
        // ==========================================
        const hiddenPathInput = document.getElementById('selected-path-input'), pathsGrid = document.getElementById('paths-grid'), addPathUI = document.getElementById('add-path-ui'), newPathInput = document.getElementById('new-path-input'), btnOtra = document.getElementById('btn-otra');
        function selectPath(element, ruta) { if(element.tagName.toLowerCase() === 'i') return; document.querySelectorAll('.folder-btn').forEach(el => { if(el.id !== 'btn-otra') el.classList.remove('active'); }); element.classList.add('active'); hiddenPathInput.value = ruta; }
        function removePath(event, iconElement, ruta) { event.stopPropagation(); const btn = iconElement.closest('.folder-btn'); let savedFolders = JSON.parse(localStorage.getItem('ps4_custom_folders')) || []; savedFolders = savedFolders.filter(f => f !== ruta); localStorage.setItem('ps4_custom_folders', JSON.stringify(savedFolders)); if(btn.classList.contains('active')) { btn.remove(); document.querySelector('.folder-btn').click(); } else { btn.remove(); } }
        function showAddPathUI() { addPathUI.classList.remove('hidden'); newPathInput.focus(); }
        function hideAddPathUI() { addPathUI.classList.add('hidden'); newPathInput.value = ''; }
        function saveNewPath() { const val = newPathInput.value.trim(); if (val === '') return hideAddPathUI(); let formattedPath = val.startsWith('/') ? val : '/' + val; formattedPath = formattedPath.endsWith('/') ? formattedPath : formattedPath + '/'; crearBotonCarpeta(formattedPath, true); }
        function crearBotonCarpeta(fPath, gLocal=true) { if (gLocal) { let sF = JSON.parse(localStorage.getItem('ps4_custom_folders')) || []; if (!sF.includes(fPath)) { sF.push(fPath); localStorage.setItem('ps4_custom_folders', JSON.stringify(sF)); } } const newBtn = document.createElement('button'); newBtn.type = 'button'; newBtn.className = 'folder-btn flex items-center justify-center gap-2 relative'; newBtn.setAttribute('onclick', `selectPath(this, '${fPath}')`); newBtn.innerHTML = `<span class="truncate max-w-[80%]">${fPath}</span><i class="fa-solid fa-xmark text-[10px] absolute right-2 opacity-50 hover:text-red-500 p-2" onclick="removePath(event, this, '${fPath}')"></i>`; pathsGrid.insertBefore(newBtn, btnOtra); hideAddPathUI(); newBtn.click(); }
        function updateFileName(input) { const display = document.getElementById('file-name-display'); const iconContainer = document.getElementById('upload-icon-container'); if (input.files && input.files.length > 0) { display.innerText = input.files.length === 1 ? input.files[0].name : input.files.length + " ARCHIVOS LISTOS"; display.classList.replace('text-gray-400', 'text-cyan-400'); iconContainer.innerHTML = '<i class="fa-solid fa-file-circle-check text-2xl"></i>'; iconContainer.classList.add('bg-cyan-900/30', 'text-cyan-300', 'border-cyan-500'); iconContainer.classList.remove('border-gray-800'); } else { display.innerText = 'TOCA PARA SELECCIONAR ARCHIVOS'; display.classList.replace('text-cyan-400', 'text-gray-400'); iconContainer.innerHTML = '<i class="fa-solid fa-folder-plus text-2xl"></i>'; iconContainer.classList.remove('bg-cyan-900/30', 'text-cyan-300', 'border-cyan-500'); iconContainer.classList.add('border-gray-800'); } }

        const modal = document.getElementById('custom-modal'), modalCard = document.getElementById('modal-card'), modalIcon = document.getElementById('modal-icon'), modalTitle = document.getElementById('modal-title'), modalText = document.getElementById('modal-text'), modalProgressContainer = document.getElementById('modal-progress-container'), modalProgressBar = document.getElementById('modal-progress-bar'), modalPercentage = document.getElementById('modal-percentage'), modalSpeed = document.getElementById('modal-speed'), modalBytes = document.getElementById('modal-bytes'), modalCloseBtn = document.getElementById('modal-close-btn'), modalCancelBtn = document.getElementById('modal-cancel-btn');
        let uploadAbortController = null;

        function mostrarCarga(titulo, subtitulo) {
            modalTitle.innerText = titulo; modalTitle.className = "text-lg font-black text-cyan-400 tracking-widest mb-2"; modalText.innerHTML = subtitulo;
            modalIcon.innerHTML = '<div class="absolute inset-0 rounded-full border border-cyan-500 opacity-50 animate-ping"></div><i class="fa-solid fa-rocket text-3xl text-cyan-400 relative z-10 fa-bounce"></i>';
            modalProgressContainer.classList.add('hidden'); modalCancelBtn.classList.add('hidden'); modalCloseBtn.classList.add('hidden');
            modal.classList.remove('hidden'); setTimeout(() => { modal.classList.remove('opacity-0'); modalCard.classList.remove('scale-95'); }, 10);
        }

        function mostrarExitoSimple(titulo, msg) {
            modalTitle.innerText = titulo; modalTitle.className = "text-lg font-black text-indigo-400 tracking-widest mb-2"; modalText.innerHTML = msg; 
            modalProgressContainer.classList.add('hidden'); modalCancelBtn.classList.add('hidden'); modalCloseBtn.classList.remove('hidden');
            modalIcon.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles text-4xl text-indigo-400"></i>';
            modalIcon.className = "w-16 h-16 mx-auto rounded-full bg-indigo-900/30 flex items-center justify-center mb-4 shadow-[0_0_20px_rgba(99,102,241,0.5)]";
            modalCloseBtn.className = "w-full btn-3d btn-3d-indigo rounded-xl py-3.5 font-bold text-xs tracking-widest mt-3 transition-transform hover:scale-105"; modalCloseBtn.innerText = "GENIAL";
        }

        let wakeLock = null;
        async function requestWakeLock() { if (!document.getElementById('toggle_wakelock').checked) return; try { if ('wakeLock' in navigator) wakeLock = await navigator.wakeLock.request('screen'); } catch (err) {} }
        function releaseWakeLock() { if (wakeLock !== null) { wakeLock.release().then(() => wakeLock = null); } }

        async function enviarArchivoChunks(event) {
            event.preventDefault(); const files = document.getElementById('file-upload').files; if (files.length === 0 || !ipInput.value) return;
            await requestWakeLock(); uploadAbortController = new AbortController(); const signal = uploadAbortController.signal;
            mostrarCarga("PREPARANDO", "Iniciando motor de transferencia..."); modalProgressContainer.classList.remove('hidden'); modalCancelBtn.classList.remove('hidden');
            let isCanceled = false, filesSuccess = 0;
            
            for (let f = 0; f < files.length; f++) {
                if (signal.aborted) { isCanceled = true; break; }
                
                // FIX: Chunk size reducido a 2MB para compatibilidad universal con PHP
                const file = files[f], chunkSize = 2 * 1024 * 1024, totalChunks = Math.ceil(file.size / chunkSize);
                
                let uploadedBytes = 0, startTime = new Date().getTime(), fileTotalGB = (file.size / (1024 * 1024 * 1024)).toFixed(2);
                modalTitle.innerText = `ENVIANDO (${f + 1}/${files.length})`; modalText.innerHTML = `<b>${file.name}</b>`;
                modalProgressBar.style.width = '0%'; modalPercentage.innerText = '0%'; modalBytes.innerText = `0 GB / ${fileTotalGB} GB`;
                let fileError = null;
                for (let i = 0; i < totalChunks; i++) {
                    if (signal.aborted) { isCanceled = true; break; }
                    const chunk = file.slice(i * chunkSize, Math.min((i * chunkSize) + chunkSize, file.size));
                    const formData = new FormData(); formData.append('action', 'upload_chunk'); formData.append('host_ip', ipInput.value); formData.append('selected_path', hiddenPathInput.value); formData.append('file_name', file.name); formData.append('chunk_index', i); formData.append('archivo_subida', chunk);
                    try {
                        let res = await fetch('index.php', { method: 'POST', body: formData, signal }); let data = await res.json();
                        if (data.status !== 'success') throw new Error(data.message || "Fallo FTP");
                        uploadedBytes += chunk.size; let pct = Math.round((uploadedBytes / file.size) * 100);
                        modalProgressBar.style.width = pct + '%'; modalPercentage.innerText = pct + '%'; modalBytes.innerText = `${(uploadedBytes / (1024 * 1024 * 1024)).toFixed(2)} GB / ${fileTotalGB} GB`;
                        let tElapsed = (new Date().getTime() - startTime) / 1000; 
                        if(tElapsed > 1) modalSpeed.innerHTML = `<i class="fa-solid fa-bolt mr-1"></i> ${((uploadedBytes / tElapsed) / (1024 * 1024)).toFixed(2)} MB/s`;
                    } catch (error) { if (error.name === 'AbortError') isCanceled = true; else fileError = error.message; break; }
                }
                if (isCanceled) break; if (fileError) { mostrarErrorFinal("ERROR", fileError); return; } else { filesSuccess++; }
            }
            releaseWakeLock();
            
            if (isCanceled) { 
                mostrarErrorFinal("ABORTADO", "Has cancelado la cola."); 
            } else if (filesSuccess === files.length) {
                // FIX: Lógica añadida para ejecutar la API del Remote Package Installer (Auto-Install)
                modalProgressContainer.classList.add('hidden'); modalCancelBtn.classList.add('hidden'); modalCloseBtn.classList.remove('hidden');
                modalTitle.innerText = "¡MISIÓN CUMPLIDA!"; modalTitle.className = "text-lg font-black text-green-400 tracking-widest mb-2";
                
                let extraMsg = '';
                if (document.getElementById('toggle_autoinstall').checked) {
                    let pkgFiles = Array.from(files).filter(f => f.name.toLowerCase().endsWith('.pkg'));
                    if (pkgFiles.length > 0) {
                        extraMsg = `<br><br><span class="text-[10px] text-green-300">Enviando comando de instalación al RPI...</span>`;
                        pkgFiles.forEach(pkg => {
                            let pkgPath = (hiddenPathInput.value.endsWith('/') ? hiddenPathInput.value : hiddenPathInput.value + '/') + pkg.name;
                            let payload = { "type": "direct", "packages": [pkgPath] };
                            fetch(`http://${ipInput.value}:12800/api/install`, {
                                method: 'POST',
                                mode: 'no-cors',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify(payload)
                            }).catch(e => console.log("RPI Error:", e));
                        });
                    }
                }

                modalText.innerHTML = `<b>¡Éxito!</b> Todos los archivos transferidos.${extraMsg}`; 
                modalIcon.innerHTML = '<i class="fa-solid fa-trophy text-4xl text-green-400"></i>';
                modalIcon.className = "w-16 h-16 mx-auto rounded-full bg-green-900/30 flex items-center justify-center mb-4 shadow-[0_0_20px_rgba(16,185,129,0.5)]";
                modalCloseBtn.className = "w-full btn-3d btn-3d-green rounded-xl py-3.5 font-bold text-xs tracking-widest mt-4 transition-transform hover:scale-105"; modalCloseBtn.innerText = "CERRAR";
                
                document.getElementById('file-upload').value = ''; updateFileName(document.getElementById('file-upload'));
            }
        }
        function cancelarEnvio() { if (uploadAbortController) uploadAbortController.abort(); }
        function mostrarErrorFinal(titulo, msg) {
            modalProgressContainer.classList.add('hidden'); modalCancelBtn.classList.add('hidden'); modalCloseBtn.classList.remove('hidden');
            modalTitle.innerText = titulo; modalTitle.className = "text-lg font-black text-red-500 tracking-widest mb-2"; modalText.innerText = msg;
            modalIcon.innerHTML = '<i class="fa-solid fa-triangle-exclamation text-4xl text-red-500"></i>';
            modalIcon.className = "w-16 h-16 mx-auto rounded-full bg-red-900/30 flex items-center justify-center mb-4 shadow-[0_0_20px_rgba(239,35,60,0.5)]";
            modalCloseBtn.className = "w-full btn-3d btn-3d-red rounded-xl py-3.5 font-bold text-xs tracking-widest mt-4 transition-transform hover:scale-105"; modalCloseBtn.innerText = "ENTENDIDO";
            if (titulo === "ABORTADO") { document.getElementById('file-upload').value = ''; updateFileName(document.getElementById('file-upload')); }
        }
        function closeCustomModal() { modal.classList.add('opacity-0'); modalCard.classList.add('scale-95'); setTimeout(() => modal.classList.add('hidden'), 300); }
    </script>
</body>
</html>
