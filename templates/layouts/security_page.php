<?php
/**
 * Base security page layout.
 *
 * Expected $pageConfig = [
 *   'http_code' => int,
 *   'badge' => string,
 *   'title' => string,
 *   'lead' => string,
 *   'details' => array of ['label' => string, 'value' => string, 'description' => string|null],
 *   'notes' => array,
 *   'actions' => array of ['label' => string, 'variant' => 'primary'|'outline', 'href' => string|null, 'onclick' => string|null],
 *   'footnote' => string|null,
 *   'theme' => 'block'|'challenge' (optional)
 * ];
 */

$page = $pageConfig ?? [];
$httpCode = (int) ($page['http_code'] ?? 403);
$badge = $page['badge'] ?? 'Security gateway';
$title = $page['title'] ?? 'Yêu cầu của bạn đang bị giữ lại';
$lead = $page['lead'] ?? '';
$details = $page['details'] ?? [];
$notes = $page['notes'] ?? [];
$actions = $page['actions'] ?? [];
$footnote = $page['footnote'] ?? null;
$theme = $page['theme'] ?? 'block';

http_response_code($httpCode);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></title>
    <script src="/assets/js/tab-guard.js" defer></script>
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
            background: radial-gradient(circle at top, #141b33, #05070f 70%);
            color: #e8f0ff;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px 18px;
        }

        .card {
            width: 100%;
            max-width: 580px;
            background: rgba(14, 23, 42, 0.92);
            border-radius: 20px;
            padding: 38px 34px;
            border: 1px solid rgba(90, 148, 255, 0.25);
            box-shadow: 0 30px 90px rgba(6, 10, 26, 0.55);
            position: relative;
            backdrop-filter: blur(16px);
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
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
            gap: 10px;
            padding: 9px 16px;
            border-radius: 999px;
            background: rgba(255, 96, 120, 0.16);
            color: #ff9cae;
            font-size: 0.85rem;
            font-weight: 600;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            align-self: center;
        }

        .badge .code {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 42px;
            height: 24px;
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.12);
            color: #fdf5ff;
            font-size: 0.82rem;
        }

        h1 {
            margin: 26px 0 14px;
            font-size: 2rem;
            font-weight: 700;
            color: #f7fcff;
        }

        .lead {
            margin: 0 0 22px;
            font-size: 1.05rem;
            line-height: 1.65;
            color: rgba(223, 233, 255, 0.82);
        }

        .meta {
            display: grid;
            gap: 14px;
            margin: 24px 0 32px;
            width: 100%;
            text-align: left;
        }

        .meta-item {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            background: rgba(37, 57, 99, 0.25);
            padding: 14px 16px;
            border-radius: 16px;
        }

        .meta-label {
            font-size: 0.86rem;
            letter-spacing: 0.08em;
            color: rgba(179, 204, 255, 0.6);
            text-transform: uppercase;
            min-width: 120px;
        }

        .meta-content {
            color: rgba(210, 224, 255, 0.88);
            font-size: 0.97rem;
        }

        .meta-content small {
            display: block;
            margin-top: 4px;
            color: rgba(193, 212, 255, 0.68);
            font-size: 0.88rem;
        }

        .note-list {
            margin: 0;
            padding-left: 18px;
        }

        .note-list li {
            margin-bottom: 4px;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 32px;
            justify-content: center;
            width: 100%;
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
            color: rgba(216, 230, 255, 0.84);
            border: 1px solid rgba(117, 172, 255, 0.35);
        }

        .btn.outline:hover {
            transform: translateY(-1px);
            border-color: rgba(117, 172, 255, 0.55);
        }

        .footnote {
            margin-top: 28px;
            font-size: 0.8rem;
            color: rgba(160, 186, 235, 0.55);
            line-height: 1.5;
        }

        .theme-challenge .badge {
            background: rgba(101, 167, 255, 0.18);
            color: #9cc5ff;
        }

        .theme-challenge .btn.primary {
            background: linear-gradient(135deg, #6da4ff, #74e0ff);
        }

        @media (max-width: 560px) {
            .card {
                padding: 30px 24px;
            }

            h1 {
                font-size: 1.7rem;
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
<body class="theme-<?php echo htmlspecialchars($theme, ENT_QUOTES, 'UTF-8'); ?>">
<main class="card" role="alert">
    <span class="badge">
        <span><?php echo htmlspecialchars($badge, ENT_QUOTES, 'UTF-8'); ?></span>
        <span class="code"><?php echo $httpCode; ?></span>
    </span>
    <h1><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h1>
    <?php if ($lead !== ''): ?>
        <p class="lead"><?php echo nl2br(htmlspecialchars($lead, ENT_QUOTES, 'UTF-8')); ?></p>
    <?php endif; ?>

    <?php
    if (!empty($details) && isset($metadata['retry_after'])) {
        $details[] = [
            'label' => 'Thử lại sau',
            'value' => formatDuration((int) $metadata['retry_after']),
            'description' => 'Bạn cần chờ hết thời gian này trước khi truy cập lại.',
        ];
    }
    ?>

    <?php if (!empty($details) || !empty($notes)): ?>
        <section class="meta" aria-label="Thông tin chi tiết">
            <?php foreach ($details as $detail):
                $label = $detail['label'] ?? '';
                $value = $detail['value'] ?? '';
                $description = $detail['description'] ?? null;
                ?>
                <div class="meta-item">
                    <span class="meta-label"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></span>
                    <div class="meta-content">
                        <?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>
                        <?php if ($description): ?>
                            <small><?php echo htmlspecialchars($description, ENT_QUOTES, 'UTF-8'); ?></small>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if (!empty($notes)): ?>
                <div class="meta-item">
                    <span class="meta-label">Ghi chú hệ thống</span>
                    <div class="meta-content">
                        <ul class="note-list">
                            <?php foreach ($notes as $note): ?>
                                <li><?php echo htmlspecialchars($note, ENT_QUOTES, 'UTF-8'); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if (!empty($actions)): ?>
        <div class="actions">
            <?php foreach ($actions as $action):
                $variant = $action['variant'] ?? 'primary';
                $label = $action['label'] ?? 'Tiếp tục';
                $href = $action['href'] ?? null;
                $onclick = $action['onclick'] ?? null;
                $attributes = '';
                if ($href) {
                    $attributes .= ' onclick="window.location.href=\'' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '\'"';
                }
                if ($onclick) {
                    $attributes .= ' onclick="' . htmlspecialchars($onclick, ENT_QUOTES, 'UTF-8') . '"';
                }
                ?>
                <button class="btn <?php echo htmlspecialchars($variant, ENT_QUOTES, 'UTF-8'); ?>" type="button"<?php echo $attributes; ?>>
                    <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                </button>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($footnote): ?>
        <p class="footnote"><?php echo htmlspecialchars($footnote, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
</main>
</body>
</html>
