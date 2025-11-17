<?php
/** @var array $metadata */
/** @var array $config */
/** @var int $now */

$page = $baseConfig;
$page['badge'] = 'Layer 2 · Rate Limiter';
$page['http_code'] = 429;
$page['title'] = 'Tạm thời hạn chế tốc độ';
$page['lead'] = 'Bạn vừa vượt giới hạn tốc độ. Hệ thống yêu cầu đợi thêm trước khi tiếp tục.';

$rateLimit = $config['rate_limit'] ?? [];
$maxRequests = $rateLimit['max_requests'] ?? 0;
$windowSeconds = $rateLimit['window_seconds'] ?? 0;
$page['details'][] = [
    'label' => 'Giới hạn',
    'value' => sprintf('%d yêu cầu trong %d giây', $maxRequests, $windowSeconds),
    'description' => sprintf('Penalty hiện tại: %s', $metadata['penalty'] ?? '0'),
];

$cooldownUntil = $metadata['cooldown_until'] ?? 0;
if ($cooldownUntil > $now) {
    $page['details'][] = [
        'label' => 'Có thể thử lại sau',
        'value' => formatDuration($cooldownUntil - $now),
        'description' => 'Thử refresh sau thời gian trên.',
    ];
}

$page['footnote'] = 'Giảm tốc độ gửi request hoặc phân bổ lại lưu lượng, sau đó thử lại.';

return $page;
