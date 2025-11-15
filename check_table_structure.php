<?php
// 检查用户表结构
require_once __DIR__ . '/db.php';

$pdo = get_db_pdo();

try {
    // 检查用户表结构
    $stmt = $pdo->query("DESCRIBE users");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "用户表结构:\n";
    foreach ($columns as $column) {
        echo "字段: {$column['Field']}, 类型: {$column['Type']}, 默认值: {$column['Default']}\n";
    }
    
} catch (Exception $e) {
    echo "检查失败: " . $e->getMessage() . "\n";
}
?>