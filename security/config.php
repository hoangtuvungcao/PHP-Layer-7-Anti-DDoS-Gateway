<?php

return [
    // Layer 0 – IP reputation. Sử dụng blocklist file + whitelist để giảm tải cho các layer phía sau.
    'ip_reputation' => [
        // File lưu IP bị block ("ip|timestamp"), web server phải có quyền ghi
        'blocklist_file' => __DIR__ . '/data/ip_blocklist.txt',
        // Danh sách IP luôn được cho qua (thường là IP nội bộ hoặc upstream proxy)
        'allow_ips' => [
            '127.0.0.1',
            '::1',
        ],
        // Các CIDR luôn allow (ví dụ dải IP của đội ngũ vận hành)
        'allow_cidrs' => [],
        // CIDR bị chặn cứng ngay từ Layer 0
        'block_cidrs' => [
            '10.0.0.0/8',
            '192.0.2.0/24',
        ],
        // Liệt kê IP block ngay cả khi không nằm trong file hoặc CIDR
        'block_ips' => [],
    ],
    // Layer 1 – Traffic filter. Điều chỉnh theo headers của browser hợp lệ trong traffic thực tế.
    'traffic_filter' => [
        // Phương thức hợp lệ; request khác (PUT/DELETE...) sẽ bị challenge/block
        'allowed_methods' => ['GET', 'POST', 'HEAD'],
        // Các header bắt buộc phải có (key dạng lowercase)
        'required_headers' => ['accept', 'accept-language', 'user-agent'],
        // User-Agent ngắn hơn giá trị này bị xem là bot/script
        'min_user_agent_length' => 12,
        // Chứa chuỗi này trong UA sẽ bị đánh dấu nghi vấn
        'suspicious_user_agents' => [
            'curl',
            'wget',
            'python-requests',
            'libwww',
        ],
        // Accept header phải chứa ít nhất một token trong danh sách
        'required_accept_tokens' => ['text/html', 'application/json'],
        // true = bắt buộc các header Sec-Fetch-* (bảo vệ khỏi bot HTTP thô)
        'require_sec_fetch_headers' => true,
    ],
    // Layer 2 – Rate limiting. Đặt ngưỡng = ~150% mức người dùng thật để tránh false positive.
    'rate_limit' => [
        // Chiều dài cửa sổ đo lưu lượng (giây)
        'window_seconds' => 1,
        // Số request tối đa trong một cửa sổ trước khi bị phạt
        'max_requests' => 3,
        // Thời gian giảm penalty sau mỗi lần "nghỉ" (giây)
        'cooldown_seconds' => 5,
        // Penalty tối đa tích lũy được
        'max_penalty' => 4,
        // Penalty >= giá trị này → block tạm thởi
        'block_penalty_threshold' => 1,
        // Penalty >= giá trị này → chuyển sang challenge
        'challenge_penalty_threshold' => 1,
    ],
    // Layer 3 – Browser challenge. Secret phải xoay định kỳ (30 ngày) để đảm bảo an toàn.
    'challenge' => [
        // Chuỗi bí mật ký HMAC cho token challenge (thay bằng random 64 hex, xoay 30 ngày/lần)
        'secret' => getenv('SECURITY_CHALLENGE_SECRET') ?: 'e1ecc407b4936213a5426d6f38b2c65be4f8078ea04be5783a01e24bd1503981',
        // Bundle challenge hết hiệu lực sau bao nhiêu giây
        'bundle_ttl' => 120,
        // Token đã verify có hiệu lực bao lâu (giây)
        'token_ttl' => 300,
        // Thuộc tính cookie chứa token challenge/bypass
        'cookie_name' => '__sec_challenge',
        // Đường dẫn cookie / : toàn cục
        'cookie_path' => '/',
        // true = chỉ gửi cookie khi truy cập HTTPS
        'cookie_secure' => false,
        // true = JS không đọc được cookie (tùy luồng xác minh)
        'cookie_httponly' => false,
        // SameSite chính sách cookie (Lax = phù hợp đa số tình huống)
        'cookie_samesite' => 'Lax',
        // Sau khi vượt challenge được bypass bao lâu
        'bypass_ttl' => 300,
        // Số lần thử challenge tối đa trước khi bị khóa tạm thời
        'max_retries' => 20,
    ],
    // Layer 4 – Behaviour scoring. Điều chỉnh thresholds dựa vào log (notes behaviour:*).
    'behaviour' => [
        // Điểm khởi đầu cho phiên mới
        'initial_score' => 25,
        // Điểm thấp nhất / cao nhất có thể đạt
        'min_score' => -100,
        'max_score' => 120,
        // Điểm <= giá trị này → block
        'block_threshold' => 0,
        // Điểm <= giá trị này → challenge
        'challenge_threshold' => 40,
        // Điểm >= giá trị này → bypass challenge
        'bypass_threshold' => 75,
        // Bao lâu thì giảm điểm phạt một lần (giây)
        'decay_interval' => 90,
        // Mỗi lần decay giảm/ tăng bao nhiêu điểm
        'decay_step' => 5,
        // Định nghĩa "request quá nhanh" (ms giữa 2 request)
        'rapid_threshold_ms' => 400,
        // Penalty thêm khi request quá nhanh
        'rapid_penalty' => 15,
        // Bonus khi request có đầy đủ header "đẹp"
        'good_headers_bonus' => 10,
        // Phạt khi thiếu header bắt buộc
        'missing_header_penalty' => 15,
        // Phạt khi client không hỗ trợ JS (thường là bot)
        'no_js_penalty' => 25,
    ],
    // Layer 5 – Session hardening. Khóa fingerprint/UA/IP, giới hạn tab & refresh.
    'session_hardening' => [
        // Cookie định danh phiên đã khóa fingerprint/UA/IP
        'lock_cookie_name' => '__sec_session_lock',
        // Cookie lưu tab-id (kết hợp với tab-guard.js)
        'tab_cookie_name' => '__sec_tab',
        // TTL của cookie lock (giây)
        'lock_cookie_ttl' => 600,
        'lock_cookie_path' => '/',
        // true = chỉ gửi cookie lock qua HTTPS
        'lock_cookie_secure' => false,
        // true = JS không truy cập được cookie lock
        'lock_cookie_httponly' => false,
        'lock_cookie_samesite' => 'Lax',
        // Bắt buộc có fingerprint mới cho qua Layer 5
        'fingerprint_required' => true,
        // true = khóa user-agent, false = bỏ qua
        'ua_lock' => true,
        // true = khóa địa chỉ IP ban đầu
        'ip_lock' => true,
        // Số tab hoạt động tối đa (>= tab này sẽ bị challenge)
        'max_tabs' => 5,
        // Thời gian giữ tab trong danh sách trước khi auto xóa (giây)
        'tab_inactivity_ttl' => 180,
        // Số lần refresh tối đa trong một cửa sổ
        'max_refresh_per_window' => 30,
        // Độ dài cửa sổ refresh (giây)
        'refresh_window_seconds' => 10,
        // Refresh nhanh hơn ngưỡng này bị xem là spam (giây)
        'rapid_refresh_threshold_seconds' => 0.5,
        // Điểm phạt khi refresh quá nhanh
        'rapid_refresh_penalty' => 5,
        // true = block khi vượt ngưỡng refresh, false = chỉ challenge
        'block_on_refresh_overflow' => true,
    ],
    // Logging – log JSON lines phục vụ tuning. Tham số max_size_bytes & max_files kiểm soát dung lượng.
    'logging' => [
        // Bật/tắt ghi log JSON
        'enabled' => true,
        // Đường dẫn file log chính
        'file' => __DIR__ . '/logs/security.log',
        // Dung lượng tối đa của mỗi file log trước khi rotate
        'max_size_bytes' => 1048576,
        // Số file log giữ lại (log, log.1, log.2...).
        'max_files' => 5,
    ],
    // Maintenance – tự động dọn log/blocklist khi có traffic, tránh tốn tài nguyên.
    'maintenance' => [
        // true = chạy cleanup tự động dựa trên traffic
        'auto_cleanup' => true,
        // Khoảng cách tối thiểu giữa hai lần cleanup (giây)
        'cleanup_interval' => 300, // giây; dọn tối đa 5 phút/lần
        // TTL của IP trong blocklist (giây) – chỉnh nhỏ hơn để dễ thử nghiệm
        'blocklist_ttl' => 86400,   // giữ IP block trong 24h
    ],
];
