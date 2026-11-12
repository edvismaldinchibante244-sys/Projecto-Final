<?php
require_once __DIR__ . '/storage_path.php';

if (!function_exists('security_ip_blocklist_path')) {
    function security_ip_blocklist_path(): string
    {
        $baseDir = dirname(__DIR__, 2);
        $dir = nv_storage('security');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . DIRECTORY_SEPARATOR . 'ip_blocklist.json';
    }
}

if (!function_exists('security_ip_blocklist_read')) {
    function security_ip_blocklist_read(): array
    {
        $path = security_ip_blocklist_path();
        if (!is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($data) ? $data : [];
    }
}

if (!function_exists('security_ip_blocklist_write')) {
    function security_ip_blocklist_write(array $rows): void
    {
        $path = security_ip_blocklist_path();
        @file_put_contents($path, json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    }
}

if (!function_exists('security_ip_is_blocked')) {
    function security_ip_is_blocked(string $ip): bool
    {
        $ip = trim($ip);
        if ($ip === '') {
            return false;
        }
        $rows = security_ip_blocklist_read();
        $now = time();
        $dirty = false;
        $blocked = false;

        foreach ($rows as $key => $row) {
            $rowIp = (string)($row['ip'] ?? '');
            $until = (int)($row['blocked_until'] ?? 0);
            $permanent = (int)($row['permanent'] ?? 0) === 1;
            if ($until > 0 && $until < $now) {
                unset($rows[$key]);
                $dirty = true;
                continue;
            }
            if ($rowIp !== $ip) {
                continue;
            }
            if ($permanent || $until === 0 || $until >= $now) {
                $blocked = true;
            }
        }

        if ($dirty) {
            security_ip_blocklist_write(array_values($rows));
        }

        return $blocked;
    }
}
