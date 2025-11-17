<?php
$blockData = $blockContext ?? [];
$ip = $blockData['ip'] ?? 'Không xác định';
$message = $blockData['message'] ?? 'Yêu cầu của bạn đã bị giới hạn';
$notes = $blockData['notes'] ?? [];
$metadata = $blockData['metadata'] ?? [];
$layer = $metadata['layer'] ?? null;
$penalty = $metadata['penalty'] ?? null;
$blockReason = $metadata['reason'] ?? null;
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Truy cập bị tạm chặn</title>
    <style>
        :root {
            color-scheme: dark;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Manrope', Arial, sans-serif;
            background: radial-gradient(circle at top, #131c36, #05070f 70%);
            color: #e8f0ff;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px 18px;
        }

        .card {
            width: 100%;
            max-width: 560px;
            background: rgba(13, 22, 40, 0.92);
            border-radius: 20px;
            padding: 36px 32px;
            border: 1px solid rgba(90, 148, 255, 0.25);
            box-shadow: 0 28px 80px rgba(6, 10, 26, 0.6);
            position: relative;
            backdrop-filter: blur(14px);
        }

        .card::after {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: inherit;
            padding: 1px;
            background: linear-gradient(135deg, rgba(93, 162, 255, 0.6), rgba(113, 230, 255, 0.2));
            mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            mask-composite: exclude;
            pointer-events: none;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            border-radius: 999px;
            background: rgba(255, 96, 120, 0.16);
            color: #ff9cae;
            font-size: 0.82rem;
            font-weight: 600;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        h1 {
            margin: 24px 0 12px;
            font-size: 1.95rem;
            font-weight: 700;
            color: #f7fcff;
        }

        .lead {
            margin: 0 0 18px;
            font-size: 1.05rem;
            line-height: 1.6;
            color: rgba(223, 233, 255, 0.85);
        }

        .meta {
            display: grid;
            gap: 12px;
            margin: 24px 0 30px;
        }

        .meta-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            background: rgba(37, 57, 99, 0.25);
            padding: 12px 14px;
            border-radius: 14px;
        }

        .meta-label {
            font-size: 0.85rem;
            letter-spacing: 0.08em;
            color: rgba(179, 204, 255, 0.6);
            text-transform: uppercase;
            min-width: 110px;
        }

        .note-list {
            margin: 0;
            padding-left: 18px;
            color: rgba(210, 224, 255, 0.88);
            font-size: 0.96rem;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 32px;
        }

        .btn {
            border: none;
            border-radius: 999px;
            padding: 12px 22px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .btn.primary {
            background: linear-gradient(135deg, #5f8cff, #4bbdff);
            color: #031329;
            box-shadow: 0 12px 32px rgba(80, 156, 255, 0.35);
        }

        .btn.primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 14px 36px rgba(80, 156, 255, 0.45);
        }

        .btn.outline {
            background: transparent;
            color: rgba(216, 230, 255, 0.8);
            border: 1px solid rgba(117, 172, 255, 0.4);
        }

        .btn.outline:hover {
            transform: translateY(-1px);
            border-color: rgba(117, 172, 255, 0.6);
        }

        .footnote {
            margin-top: 26px;
            font-size: 0.78rem;
            color: rgba(160, 186, 235, 0.55);
            line-height: 1.5;
        }

        @media (max-width: 540px) {
            .card {
                padding: 28px 22px;
            }

            h1 {
                font-size: 1.65rem;
            }

            .meta-item {
                flex-direction: column;
                align-items: stretch;
            }

            .meta-label {
                min-width: auto;
            }

            .actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
<main class="card" role="alert">
    <span class="badge">Truy cập bị giới hạn</span>
    <h1><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></h1>
    <p class="lead">Để bảo vệ hệ thống khỏi các cuộc tấn công tự động, chúng tôi đã tạm chặn yêu cầu từ địa chỉ IP<br><strong><?php echo htmlspecialchars($ip, ENT_QUOTES, 'UTF-8'); ?></strong>.</p>

    <section class="meta" aria-label="Thông tin chi tiết">
        <?php if ($layer !== null): ?>
            <div class="meta-item">
                <span class="meta-label">Lớp bảo vệ</span>
                <div>
                    <strong>Layer <?php echo (int) $layer; ?></strong>
                    <div style="margin-top:4px;color:rgba(193,212,255,0.7);font-size:0.9rem;">
                        <?php if ($layer === 2): ?>Hạn chế tốc độ truy cập (Rate Limiter)
                        <?php elseif ($layer === 1): ?>Bộ lọc request (Traffic Filter)
                        <?php elseif ($layer === 3): ?>Trình duyệt chưa vượt qua thử thách
                        <?php else: ?>Hệ thống phát hiện hành vi bất thường
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($penalty !== null): ?>
            <div class="meta-item">
                <span class="meta-label">Mức độ vi phạm</span>
                <div>
                    <strong>Penalty: <?php echo (int) $penalty; ?></strong>
                    <div style="margin-top:4px;color:rgba(193,212,255,0.7);font-size:0.9rem;">
                        Các yêu cầu quá nhanh hoặc vượt hạn mức trong cửa sổ <?php echo htmlspecialchars(($metadata['window'] ?? 'được cấu hình'), ENT_QUOTES, 'UTF-8'); ?>.
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($notes)): ?>
            <div class="meta-item">
                <span class="meta-label">Ghi chú hệ thống</span>
                <ul class="note-list">
                    <?php foreach ($notes as $note): ?>
                        <li><?php echo htmlspecialchars($note, ENT_QUOTES, 'UTF-8'); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($blockReason !== null): ?>
            <div class="meta-item">
                <span class="meta-label">Reason code</span>
                <span><?php echo htmlspecialchars($blockReason, ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
        <?php endif; ?>
    </section>

    <div class="actions">
        <button class="btn primary" type="button" onclick="location.reload()">Thử lại ngay</button>
        <button class="btn outline" type="button" onclick="window.history.length ? history.back() : location.href='/'">Quay lại trang trước</button>
    </div>

    <p class="footnote">Nếu bạn là người dùng hợp lệ, hãy chờ vài phút trước khi thử lại hoặc liên hệ đội phụ trách để được hỗ trợ.</p>
</main>
</body>
</html>
