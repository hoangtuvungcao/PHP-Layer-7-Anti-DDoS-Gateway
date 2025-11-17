<?php
/** @var array $metadata */
/** @var array $config */
/** @var int $now */

$page = $baseConfig;
$page['badge'] = 'Layer 2 · Rate Limiter';
$page['http_code'] = 429;
$page['title'] = 'Bạn đang truy cập quá nhanh';
$page['lead'] = 'Để bảo vệ dịch vụ, hệ thống đã tạm khóa truy cập vì vượt giới hạn tốc độ.';

$rateLimit = $config['rate_limit'] ?? [];
$maxRequests = $rateLimit['max_requests'] ?? 0;
$windowSeconds = $rateLimit['window_seconds'] ?? 0;

$page['details'][] = [
    'label' => 'Giới hạn',
    'value' => sprintf('%d yêu cầu trong %d giây', $maxRequests, $windowSeconds),
    'description' => sprintf('Penalty hiện tại: %s', $metadata['penalty'] ?? '0'),
];

$blockUntil = $metadata['block_until'] ?? 0;
if ($blockUntil > $now) {
    $page['details'][] = [
        'label' => 'Tự mở khóa sau',
        'value' => formatDuration($blockUntil - $now),
        'description' => 'Bạn có thể thử lại sau thời gian này.',
    ];
}

$page['footnote'] = 'Giảm tốc độ gửi request và thử lại sau khi hết thời gian chờ.';

return $page;
