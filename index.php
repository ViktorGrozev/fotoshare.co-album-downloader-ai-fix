<?php
require 'vendor/autoload.php';
use Goutte\Client;
use League\Csv\CannotInsertRecord;
use League\Csv\Writer;

function fetch_with_retry($link, $context, $max_tries = 6) {
    for ($try = 1; $try <= $max_tries; $try++) {
        $body = @file_get_contents($link, false, $context);
        $headers = function_exists('http_get_last_response_headers')
            ? (http_get_last_response_headers() ?? [])
            : ($http_response_header ?? []);

        $status = 0;
        if (!empty($headers[0]) && preg_match('#\s(\d{3})\s?#', $headers[0], $m)) {
            $status = (int)$m[1];
        }
        if ($body !== false && $body !== '' && $status === 200) {
            return $body;
        }
        if ($status === 404 || $status === 410) {
            return false;
        }
        $wait = 2 ** $try;
        foreach ($headers as $h) {
            if (stripos($h, 'Retry-After:') === 0) {
                $wait = max($wait, (int)trim(substr($h, 12)));
            }
        }
        echo "  HTTP $status, retrying in {$wait}s (try $try/$max_tries)" . PHP_EOL;
        sleep($wait);
    }
    return false;
}

function looks_like_html($body) {
    $start = strtolower(ltrim(substr($body, 0, 200)));
    return strpos($start, '<!doctype html') === 0 || strpos($start, '<html') === 0;
}

if (isset($_GET['url']) || !empty($argv[1])) {
    $url = isset($_GET['url']) ? $_GET['url'] : trim($argv[1]);

    $folder = __DIR__ . DIRECTORY_SEPARATOR . basename($url);

    if (!is_dir($folder)) {
        $dir = mkdir($folder, 0755);
        if ($dir === false) {
            exit('Error: You need to set the permission correctly!');
        }
    }
    $data = [];
    $client = new Client();
    $crawler = $client->request('GET', $url);
    $crawler->filter('[data-img][data-url]')->each(function ($node) {
        global $data;
        $data[] = $node->extract(['data-img', 'data-url', 'data-thumb', 'data-width', 'data-height', 'data-type'])[0];
    });

    $files_count = count($data);
    if (php_sapi_name() == 'cli') { echo "$files_count files found." . PHP_EOL; }

    try {
        $writer = Writer::createFromPath($folder . DIRECTORY_SEPARATOR . 'images.csv', 'w+');
        $writer->insertOne(['Image URL', 'GIF/Thumbnail', 'Fotoshare.co Path', 'Width', 'Height', 'Type']);
        $writer->insertAll($data);
    } catch (CannotInsertRecord $e) {
        echo $e->getMessage();
    }

    $base = 'https://fotoshare.co';
    $context = stream_context_create([
        'http' => [
            'header' => "User-Agent: Mozilla/5.0\r\n",
            'follow_location' => 1,
            'ignore_errors' => true,
        ],
    ]);

    foreach ($data as $key => $row) {
        $index = $key + 1;
        $type = strtolower($row[5]);

        // CDN link without the ?width=450&aspect_ratio=1:1 thumbnail query = full-size original
        $cdn = strtok($row[2], '?');

        $targets = [];
        if ($type === 'mp4') {
            // Videos: read the real video URL from the page's og:video tag
            $page = fetch_with_retry($base . '/' . ltrim($row[0], '/'), $context);
            if ($page !== false && preg_match('#og:video(?::url|:secure_url)?"\s+content="([^"]+)"#i', $page, $m)) {
                $targets[] = html_entity_decode($m[1]);
            }
            $targets[] = $cdn; // the thumbnail/gif, as the original script did
        } else {
            $targets[] = $cdn;
        }

        foreach ($targets as $link) {
            $name = basename(parse_url($link, PHP_URL_PATH));
            $path = $folder . DIRECTORY_SEPARATOR . $name;

            if (file_exists($path)) {
                if (php_sapi_name() == 'cli') { echo "($index/$files_count) File skipped: $name" . PHP_EOL; }
                continue;
            }

            $contents = fetch_with_retry($link, $context);
            if ($contents === false || looks_like_html($contents)) {
                if (php_sapi_name() == 'cli') { echo "($index/$files_count) FAILED: $link" . PHP_EOL; }
                continue;
            }

            file_put_contents($path, $contents);
            usleep(200000); // 0.2s between downloads
            if (php_sapi_name() == 'cli') { echo "($index/$files_count) Downloaded: $name" . PHP_EOL; }
        }
    }
}

if (php_sapi_name() !== 'cli') {
    require __DIR__ . DIRECTORY_SEPARATOR . 'view.php';
}
