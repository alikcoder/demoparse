<?php

global $postCount;
include_once 'db.php';
include_once 'simple_html_dom.php';

function curlGetPage($url, $referer = '
https://google.com/')
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.77 Safari/537.36');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, 0);

    $response = curl_exec($ch);
    return $response;

}

function parseAndInsertData($html)
{
    global $db;
    $postCount = 1;

    // catalogCard-main-b
    foreach ($html->find('*[class="catalogCard-main-b"]') as $element) {
        $img = $element->find('*[class="catalogCard-image-i"] img', 0); // Find the img element inside the catalogCard-main-b
        $link = $element->find('.catalogCard-image', 0);
        $priceElem = $element->find('*[class="catalogCard-price"]', 0);

        $price = $priceElem->plaintext;
        $trimmedP = ltrim($price, "$");

        $priceN = isset($trimmedP) ? floatval($trimmedP) : 0;

        if ($priceN > 0) { // Check if the price is numeric and greater than 0
            $post = [
                'img' => $img->getAttribute('src'), // Get the 'src' attribute
                'title' => $img->getAttribute('alt'), // Get the 'alt' attribute
                'link' => $link->href,
                'price' => $trimmedP,
            ];

            // Используем подготовленные запросы для избежания SQL инъекций
            $db->query("INSERT IGNORE INTO posts (`title`, `img`, `link`, `price`) 
            VALUES('{$post['title']}', '{$post['img']}', '{$post['link']}', {$priceN})");

            echo "Step cycle:" . $postCount;

            echo "Original price: " . $price . PHP_EOL;
            echo "Parsed price: " . $priceN . PHP_EOL;
        }

        $postCount++;
    }
}


$startPage = 1;
$maxPages = 100; // You can adjust this limit as needed

for ($i = $startPage; $i <= $maxPages; $i++) {
    $url = "https://smarts.ua/smartfony-apple-iphone/filter/page=$i";
    $page = curlGetPage($url);
    $html = str_get_html($page);

    if (!$html) {
        echo "Error fetching page: $url\n";
    }

    parseAndInsertData($html);

    usleep(1500000); // Optional delay

    // ... You can add additional logic here if needed ...
}
?>