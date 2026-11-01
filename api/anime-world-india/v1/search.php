<?php
header("Content-Type: application/json; charset=UTF-8");

// Get parameters
$query = isset($_GET['query']) ? trim($_GET['query']) : '';
$page = isset($_GET['p']) && is_numeric($_GET['p'])
    ? max(1, (int)$_GET['p'])
    : 1;

if ($query === '') {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error" => "Missing query parameter"
    ]);
    exit;
}

// Build AnimeWorld URL
$targetUrl = "https://animeworld-india.me/search?q="
    . urlencode($query) . "&page=" . $page;

// Direct request (no CORS proxy)
$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL => $targetUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 5,
    CURLOPT_USERAGENT =>
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36",
    CURLOPT_HTTPHEADER => [
        "Accept: text/html,application/xhtml+xml",
        "Accept-Language: en-US,en;q=0.9"
    ],
    CURLOPT_ENCODING => "",
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT => 30
]);

$html = curl_exec($ch);

if ($html === false) {
    $error = curl_error($ch);
    curl_close($ch);

    http_response_code(502);
    echo json_encode([
        "success" => false,
        "error" => "Request failed",
        "details" => $error
    ]);
    exit;
}

$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

curl_close($ch);

// Check HTTP response
if ($httpCode < 200 || $httpCode >= 300) {
    http_response_code(502);
    echo json_encode([
        "success" => false,
        "error" => "AnimeWorld returned an HTTP error",
        "http_code" => $httpCode,
        "content_type" => $contentType,
        "final_url" => $finalUrl,
        "response_preview" => substr($html, 0, 1000)
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}
// Load HTML
libxml_use_internal_errors(true);

$dom = new DOMDocument();

$loaded = $dom->loadHTML(
    '<?xml encoding="UTF-8">' . $html
);

libxml_clear_errors();

if (!$loaded) {
    http_response_code(502);
    echo json_encode([
        "success" => false,
        "error" => "Could not parse AnimeWorld HTML"
    ]);
    exit;
}

$xpath = new DOMXPath($dom);

// Extract search results
$nodes = $xpath->query(
    "//ul[contains(@class,'post-lst')]//li[contains(@class,'status-publish')]"
);

$results = [];

if ($nodes !== false) {
    foreach ($nodes as $li) {

        $titleNode = $xpath->query(
            ".//h2[contains(@class,'entry-title')]",
            $li
        )->item(0);

        $imgNode = $xpath->query(".//img", $li)->item(0);

        $yearNode = $xpath->query(
            ".//span[contains(@class,'year')]",
            $li
        )->item(0);

        $linkNode = $xpath->query(
            ".//a[contains(@class,'lnk-blk')]",
            $li
        )->item(0);

        $ratingNode = $xpath->query(
            ".//span[contains(@class,'vote')]",
            $li
        )->item(0);

        $title = $titleNode
            ? trim($titleNode->textContent)
            : null;

        $image = null;

        if ($imgNode) {
            $image = $imgNode->getAttribute("data-src");

            if (!$image) {
                $image = $imgNode->getAttribute("src");
            }
        }

        $year = $yearNode
            ? trim($yearNode->textContent)
            : null;

        $link = $linkNode
            ? $linkNode->getAttribute("href")
            : null;

        $rating = $ratingNode
            ? trim($ratingNode->textContent)
            : null;

        $type = null;
        $seriesId = null;
        $movieId = null;

        if ($link) {
            $path = parse_url($link, PHP_URL_PATH) ?: "";

            if (preg_match('~/series/([^/]+)~', $path, $matches)) {
                $type = "series";
                $seriesId = $matches[1];

            } elseif (preg_match('~/movie/([^/]+)~', $path, $matches)) {
                $type = "movie";
                $movieId = $matches[1];
            }

            // Make relative links absolute
            if (strpos($link, "//") === 0) {
                $link = "https:" . $link;
            } elseif (strpos($link, "/") === 0) {
                $link = "https://animeworld-india.me" . $link;
            }
        }

        if (!$title) {
            continue;
        }

        $item = [
            "title" => $title,
            "image" => $image,
            "year" => $year,
            "rating" => $rating,
            "type" => $type,
            "url" => $link
        ];

        if ($type === "series") {
            $item["seriesId"] = $seriesId;
        } elseif ($type === "movie") {
            $item["movieId"] = $movieId;
        }

        $results[] = $item;
    }
}

// Pagination
$totalPages = null;
$hasNextPage = false;

$pageLinks = $xpath->query(
    "//nav[contains(@class,'pagination')]//a[contains(@class,'page-link')]"
);

if ($pageLinks !== false && $pageLinks->length > 0) {
    $pages = [];

    foreach ($pageLinks as $a) {
        $text = trim($a->textContent);

        if (is_numeric($text)) {
            $pages[] = (int)$text;
        }
    }

    if (!empty($pages)) {
        $totalPages = max($pages);
        $hasNextPage = $page < $totalPages;
    }
}

// Final JSON response
echo json_encode([
    "success" => true,
    "query" => $query,
    "currentPage" => $page,
    "totalPages" => $totalPages,
    "hasNextPage" => $hasNextPage,
    "source" => "animeworld-india.me/search",
    "results" => $results
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
