<?php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must run via CLI." . PHP_EOL);
    exit(1);
}

$options = getopt('', ['mode::', 'host::', 'delay::', 'count::']);
$mode = strtolower($options['mode'] ?? 'human');
$host = rtrim($options['host'] ?? 'http://localhost/', '/');
$delay = (float) ($options['delay'] ?? 1.0);
$count = (int) ($options['count'] ?? 50);

$allowedModes = ['human', 'burst', 'mix'];
if (!in_array($mode, $allowedModes, true)) {
    fwrite(STDERR, "Mode must be one of: " . implode(', ', $allowedModes) . PHP_EOL);
    exit(1);
}

echo "Running traffic simulation" . PHP_EOL;
echo "Mode: {$mode}, Host: {$host}, Delay: {$delay}, Count: {$count}" . PHP_EOL;

$ch = curl_init();
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_NOBODY, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$userAgents = [
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Safari/537.36',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 13_1) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17 Safari/605.1.15',
    'Mozilla/5.0 (X11; Linux x86_64) Gecko/20100101 Firefox/118.0',
];

for ($i = 0; $i < $count; $i++) {
    $path = '/';
    $ua = $userAgents[$i % count($userAgents)];
    $headers = [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.9',
    ];

    switch ($mode) {
        case 'burst':
            $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Safari/537.36';
            $headers = [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
                'Sec-Fetch-Site: none',
                'Sec-Fetch-Mode: navigate',
                'Sec-Fetch-Dest: document',
            ];
            $delay = 0.1;
            break;
        case 'mix':
            if ($i % 5 === 0) {
                $ua = 'python-requests/2.31.0';
                $headers = ['Accept: */*'];
                $delay = 0.2;
            } else {
                $delay = 1.2;
            }
            break;
        default:
            // human
            $delay = max(0.7, $delay);
            break;
    }

    curl_setopt($ch, CURLOPT_URL, $host . $path);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_USERAGENT, $ua);

    $response = curl_exec($ch);
    $info = curl_getinfo($ch);
    $status = $info['http_code'] ?? 0;

    echo "#" . ($i + 1) . " -> status: {$status}, UA: {$ua}" . PHP_EOL;

    if ($response === false) {
        echo "   Error: " . curl_error($ch) . PHP_EOL;
    }

    usleep((int) ($delay * 1_000_000));
}

curl_close($ch);

echo "Simulation complete. Check security/logs/security.log for results." . PHP_EOL;
