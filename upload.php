<?php
session_start();
session_regenerate_id(true);

// 定义用户账户文件路径
$userAccountsFile = __DIR__ . '/1/user_accounts.txt';

// 配置信息
$config = [
    'upload_dir' => __DIR__ . '/files/',
    'log_dir' => __DIR__ . '/logs/',
    'max_size' => 10 * 1024 * 1024 * 1024, // 10GB
    // 用户信息将动态加载
];

// 从文件加载用户账户信息
function loadUserAccounts($filePath) {
    $users = [];
    
    // 如果用户账户文件存在，则读取内容
    if (file_exists($filePath)) {
        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            // 分割每行为用户名和密码哈希
            $parts = explode(':', trim($line), 2);
            if (count($parts) === 2) {
                list($username, $passwordHash) = $parts;
                $users[$username] = $passwordHash;
            }
        }
    }
    
    // 如果没有用户，添加默认管理员账户（带安全提示）
    if (empty($users)) {
        $defaultUser = 'admin';
        $defaultPassword = 'securepass123';
        $users[$defaultUser] = password_hash($defaultPassword, PASSWORD_DEFAULT);
        
        // 创建用户账户文件
        file_put_contents($filePath, "$defaultUser:{$users[$defaultUser]}", LOCK_EX);
        
        // 创建必要的目录
        if (!file_exists(__DIR__ . '/1')) {
            mkdir(__DIR__ . '/1', 0755, true);
        }
    }
    
    return $users;
}

// 加载用户账户
$config['users'] = loadUserAccounts($userAccountsFile);

// 创建上传和日志目录
if (!file_exists($config['upload_dir'])) {
    mkdir($config['upload_dir'], 0755, true);
    file_put_contents($config['upload_dir'] . 'index.html', '');
}
if (!file_exists($config['log_dir'])) {
    mkdir($config['log_dir'], 0755, true);
    file_put_contents($config['log_dir'] . 'index.html', '');
}

// 文件大小格式化函数
function formatSize($bytes) {
    if ($bytes === false || $bytes == 0) return '未知';
    if ($bytes >= 1099511627776) return number_format($bytes / 1099511627776, 2) . ' TB';
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' bytes';
}

// 日志记录函数
function logEvent($event, $details = '', $status = 'INFO') {
    global $config;
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] [$status] [$ip] $event" . (!empty($details) ? " - $details" : "");
    $logFile = $config['log_dir'] . 'log_' . date('Y-m-d') . '.txt';
    file_put_contents($logFile, $logEntry . PHP_EOL, FILE_APPEND | LOCK_EX);
    return $logEntry;
}

$message = '';
$isLoggedIn = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;
$currentUser = $_SESSION['username'] ?? '';

// 处理登录请求
if (isset($_POST['login'])) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    // 重新加载用户数据确保最新
    $config['users'] = loadUserAccounts($userAccountsFile);
    
    if (isset($config['users'][$username])) {
        if (password_verify($password, $config['users'][$username])) {
            $_SESSION['loggedin'] = true;
            $_SESSION['username'] = $username;
            $isLoggedIn = true;
            $currentUser = $username;
            $message = "登录成功！欢迎 $username";
            logEvent("用户登录", "用户名: $username", "SUCCESS");
        } else {
            $message = "用户名或密码错误";
            logEvent("登录失败", "用户名: $username", "WARNING");
        }
    } else {
        $message = "用户不存在";
        logEvent("登录尝试", "尝试登录不存在的用户: $username", "WARNING");
    }
}

// 处理登出请求
if (isset($_GET['logout'])) {
    logEvent("用户登出", "用户名: $currentUser", "INFO");
    session_destroy();
    header("Location: " . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// 处理文件上传
if ($isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['file'])) {
        $file = $_FILES['file'];
        $originalName = basename($file['name']);
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $message = "上传失败，错误代码: " . $file['error'];
            logEvent("文件上传失败", "文件名: $originalName, 错误代码: {$file['error']}", "ERROR");
        } 
        elseif ($file['size'] > $config['max_size']) {
            $message = "文件太大，最大允许 10GB";
            logEvent("文件上传被拒绝", "文件名: $originalName, 大小: " . formatSize($file['size']), "WARNING");
        }
        else {
            $sanitizedName = preg_replace("/[^a-zA-Z0-9\._-]/", "", $originalName);
            $extension = strtolower(pathinfo($sanitizedName, PATHINFO_EXTENSION));
            $targetPath = $config['upload_dir'] . uniqid() . '_' . $sanitizedName;
            
            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                $message = "文件上传成功！";
                logEvent("文件上传成功", "用户名: $currentUser, 文件名: $originalName, 存储为: " . basename($targetPath), "SUCCESS");
            } else {
                $message = "保存文件时出错";
                logEvent("文件保存失败", "用户名: $currentUser, 文件名: $originalName", "ERROR");
            }
        }
    }
    // 处理文件重命名
    elseif (isset($_POST['rename'])) {
        $oldName = $_POST['old_name'] ?? '';
        $newName = $_POST['new_name'] ?? '';
        
        if (!empty($oldName) && !empty($newName)) {
            $sanitizedOldName = basename($oldName);
            $sanitizedNewName = basename($newName);
            
            $oldPath = $config['upload_dir'] . $sanitizedOldName;
            $newPath = $config['upload_dir'] . $sanitizedNewName;
            
            if (file_exists($oldPath)) {
                if (!file_exists($newPath)) {
                    if (rename($oldPath, $newPath)) {
                        $message = "文件重命名成功！";
                        logEvent("文件重命名", "用户名: $currentUser, 旧文件名: $sanitizedOldName, 新文件名: $sanitizedNewName", "INFO");
                    } else {
                        $message = "重命名操作失败";
                        logEvent("重命名失败", "用户名: $currentUser, 旧文件名: $sanitizedOldName, 新文件名: $sanitizedNewName", "ERROR");
                    }
                } else {
                    $message = "新文件名已存在";
                    logEvent("重命名失败", "用户名: $currentUser, 新文件名已存在: $sanitizedNewName", "WARNING");
                }
            } else {
                $message = "原始文件不存在";
                logEvent("重命名失败", "用户名: $currentUser, 原始文件不存在: $sanitizedOldName", "WARNING");
            }
        } else {
            $message = "请提供有效的文件名";
            logEvent("重命名失败", "用户名: $currentUser, 提供的文件名无效", "WARNING");
        }
    }
}

// 处理文件下载
if ($isLoggedIn && isset($_GET['download'])) {
    $filename = basename($_GET['download']);
    $filepath = $config['upload_dir'] . $filename;
    
    if (file_exists($filepath) && is_file($filepath)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.basename($filepath).'"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filepath));
        flush();
        readfile($filepath);
        logEvent("文件下载", "用户名: $currentUser, 文件名: $filename", "INFO");
        exit;
    } else {
        $message = "请求下载的文件不存在或已被删除";
        logEvent("文件下载失败", "用户名: $currentUser, 文件名: $filename", "WARNING");
    }
}

// 安全获取文件属性
function getFileAttributes($filePath) {
    if (!file_exists($filePath) || !is_file($filePath)) {
        return ['size' => false, 'modified' => false];
    }
    
    return [
        'size' => filesize($filePath),
        'modified' => filemtime($filePath)
    ];
}

// 获取已上传文件列表
$fileList = [];
if ($isLoggedIn && is_dir($config['upload_dir'])) {
    $files = scandir($config['upload_dir']);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            $filePath = $config['upload_dir'] . $file;
            
            // 安全获取文件属性
            $attributes = getFileAttributes($filePath);
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            
            $fileInfo = [
                'name' => $file,
                'path' => $filePath,
                'size' => $attributes['size'],
                'modified' => $attributes['modified'],
                'extension' => $extension,
                'icon' => getFileIcon($extension),
                'previewable' => isPreviewable($extension)
            ];
            $fileList[] = $fileInfo;
        }
    }
    
    // 按修改时间排序（最近的在前）
    usort($fileList, function($a, $b) {
        return ($b['modified'] ?? 0) <=> ($a['modified'] ?? 0);
    });
}

// 获取日志文件列表
$logFiles = [];
if ($isLoggedIn && is_dir($config['log_dir'])) {
    $files = scandir($config['log_dir']);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..' && pathinfo($file, PATHINFO_EXTENSION) === 'txt') {
            $filePath = $config['log_dir'] . $file;
            $attributes = getFileAttributes($filePath);
            
            $lines = 0;
            if (file_exists($filePath)) {
                $lines = count(file($filePath));
            }
            
            $fileInfo = [
                'name' => $file,
                'size' => $attributes['size'],
                'modified' => $attributes['modified'],
                'lines' => $lines
            ];
            $logFiles[] = $fileInfo;
        }
    }
    
    // 按文件名排序（最近的日期在前）
    usort($logFiles, function($a, $b) {
        return strcmp($b['name'], $a['name']);
    });
}

// 根据文件扩展名获取图标
function getFileIcon($extension) {
    $icons = [
        'jpg' => '📷', 'jpeg' => '📷', 'png' => '🖼️', 'gif' => '🖼️',
        'pdf' => '📄', 'txt' => '📝', 'doc' => '📄', 'docx' => '📄',
        'xls' => '📊', 'xlsx' => '📊', 'zip' => '📦', 'rar' => '📦',
        'mp3' => '🎵', 'mp4' => '🎬', 'mov' => '🎬', 'avi' => '🎬',
        'exe' => '⚙️', 'dmg' => '💾', 'psd' => '🎨', 'ai' => '🎨',
        'log' => '📋'
    ];
    return $icons[$extension] ?? '📁';
}

// 检查文件是否可预览
function isPreviewable($extension) {
    $previewable = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'txt'];
    return in_array($extension, $previewable);
}

// 格式化时间
function formatTime($timestamp) {
    if ($timestamp === false) return '未知';
    return date('Y-m-d H:i', $timestamp);
}

// 获取日志内容
function getLogContent($filename, $maxLines = 1000) {
    global $config;
    $filePath = $config['log_dir'] . $filename;
    
    if (file_exists($filePath) && is_file($filePath)) {
        $file = file($filePath);
        if (!$file) return [];
        
        $lineCount = count($file);
        $startLine = max(0, $lineCount - $maxLines);
        
        return array_slice($file, $startLine);
    }
    
    return [];
}

// 获取当前选中的日志文件
$selectedLog = '';
if ($isLoggedIn && isset($_GET['log']) && preg_match('/^log_\d{4}-\d{2}-\d{2}\.txt$/', $_GET['log'])) {
    $selectedLog = $_GET['log'];
}

$logContent = [];
if ($isLoggedIn && !empty($selectedLog)) {
    $logContent = getLogContent($selectedLog);
}

// 获取预览文件
$previewFile = '';
$previewPath = '';
$previewContent = '';
$previewType = '';
$previewSize = 0;
if ($isLoggedIn && isset($_GET['preview'])) {
    $previewFile = basename($_GET['preview']);
    $filePath = $config['upload_dir'] . $previewFile;
    
    if (file_exists($filePath)) {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (isPreviewable($extension)) {
            $previewType = $extension;
            $previewPath = $filePath;
            $previewSize = filesize($filePath);
            
            if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'pdf'])) {
                // 不需要读取内容
            } elseif ($extension === 'txt') {
                if ($previewSize <= 1048576) {
                    $previewContent = file_get_contents($filePath);
                } else {
                    $previewContent = false;
                }
            }
        }
    } else {
        $message = "请求的文件不存在或已被删除";
        logEvent("文件预览失败", "用户名: $currentUser, 文件名: $previewFile", "WARNING");
    }
}

// 获取消息类型对应的CSS类
function getMessageClass($message) {
    if (strpos($message, '成功') !== false) return 'success';
    if (strpos($message, '错误') !== false) return 'error';
    if (strpos($message, '警告') !== false) return 'warning';
    return 'info';
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>多用户文件上传系统</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/viewerjs/1.11.1/viewer.min.css">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #0f2027, #203a43, #2c5364);
            min-height: 100vh;
            padding: 20px;
            color: #333;
        }
        
        .container {
            max-width: 1400px;
            margin: 40px auto;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 15px;
            box-shadow: 0 15px 50px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            min-height: 90vh;
        }
        
        .header {
            background: linear-gradient(90deg, #2c3e50, #4a6fc7);
            color: white;
            padding: 25px;
            text-align: center;
            position: relative;
        }
        
        .header h1 {
            font-size: 2.5rem;
            margin-bottom: 10px;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }
        
        .header p {
            font-size: 1.1rem;
            opacity: 0.9;
            max-width: 700px;
            margin: 0 auto;
        }
        
        .user-badge {
            position: absolute;
            top: 20px;
            right: 20px;
            background: rgba(255,255,255,0.2);
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
            color: white;
            display: flex;
            align-items: center;
        }
        
        .nav-tabs {
            display: flex;
            background: #f5f7fa;
            border-bottom: 1px solid #e1e5ee;
        }
        
        .tab-btn {
            padding: 15px 25px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            color: #555;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
        }
        
        .tab-btn.active {
            color: #4a6fc7;
            border-bottom: 3px solid #4a6fc7;
            background: white;
        }
        
        .tab-content {
            flex: 1;
            padding: 30px;
            display: none;
            flex-direction: column;
        }
        
        .tab-content.active {
            display: flex;
        }
        
        .content-row {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            flex: 1;
        }
        
        .card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.05);
            padding: 25px;
            flex: 1;
            min-width: 300px;
            display: flex;
            flex-direction: column;
        }
        
        .card h2 {
            color: #2c3e50;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
            display: flex;
            align-items: center;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #555;
        }
        
        input[type="text"],
        input[type="password"],
        input[type="file"] {
            width: 100%;
            padding: 14px;
            border: 2px solid #e1e5ee;
            border-radius: 8px;
            font-size: 16px;
        }
        
        input:focus {
            border-color: #4a6fc7;
            outline: none;
        }
        
        button {
            background: linear-gradient(90deg, #4a6fc7, #2c3e50);
            color: white;
            border: none;
            padding: 14px 25px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            width: 100%;
            transition: all 0.3s;
            letter-spacing: 0.5px;
        }
        
        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .logout-btn {
            background: linear-gradient(90deg, #e74c3c, #c0392b);
            margin-top: 10px;
        }
        
        .message {
            padding: 15px;
            margin: 20px 0;
            border-radius: 8px;
            text-align: center;
            font-weight: 500;
            font-size: 16px;
        }
        
        .success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        
        .warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeeba;
        }
        
        .file-list, .log-list {
            list-style: none;
            max-height: 500px;
            overflow-y: auto;
            flex: 1;
        }
        
        .file-item, .log-item {
            display: flex;
            align-items: center;
            padding: 15px;
            border-bottom: 1px solid #eee;
            transition: background 0.2s;
        }
        
        .file-icon, .log-icon {
            font-size: 28px;
            margin-right: 15px;
            min-width: 40px;
            text-align: center;
        }
        
        .file-details, .log-details {
            flex: 1;
            min-width: 0;
        }
        
        .file-name, .log-name {
            font-weight: 600;
            margin-bottom: 5px;
            word-break: break-all;
        }
        
        .file-meta, .log-meta {
            display: flex;
            font-size: 13px;
            color: #777;
            flex-wrap: wrap;
        }
        
        .file-actions {
            display: flex;
            gap: 10px;
            margin-top: 10px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 8px 15px;
            border-radius: 5px;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            white-space: nowrap;
        }
        
        .btn i {
            margin-right: 5px;
        }
        
        .preview-btn {
            background: #3498db;
            color: white;
        }
        
        .download-btn {
            background: #2ecc71;
            color: white;
        }
        
        .rename-btn {
            background: #9b59b6;
            color: white;
        }
        
        .view-log-btn {
            background: #f39c12;
            color: white;
        }
        
        .log-content {
            background: #1e1e1e;
            color: #d4d4d4;
            border-radius: 5px;
            padding: 15px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            line-height: 1.5;
            max-height: 500px;
            overflow-y: auto;
            flex: 1;
            white-space: pre-wrap;
        }
        
        .log-line {
            margin-bottom: 5px;
            padding: 3px 8px;
            border-radius: 3px;
        }
        
        .log-line.error {
            background: rgba(255, 0, 0, 0.1);
            color: #f48771;
        }
        
        .log-line.warning {
            background: rgba(255, 165, 0, 0.1);
            color: #ffcc00;
        }
        
        .log-line.success {
            background: rgba(0, 128, 0, 0.1);
            color: #4ec9b0;
        }
        
        .log-line.info {
            background: rgba(30, 144, 255, 0.1);
            color: #9cdcfe;
        }
        
        .footer {
            text-align: center;
            padding: 25px;
            color: #777;
            border-top: 1px solid #eee;
            background: #f9f9f9;
        }
        
        .progress-container {
            width: 100%;
            background: #e0e0e0;
            border-radius: 5px;
            margin: 15px 0;
            display: none;
        }
        
        .progress-bar {
            height: 20px;
            background: linear-gradient(90deg, #4a6fc7, #2c3e50);
            border-radius: 5px;
            width: 0%;
            transition: width 0.3s;
        }
        
        .progress-text {
            text-align: center;
            font-size: 14px;
            color: #555;
            margin-top: 5px;
        }
        
        .preview-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.9);
            z-index: 1000;
            display: flex;
            justify-content: center;
            align-items: center;
            opacity: 0;
            visibility: hidden;
        }
        
        .preview-modal.active {
            opacity: 1;
            visibility: visible;
        }
        
        .preview-content {
            max-width: 90%;
            max-height: 90%;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            position: relative;
            display: flex;
            flex-direction: column;
        }
        
        .preview-header {
            background: #2c3e50;
            color: white;
            padding: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .preview-title {
            font-size: 18px;
            font-weight: 600;
        }
        
        .close-preview {
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
        }
        
        .preview-body {
            padding: 20px;
            max-height: calc(100vh - 100px);
            overflow: auto;
            flex: 1;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        
        .preview-actions {
            display: flex;
            justify-content: center;
            padding: 15px;
            background: #f5f5f5;
            gap: 10px;
        }
        
        .rename-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            display: flex;
            justify-content: center;
            align-items: center;
            opacity: 0;
            visibility: hidden;
        }
        
        .rename-modal.active {
            opacity: 1;
            visibility: visible;
        }
        
        .rename-content {
            background: white;
            border-radius: 10px;
            width: 90%;
            max-width: 500px;
        }
        
        .rename-header {
            padding: 15px 20px;
            background: #4a6fc7;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-radius: 10px 10px 0 0;
        }
        
        .rename-header h3 {
            margin: 0;
        }
        
        .close-rename {
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
        }
        
        .rename-form {
            padding: 20px;
        }
        
        .rename-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        .rename-actions .btn {
            flex: 1;
            padding: 12px;
        }
        
        @media (max-width: 900px) {
            .content-row {
                flex-direction: column;
            }
            
            .preview-content iframe {
                width: 95vw;
                height: 70vh;
            }
            
            .user-badge {
                position: static;
                margin: 10px auto;
                width: fit-content;
            }
        }
        
        @media (max-width: 600px) {
            .card {
                min-width: 100%;
            }
            
            .nav-tabs {
                flex-wrap: wrap;
            }
            
            .tab-btn {
                padding: 10px 15px;
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>多用户文件上传系统</h1>
            <p>支持多用户、大文件上传、预览及下载功能</p>
            
            <?php if ($isLoggedIn): ?>
                <div class="user-badge">
                    <i class="fas fa-user"></i> 当前用户: <?= htmlspecialchars($currentUser, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>
        </div>
        
        <?php if ($isLoggedIn): ?>
            <div class="nav-tabs">
                <button class="tab-btn active" data-tab="files">文件管理</button>
                <button class="tab-btn" data-tab="logs">系统日志</button>
                <button class="tab-btn" data-tab="users">用户信息</button>
            </div>
        <?php endif; ?>
        
        <div class="tab-content active" id="files-tab">
            <div class="content-row">
                <div class="card">
                    <?php if ($message): ?>
                        <div class="message <?= getMessageClass($message) ?>">
                            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!$isLoggedIn): ?>
                        <!-- 登录表单 -->
                        <h2><i class="fas fa-lock"></i> 用户登录</h2>
                        <form action="" method="post">
                            <div class="form-group">
                                <label for="username">用户名</label>
                                <input type="text" name="username" id="username" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="password">密码</label>
                                <input type="password" name="password" id="password" required>
                            </div>
                            
                            <button type="submit" name="login">登录</button>
                        </form>
                        
                        <div class="message info">
                            <strong>账户信息存储在：</strong><?= htmlspecialchars($userAccountsFile, ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    <?php else: ?>
                        <!-- 文件上传表单 -->
                        <h2><i class="fas fa-cloud-upload-alt"></i> 上传文件</h2>
                        <form action="" method="post" enctype="multipart/form-data" id="uploadForm">
                            <div class="form-group">
                                <label for="file">选择文件 (支持所有类型，最大10GB):</label>
                                <input type="file" name="file" id="file" required>
                            </div>
                            <div class="progress-container" id="progressContainer">
                                <div class="progress-bar" id="progressBar"></div>
                                <div class="progress-text" id="progressText">0%</div>
                            </div>
                            <button type="submit">上传文件</button>
                        </form>
                        
                        <form action="" method="get">
                            <button type="submit" name="logout" class="logout-btn">退出登录</button>
                        </form>
                    <?php endif; ?>
                </div>
                
                <?php if ($isLoggedIn): ?>
                    <!-- 文件列表 -->
                    <div class="card">
                        <h2><i class="fas fa-folder-open"></i> 已上传文件</h2>
                        <?php if (count($fileList) > 0): ?>
                            <ul class="file-list">
                                <?php foreach ($fileList as $file): ?>
                                    <li class="file-item">
                                        <div class="file-icon"><?= $file['icon'] ?></div>
                                        <div class="file-details">
                                            <div class="file-name"><?= htmlspecialchars($file['name'], ENT_QUOTES, 'UTF-8') ?></div>
                                            <div class="file-meta">
                                                <span class="file-size"><?= formatSize($file['size']) ?></span>
                                                <span class="file-modified"><?= formatTime($file['modified']) ?></span>
                                            </div>
                                            <div class="file-actions">
                                                <?php if ($file['previewable']): ?>
                                                    <button class="btn preview-btn" data-filename="<?= htmlspecialchars($file['name'], ENT_QUOTES, 'UTF-8') ?>">
                                                        <i class="fas fa-eye"></i> 预览
                                                    </button>
                                                <?php endif; ?>
                                                <a href="?download=<?= urlencode($file['name']) ?>" class="btn download-btn">
                                                    <i class="fas fa-download"></i> 下载
                                                </a>
                                                <button class="btn rename-btn" data-filename="<?= htmlspecialchars($file['name'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <i class="fas fa-edit"></i> 重命名
                                                </button>
                                            </div>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <div class="message info">
                                尚未上传任何文件
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- 系统信息 -->
                    <div class="card">
                        <h2><i class="fas fa-info-circle"></i> 系统信息</h2>
                        <p><strong>存储位置：</strong> <?= htmlspecialchars(realpath($config['upload_dir']), ENT_QUOTES, 'UTF-8') ?></p>
                        <p><strong>日志位置：</strong> <?= htmlspecialchars(realpath($config['log_dir']), ENT_QUOTES, 'UTF-8') ?></p>
                        <p><strong>用户账户：</strong> <?= htmlspecialchars(realpath($userAccountsFile), ENT_QUOTES, 'UTF-8') ?></p>
                        <p><strong>已使用空间：</strong> 
                            <?php 
                                $totalSize = 0;
                                foreach ($fileList as $file) {
                                    if ($file['size'] !== false) {
                                        $totalSize += $file['size'];
                                    }
                                }
                                echo formatSize($totalSize);
                            ?>
                        </p>
                        <p><strong>文件数量：</strong> <?= count($fileList) ?></p>
                        <p><strong>最大文件大小：</strong> 10 GB</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if ($isLoggedIn): ?>
            <div class="tab-content" id="logs-tab">
                <div class="content-row">
                    <!-- 日志文件列表 -->
                    <div class="card">
                        <h2><i class="fas fa-file-alt"></i> 日志文件</h2>
                        <?php if (count($logFiles) > 0): ?>
                            <ul class="log-list">
                                <?php foreach ($logFiles as $log): ?>
                                    <li class="log-item">
                                        <div class="log-icon">📋</div>
                                        <div class="log-details">
                                            <div class="log-name"><?= htmlspecialchars($log['name'], ENT_QUOTES, 'UTF-8') ?></div>
                                            <div class="log-meta">
                                                <span class="log-size"><?= formatSize($log['size']) ?></span>
                                                <span class="log-modified"><?= formatTime($log['modified']) ?></span>
                                                <span class="log-lines"><?= $log['lines'] ?> 行</span>
                                            </div>
                                        </div>
                                        <a href="?log=<?= urlencode($log['name']) ?>" class="btn view-log-btn">
                                            <i class="fas fa-eye"></i> 查看
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <div class="message info">
                                暂无日志文件
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- 日志内容 -->
                    <div class="card">
                        <h2><i class="fas fa-align-left"></i> 日志内容</h2>
                        <?php if (!empty($selectedLog) && !empty($logContent)): ?>
                            <div class="log-content">
                                <?php foreach ($logContent as $line): 
                                    $line = trim($line);
                                    $lineClass = 'info';
                                    
                                    if (strpos($line, '[ERROR]') !== false) $lineClass = 'error';
                                    elseif (strpos($line, '[WARNING]') !== false) $lineClass = 'warning';
                                    elseif (strpos($line, '[SUCCESS]') !== false) $lineClass = 'success';
                                ?>
                                    <div class="log-line <?= $lineClass ?>"><?= htmlspecialchars($line, ENT_QUOTES, 'UTF-8') ?></div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="message info">
                                <?= empty($selectedLog) ? '请从左侧选择日志文件查看内容' : '该日志文件为空' ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- 日志信息 -->
                    <div class="card">
                        <h2><i class="fas fa-clipboard-list"></i> 日志说明</h2>
                        <p>系统日志记录所有关键操作，包括：</p>
                        <ul style="margin: 15px 0 15px 25px;">
                            <li>用户登录/登出</li>
                            <li>文件上传操作</li>
                            <li>文件重命名操作</li>
                            <li>文件下载操作</li>
                            <li>用户账户变更</li>
                            <li>系统错误信息</li>
                        </ul>
                        
                        <div class="message info">
                            <p><strong>日志格式：</strong></p>
                            <p>[时间] [级别] [IP地址] 事件描述 - 附加信息</p>
                        </div>
                        
                        <div class="message">
                            <p><strong>日志级别说明：</strong></p>
                            <p><span class="log-line success" style="display: inline-block; padding: 3px 8px;">SUCCESS</span> 操作成功</p>
                            <p><span class="log-line info" style="display: inline-block; padding: 3px 8px;">INFO</span> 常规信息</p>
                            <p><span class="log-line warning" style="display: inline-block; padding: 3px 8px;">WARNING</span> 警告信息</p>
                            <p><span class="log-line error" style="display: inline-block; padding: 3px 8px;">ERROR</span> 错误信息</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="tab-content" id="users-tab">
                <div class="content-row">
                    <!-- 当前用户信息 -->
                    <div class="card">
                        <h2><i class="fas fa-user-circle"></i> 当前用户信息</h2>
                        <div class="user-info">
                            <p><strong>用户名：</strong> <?= htmlspecialchars($currentUser, ENT_QUOTES, 'UTF-8') ?></p>
                            <p><strong>登录状态：</strong> <span style="color:#27ae60;">已登录</span></p>
                            <p><strong>角色：</strong> <?= $currentUser === 'admin' ? '管理员' : '普通用户' ?></p>
                            <p><strong>上传文件数：</strong> <?= count($fileList) ?></p>
                            <p><strong>使用空间：</strong> 
                                <?php 
                                    $userSize = 0;
                                    foreach ($fileList as $file) {
                                        if ($file['size'] !== false) {
                                            $userSize += $file['size'];
                                        }
                                    }
                                    echo formatSize($userSize);
                                ?>
                            </p>
                        </div>
                    </div>
                    
                    <!-- 用户管理 -->
                    <div class="card">
                        <h2><i class="fas fa-users"></i> 系统用户</h2>
                        <table style="width:100%; border-collapse: collapse; margin-top:15px;">
                            <thead>
                                <tr style="background:#f5f7fa;">
                                    <th style="padding:12px; text-align:left;">用户名</th>
                                    <th style="padding:12px; text-align:left;">角色</th>
                                    <th style="padding:12px; text-align:left;">状态</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($config['users'] as $username => $passwordHash): ?>
                                    <tr style="border-bottom:1px solid #eee;">
                                        <td style="padding:12px;"><?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?></td>
                                        <td style="padding:12px;"><?= $username === 'admin' ? '管理员' : '普通用户' ?></td>
                                        <td style="padding:12px;"><span style="color:#27ae60;">活跃</span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        
                        <div class="message info" style="margin-top:20px;">
                            <p><strong>用户账户文件位置：</strong></p>
                            <p><?= htmlspecialchars(realpath($userAccountsFile), ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                    </div>
                    
                    <!-- 系统统计 -->
                    <div class="card">
                        <h2><i class="fas fa-chart-bar"></i> 系统统计</h2>
                        <div style="display:flex; gap:20px; margin-top:20px;">
                            <div style="flex:1; text-align:center; background:#f5f7fa; padding:20px; border-radius:8px;">
                                <div style="font-size:24px; font-weight:bold; color:#4a6fc7;">
                                    <?= count($fileList) ?>
                                </div>
                                <div>总文件数</div>
                            </div>
                            <div style="flex:1; text-align:center; background:#f5f7fa; padding:20px; border-radius:8px;">
                                <div style="font-size:24px; font-weight:bold; color:#4a6fc7;">
                                    <?= count($logFiles) ?>
                                </div>
                                <div>日志文件</div>
                            </div>
                            <div style="flex:1; text-align:center; background:#f5f7fa; padding:20px; border-radius:8px;">
                                <div style="font-size:24px; font-weight:bold; color:#4a6fc7;">
                                    <?= count($config['users']) ?>
                                </div>
                                <div>系统用户</div>
                            </div>
                        </div>
                        
                        <div style="margin-top:20px;">
                            <h3><i class="fas fa-hdd"></i> 存储空间使用情况</h3>
                            <div style="height:20px; background:#e0e0e0; border-radius:10px; margin-top:10px; overflow:hidden;">
                                <?php
                                    // 计算磁盘使用率
                                    $totalSpace = disk_total_space(__DIR__);
                                    $freeSpace = disk_free_space(__DIR__);
                                    $usedSpace = $totalSpace - $freeSpace;
                                    $usedPercentage = round(($usedSpace / $totalSpace) * 100);
                                ?>
                                <div style="height:100%; background:linear-gradient(90deg, #4a6fc7, #2c3e50); width:<?= $usedPercentage ?>%;"></div>
                            </div>
                            <div style="display:flex; justify-content:space-between; margin-top:5px;">
                                <span><?= $usedPercentage ?>% 已使用</span>
                                <span><?= 100 - $usedPercentage ?>% 可用</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
        <div class="footer">
            多用户文件上传系统 &copy; <?= date('Y') ?> | 安全存储与管理解决方案
        </div>
    </div>

    <!-- 文件预览模态框 -->
    <div class="preview-modal" id="previewModal">
        <div class="preview-content">
            <div class="preview-header">
                <div class="preview-title" id="previewTitle">文件预览</div>
                <button class="close-preview" id="closePreview">&times;</button>
            </div>
            <div class="preview-body" id="previewBody">
                <!-- 预览内容将动态加载到这里 -->
            </div>
            <div class="preview-actions">
                <a href="#" class="btn download-btn" id="modalDownloadBtn">
                    <i class="fas fa-download"></i> 下载文件
                </a>
                <button class="btn close-preview-btn" id="modalCloseBtn">
                    <i class="fas fa-times"></i> 关闭预览
                </button>
            </div>
        </div>
    </div>

    <!-- 重命名模态框 -->
    <div class="rename-modal" id="renameModal">
        <div class="rename-content">
            <div class="rename-header">
                <h3>重命名文件</h3>
                <button class="close-rename">&times;</button>
            </div>
            <form action="" method="post" class="rename-form">
                <input type="hidden" name="old_name" id="oldNameInput">
                <div class="form-group">
                    <label for="newNameInput">新文件名:</label>
                    <input type="text" name="new_name" id="newNameInput" required>
                </div>
                <div class="rename-actions">
                    <button type="submit" name="rename" class="btn rename-confirm-btn">
                        <i class="fas fa-check"></i> 确认
                    </button>
                    <button type="button" class="btn rename-cancel-btn">
                        <i class="fas fa-times"></i> 取消
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/viewerjs/1.11.1/viewer.min.js"></script>
    <script>
        // 标签切换功能
        document.querySelectorAll('.tab-btn').forEach(button => {
            button.addEventListener('click', () => {
                document.querySelectorAll('.tab-btn').forEach(btn => {
                    btn.classList.remove('active');
                });
                button.classList.add('active');
                
                document.querySelectorAll('.tab-content').forEach(content => {
                    content.classList.remove('active');
                });
                
                const tabId = button.getAttribute('data-tab');
                document.getElementById(`${tabId}-tab`).classList.add('active');
            });
        });
        
        // 文件上传进度条功能
        const uploadForm = document.getElementById('uploadForm');
        if (uploadForm) {
            uploadForm.addEventListener('submit', function(e) {
                const fileInput = document.getElementById('file');
                const progressBar = document.getElementById('progressBar');
                const progressText = document.getElementById('progressText');
                const progressContainer = document.getElementById('progressContainer');
                
                if (fileInput.files.length > 0) {
                    progressContainer.style.display = 'block';
                    
                    const xhr = new XMLHttpRequest();
                    xhr.open('POST', '', true);
                    
                    xhr.upload.addEventListener('progress', function(e) {
                        if (e.lengthComputable) {
                            const percent = Math.round((e.loaded / e.total) * 100);
                            progressBar.style.width = percent + '%';
                            progressText.textContent = percent + '%';
                            
                            if (percent === 100) {
                                progressText.textContent = '处理中...';
                            }
                        }
                    });
                    
                    xhr.onload = function() {
                        if (xhr.status === 200) {
                            location.reload();
                        } else {
                            alert('上传失败: ' + xhr.statusText);
                            progressContainer.style.display = 'none';
                        }
                    };
                    
                    xhr.onerror = function() {
                        alert('网络错误，上传失败');
                        progressContainer.style.display = 'none';
                    };
                    
                    const formData = new FormData(uploadForm);
                    xhr.send(formData);
                    
                    e.preventDefault();
                }
            });
        }
        
        // 文件预览功能
        const previewModal = document.getElementById('previewModal');
        const previewBody = document.getElementById('previewBody');
        const previewTitle = document.getElementById('previewTitle');
        const closePreview = document.getElementById('closePreview');
        const modalCloseBtn = document.getElementById('modalCloseBtn');
        const modalDownloadBtn = document.getElementById('modalDownloadBtn');
        
        document.querySelectorAll('.preview-btn').forEach(button => {
            button.addEventListener('click', function() {
                const filename = this.getAttribute('data-filename');
                previewTitle.textContent = `预览文件: ${filename}`;
                modalDownloadBtn.href = `?download=${encodeURIComponent(filename)}`;
                
                // 显示加载状态
                previewBody.innerHTML = '<div style="text-align:center;padding:50px;"><i class="fas fa-spinner fa-spin fa-3x"></i><p>加载文件中...</p></div>';
                previewModal.classList.add('active');
                
                // 获取文件扩展名
                const extension = filename.split('.').pop().toLowerCase();
                
                // 根据扩展名确定预览方式
                let previewContent = '';
                
                if (['jpg', 'jpeg', 'png', 'gif'].includes(extension)) {
                    previewContent = `
                        <img src="?download=${encodeURIComponent(filename)}" alt="${filename}" style="max-width:100%; max-height:80vh;">
                        <div class="message info" style="margin-top:15px;">
                            <i class="fas fa-info-circle"></i> 使用鼠标滚轮可缩放图片
                        </div>
                    `;
                } else if (extension === 'pdf') {
                    previewContent = `
                        <iframe src="?download=${encodeURIComponent(filename)}" width="100%" height="600px" style="border:none;"></iframe>
                        <div class="message info" style="margin-top:15px;">
                            <i class="fas fa-info-circle"></i> PDF文件需要浏览器支持PDF预览功能
                        </div>
                    `;
                } else if (extension === 'txt') {
                    fetch(`?download=${encodeURIComponent(filename)}`)
                        .then(response => response.text())
                        .then(text => {
                            previewContent = `<pre style="background:#f5f5f5;padding:20px;border-radius:5px;max-height:70vh;overflow:auto;">${text}</pre>`;
                            previewBody.innerHTML = previewContent;
                        })
                        .catch(error => {
                            previewBody.innerHTML = `<div class="message error">加载文本内容时出错: ${error.message}</div>`;
                        });
                    
                    return;
                } else {
                    previewContent = `
                        <div class="message info">
                            <p>不支持此文件类型的预览</p>
                            <p>请下载文件后在本地查看</p>
                        </div>
                    `;
                }
                
                previewBody.innerHTML = previewContent;
                
                // 如果是图片，初始化图片查看器
                if (['jpg', 'jpeg', 'png', 'gif'].includes(extension)) {
                    const img = previewBody.querySelector('img');
                    if (img) {
                        new Viewer(img, {
                            navbar: false,
                            title: false,
                            toolbar: {
                                zoomIn: 1,
                                zoomOut: 1,
                                oneToOne: 1,
                                reset: 1,
                                rotateLeft: 1,
                                rotateRight: 1,
                                flipHorizontal: 1,
                                flipVertical: 1,
                            }
                        });
                    }
                }
            });
        });
        
        // 关闭预览
        closePreview.addEventListener('click', () => {
            previewModal.classList.remove('active');
        });
        
        modalCloseBtn.addEventListener('click', () => {
            previewModal.classList.remove('active');
        });
        
        previewModal.addEventListener('click', (e) => {
            if (e.target === previewModal) {
                previewModal.classList.remove('active');
            }
        });
        
        // 重命名功能
        const renameModal = document.getElementById('renameModal');
        const oldNameInput = document.getElementById('oldNameInput');
        const newNameInput = document.getElementById('newNameInput');
        const renameCancelBtn = document.querySelector('.rename-cancel-btn');
        
        document.querySelectorAll('.rename-btn').forEach(button => {
            button.addEventListener('click', function() {
                const filename = this.getAttribute('data-filename');
                oldNameInput.value = filename;
                
                // 智能处理文件名：移除唯一ID前缀
                if (filename.includes('_')) {
                    const parts = filename.split('_');
                    if (parts.length > 1) {
                        const namePart = parts.slice(1).join('_');
                        newNameInput.value = namePart;
                    } else {
                        newNameInput.value = filename;
                    }
                } else {
                    newNameInput.value = filename;
                }
                
                renameModal.classList.add('active');
                newNameInput.focus();
            });
        });
        
        // 关闭重命名模态框
        document.querySelector('.close-rename').addEventListener('click', () => {
            renameModal.classList.remove('active');
        });
        
        renameCancelBtn.addEventListener('click', () => {
            renameModal.classList.remove('active');
        });
        
        renameModal.addEventListener('click', (e) => {
            if (e.target === renameModal) {
                renameModal.classList.remove('active');
            }
        });
        
        // 重命名表单提交确认
        document.querySelector('.rename-form').addEventListener('submit', function(e) {
            const newName = newNameInput.value.trim();
            if (!newName) {
                e.preventDefault();
                alert('请输入有效的文件名');
                return false;
            }
            
            if (!confirm(`确定要将文件重命名为 "${newName}" 吗？`)) {
                e.preventDefault();
                return false;
            }
        });
        
        // 页面加载时，检查URL参数，切换到日志标签页
        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('log')) {
                const logTabBtn = document.querySelector('.tab-btn[data-tab="logs"]');
                if (logTabBtn) {
                    logTabBtn.click();
                }
            }
        });
    </script>
</body>
</html>