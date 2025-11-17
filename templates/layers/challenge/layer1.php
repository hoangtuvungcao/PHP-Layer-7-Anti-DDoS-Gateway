<?php
$page = $baseConfig;
$page['badge'] = 'Layer 1 · Traffic Filter';
$page['title'] = 'Request thiếu thông tin bắt buộc';
$page['lead'] = "Trình duyệt/Client chưa gửi đủ header cần thiết (Accept, Accept-Language, Sec-Fetch...). Kiểm tra cấu hình rồi thử lại.";
$page['actions'][] = ['label' => 'Xem hướng dẫn', 'variant' => 'outline', 'onclick' => "window.location.href='/#guide'"];
return $page;
