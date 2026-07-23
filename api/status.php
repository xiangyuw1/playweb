<?php
/**
 * Minecraft 服务器状态查询 API
 * 使用 Server List Ping 协议 (1.7+)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// 服务器配置（优先读取环境变量）
$server = getenv('MC_SERVER_HOST') ?: '127.0.0.1';
$port = getenv('MC_SERVER_PORT') ?: 25565;
$timeout = 3;

/**
 * 查询 Minecraft 服务器状态
 */
function pingServer($host, $port = 25565, $timeout = 3) {
    $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
    
    if (!$socket) {
        return null;
    }
    
    stream_set_timeout($socket, $timeout);
    
    try {
        // 构建握手包
        $handshake = pack('c', 0x00); // Packet ID
        $handshake .= pack('c', 0x00); // Protocol Version (使用 0 表示查询)
        $handshake .= pack('c', strlen($host)) . $host; // Server Address
        $handshake .= pack('n', $port); // Server Port (unsigned short, big-endian)
        $handshake .= pack('c', 0x01); // Next State (1 = status)
        
        // 发送握手包
        $packet = pack('c', strlen($handshake)) . $handshake;
        fwrite($socket, $packet);
        
        // 发送状态请求包
        fwrite($socket, pack('c', 1) . pack('c', 0x00));
        
        // 读取响应
        $length = readVarInt($socket);
        if ($length < 1) {
            fclose($socket);
            return null;
        }
        
        $packetId = readVarInt($socket);
        $jsonLength = readVarInt($socket);
        
        $jsonData = '';
        while (strlen($jsonData) < $jsonLength) {
            $chunk = fread($socket, $jsonLength - strlen($jsonData));
            if ($chunk === false) break;
            $jsonData .= $chunk;
        }
        
        fclose($socket);
        
        return json_decode($jsonData, true);
        
    } catch (Exception $e) {
        fclose($socket);
        return null;
    }
}

/**
 * 读取 VarInt
 */
function readVarInt($socket) {
    $value = 0;
    $size = 0;
    
    while (true) {
        $byte = fread($socket, 1);
        if ($byte === false || strlen($byte) === 0) {
            return -1;
        }
        $byte = ord($byte);
        $value |= ($byte & 0x7F) << ($size++ * 7);
        
        if ($size > 5) {
            return -1;
        }
        
        if (($byte & 0x80) !== 0x80) {
            break;
        }
    }
    
    return $value;
}

// 执行查询
$result = pingServer($server, $port, $timeout);

if ($result !== null) {
    $response = [
        'online' => true,
        'players' => [
            'online' => $result['players']['online'] ?? 0,
            'max' => $result['players']['max'] ?? 0,
            'list' => $result['players']['sample'] ?? []
        ],
        'version' => $result['version']['name'] ?? 'Unknown',
        'description' => $result['description'] ?? '',
        'timestamp' => time()
    ];
} else {
    $response = [
        'online' => false,
        'players' => [
            'online' => 0,
            'max' => 0,
            'list' => []
        ],
        'timestamp' => time()
    ];
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
?>
