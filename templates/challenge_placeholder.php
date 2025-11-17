<?php
/** @var array $challengeBundle */
/** @var string $challengeEndpoint */

header('Content-Type: text/html; charset=UTF-8');

$bundleJson = htmlspecialchars(json_encode($challengeBundle, JSON_UNESCAPED_SLASHES));
$redirectUrl = $_SESSION['_security']['challenge']['redirect'] ?? '/';
$redirectAttr = htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8');
$endpointAttr = htmlspecialchars($challengeEndpoint, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Đang kiểm tra trình duyệt...</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            color-scheme: dark;
        }

        body {
            font-family: 'Manrope', Arial, sans-serif;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            background: radial-gradient(circle at top, #152347, #0b1426 70%);
            color: #e7f0ff;
            padding: 20px;
        }

        .challenge-wrapper {
            width: 100%;
            max-width: 460px;
        }

        .challenge-card {
            background: rgba(13, 24, 46, 0.9);
            border: 1px solid rgba(76, 140, 255, 0.25);
            border-radius: 20px;
            padding: 36px 32px;
            box-shadow: 0 20px 60px rgba(8, 15, 32, 0.45);
            position: relative;
            backdrop-filter: blur(12px);
        }

        .challenge-card::after {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: 20px;
            padding: 1px;
            background: linear-gradient(135deg, rgba(76, 140, 255, 0.5), rgba(93, 212, 255, 0.2));
            mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            mask-composite: exclude;
            pointer-events: none;
        }

        .spinner {
            width: 58px;
            height: 58px;
            border-radius: 50%;
            border: 5px solid rgba(101, 167, 255, 0.25);
            border-top-color: #64c8ff;
            border-right-color: rgba(100, 200, 255, 0.6);
            animation: spin 1s ease-in-out infinite;
            margin: 0 auto 28px;
        }

        h1 {
            font-size: 1.6rem;
            font-weight: 700;
            margin: 0 0 14px;
            text-align: center;
            letter-spacing: 0.03em;
        }

        p {
            font-size: 0.98rem;
            line-height: 1.6;
            text-align: center;
            color: rgba(217, 230, 255, 0.85);
            margin: 0 0 12px;
        }

        .status {
            margin-top: 26px;
            background: rgba(76, 140, 255, 0.12);
            border-radius: 12px;
            padding: 18px 20px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .status-label {
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.12em;
            color: rgba(220, 236, 255, 0.55);
        }

        .status-message {
            font-size: 0.95rem;
            font-weight: 600;
            color: #b7d6ff;
        }

        .progress-track {
            width: 100%;
            height: 6px;
            border-radius: 999px;
            background: rgba(62, 110, 204, 0.35);
            overflow: hidden;
        }

        .progress-bar {
            height: 100%;
            width: 8%;
            border-radius: inherit;
            background: linear-gradient(90deg, #64c8ff, #7095ff);
            transition: width 0.35s ease-out;
        }

        .error-box {
            display: none;
            margin-top: 18px;
            padding: 12px 14px;
            border-radius: 12px;
            background: rgba(255, 96, 120, 0.12);
            color: #ff9cab;
            font-size: 0.9rem;
            text-align: center;
        }

        .footer-note {
            margin-top: 32px;
            font-size: 0.75rem;
            text-align: center;
            opacity: 0.6;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        @media (max-width: 520px) {
            .challenge-card {
                padding: 28px 22px;
            }

            h1 {
                font-size: 1.4rem;
            }

            p {
                font-size: 0.92rem;
            }
        }
    </style>
</head>
<body>
<div class="challenge-wrapper">
    <div class="challenge-card" id="challenge-root"
         data-bundle='<?php echo $bundleJson; ?>'
         data-endpoint="<?php echo $endpointAttr; ?>"
         data-redirect="<?php echo $redirectAttr; ?>">
        <div class="spinner" aria-hidden="true"></div>
        <h1>Chúng tôi đang kiểm tra trình duyệt của bạn</h1>
        <p>Đây là bước bảo vệ tự động giúp ngăn chặn hành vi không đúng đắn.</p>
        <p>Vui lòng không rời khỏi trang cho tới khi hoàn tất.</p>

        <div class="status">
            <span class="status-label">Trạng thái</span>
            <span class="status-message" id="challenge-status">Đang chuẩn bị...</span>
            <div class="progress-track" aria-hidden="true">
                <div class="progress-bar" id="challenge-progress"></div>
            </div>
        </div>

        <div class="error-box" id="challenge-error"></div>

        <div class="footer-note">
            Nếu bạn là người dùng hợp pháp, quá trình này chỉ diễn ra một lần.
        </div>
    </div>
</div>

<script src="/assets/js/tab-guard.js" defer></script>
<script src="/assets/js/challenge.js?v=1"></script>
</body>
</html>
