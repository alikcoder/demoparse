<?php

set_time_limit(0);
ob_implicit_flush();
error_reporting(E_ALL);
header('Content-Type: text/html; charset=utf-8');
define('MAIN_PATH', dirname(__DIR__));
//define('MAIN_PATH', __DIR__);
define('IMG_DIR', MAIN_PATH. '/image');
define('PRODUCT_IMG_DIR', IMG_DIR. '/catalog/product');
define('CATEGORY_FPATH', __DIR__. '/categories.txt');
define('GOOGLE_API_KEY', 'AIzaSyCPCRcTtojF9ycnta-8aPVqVJjinuKRdoU');
define('COOKIE_PATH', __DIR__. '/cookie.txt');
define('DELIM', isset($_SERVER["DOCUMENT_ROOT"]) && $_SERVER["DOCUMENT_ROOT"]?"<br/>":"\n");

require MAIN_PATH. '/config.php';
require __DIR__. '/classes/mysql.class.php';

/*
if (!isset($argv[1])) {
    exit('Supplier not found');
}

if (!in_array($argv[1], ['elko', 'asbis', 'tbaltic'])) {
    exit('New supplier: ', $argv[1]);
}
*/

$parser = new Parser();
$parser->run('elko');
$parser->setCategoryPath();
$parser->setSeoUrls();
if (isset($argv[1]) && $argv[1] == 'discount') {
    $parser->setDiscountPrices();
}
echo "Finish!";

class Parser
{
    private $_mainUrl = 'https://test.testim4.pp.ua/index.php?route=feed/universal_feed';
    private $_languageIds = ['et' => 1, 'ru' =>  2, 'en' => 3];
    private $_languages = ['elko' => 3, 'asbis' => 3, 'tbaltic' => 2];
    private $_db;
    private $_activeProductIds = [];
    private $_translations = [];
    private $_attrGroupId = 1;

    function __construct() {
        $this->_db = new SafeMySQL(array('host' => DB_HOSTNAME, 'user' => DB_USERNAME, 'pass' => DB_PASSWORD, 'db' => DB_DATABASE));
    }

    function run($supplier)
    {
        if (!file_exists(CATEGORY_FPATH)) {
            exit('File not exists: '. CATEGORY_FPATH);
        }

        $categories = [];
        foreach (file(CATEGORY_FPATH) as $line) {
            $row = explode('||', $line);
            if (count($row) != 2) {
                exit('Wrong format: '. $line);
            }
            $categories[trim($row[0])] = trim($row[1]);
        }

        $products = $this->extractProducts();

        foreach ($products as $ean => $productData) {
            $sku = $productData['sku'];
            echo "EAN: $ean, sku: $sku";
            if (!isset($categories[$productData['categoryid']])) {
                exit('Corresponding category not found: '.  $productData['categoryid']);
            }
            $productData['categories'] = [$categories[$productData['categoryid']]];
            $productData['price'] = $this->adjustPrice($productData['price']);

            if ($product = $this->getProductData($ean, $sku)) {
                echo ", exists";
                $this->_activeProductIds[] = $product['product_id'];
                $upd = [];
                if ($product['ean'] == $productData['ean'] && strtolower($product['mpn']) == 'bigbuy') {
                    $upd['mpn'] = 'nobigbuy';
                }
                $stockStatusId = 7;
                if (!$productData['quantity']) {
                    $stockStatusId = 5;
                }
                if ($stockStatusId != $product['stock_status_id']) {
                    $upd['stock_status_id'] = $stockStatusId;
                }
                if ($productData['price'] != $product['price']) {
                    $upd['price'] = $productData['price'];
                }
                if ($productData['quantity'] != $product['quantity']) {
                    $upd['quantity'] = $productData['quantity'];
                }

                if (count($upd)) {
                    $this->updateProduct($product['product_id'], $upd);
                }
            }else {
                $productLanguageCode = array_search($productData['language_id'], $this->_languageIds);
                if (!$productLanguageCode) {
                    exit('Unknown product language: '. $productData['language_id']);
                }
                $productData['content'] = [];

                $attrNamesOrig = array_column($productData['attr'], 'name');
                if ($productLanguageCode !=  'en') {
                    $attrNames = $this->translateShort('en', $productLanguageCode, $attrNamesOrig);
                    $i = 0;
                    foreach ($productData['attr'] as $attrId => $row) {
                        $productData['attr'][$attrId]['name'] = $attrNames[$i];
                        $i++;
                    }
                }

                $productData['content'][$productData['language_id']] = ['name' =>  $productData['name'], 'description' => $productData['description'], 'attr' => $productData['attr']];

                $langToTranslate = array_diff($this->_languageIds, [$productData['language_id']]);
                foreach ($langToTranslate as $languageCode => $languageId) {
                    $translatedProduct = $this->translate($productLanguageCode, $languageCode, $productData);
                    $productData['content'][$languageId] = $translatedProduct;
                }

                $productData['model'] = $productData['sku'];
                $productData['mpn'] = 'nobigbuy';
                $productData['pic_dir'] = implode('/', array_map('self::totranslit', $productData['categories']));
                $productData['language_id'] = 2;
                $productData['tax_class_id'] = 4;
                $productId = $this->insertProduct($productData);
                //var_dump($productId);exit();
                //var_dump($productData);exit();
            }
            echo DELIM;
        }
    }

    function extractProducts()
    {
        $return = [];
        foreach (['elko' => 'E', 'asbis' =>  'A', 'tbaltic' =>  'T'] as $supplier => $supplierPrefix) {
            if (!isset($this->_languages[$supplier])) {
                exit('Language not found: '. $supplier);
            }
            $resp = $this->getPageHtml($this->_mainUrl. "&feed=$supplier.xml");
            $resp = str_replace('<![CDATA[', '', $resp);
            $resp = str_replace(']]>', '', $resp);
            $dom = new DomDocument();
            @$dom->loadHTML($resp);
            $xpath = new DomXPath($dom);
            $productNodes = $xpath->query('//items / item');
            foreach ($productNodes as $productItem) {
                $productData = ['language_id' => $this->_languages[$supplier]];
                foreach (['ean', 'sku', 'name', 'brand', 'price', 'quantity', 'description', 'weight', 'width', 'height', 'length', 'categoryid'] as $fieldName) {
                    $item = $productItem->getElementsByTagName($fieldName)->item(0);
                    if ($fieldName == 'description') {
                        $description = '';
                        if ($item) {
                            $description = trim($item->ownerDocument->saveXml($item));
                            $description = preg_replace('/^<description>/', '', $description);
                            $description = trim(preg_replace('/<\/description>$/', '', $description));
                            $productData[$fieldName] = $description;
                        }else{
                            $productData[$fieldName] = '';
                        }
                        $productData[$fieldName] = preg_replace('/\]\]>$/', '', $productData[$fieldName]);
                    }else {
                        $productData[$fieldName] = $item ? trim($item->nodeValue) : false;
                    }
                }
                if (!$productData['ean']) {
                     exit('Ean not found');
                }
                if (!$productData['price']) {
                    exit('Price not found');
                }
                if (!$productData['sku']) {
                    exit('Sku not found');
                }
                if (!$productData['categoryid']) {
                    exit('categoryid not found');
                }
                if (isset($return[$productData['ean']])) {
                    if ($productData['price'] < $return[$productData['ean']]['price'] && $productData['quantity']) {
                        $return[$productData['ean']]['price'] = $productData['price'];
                    }
                }else {
                    $productData['sku'] = $supplierPrefix. $productData['sku'];
                    $productData['attr'] = [];
                    $attrNodes = $productItem->getElementsByTagName('attribute');
                    foreach ($attrNodes as $attrItem) {
                        $attrName = $attrItem->getAttribute('name');
                        if ($attrName) {
                            $attrValue = trim($attrItem->nodeValue);
                            $attrId = $attrItem->getAttribute('attributeid');
                            if (!$attrId) {
                                exit('Attribute id not found');
                            }
                            $productData['attr'][$attrId] = ['name' => $attrName, 'value' => $attrValue];
                        }
                    }
                    $productData['images'] = [];
                    $imgNodes = $productItem->getElementsByTagName('image');
                    foreach ($imgNodes as $imgItem) {
                        $imageUrlItem = $imgItem->getElementsByTagName('url')->item(0);
                        if ($imageUrlItem) {
                            $productData['images'][] = trim($imageUrlItem->nodeValue);
                        }
                    }
                    $return[$productData['ean']] = $productData;
                }
            }
        }

        return $return;
    }

    function getProductData($ean, $sku) {
        $return = false;
        $sql = "SELECT p.product_id, p.price, p.status, p.quantity, p.stock_status_id, p.discount_percent, p.ean, p.mpn, pd.language_id, pc.category_id, ps.price AS `special_price` FROM `". DB_PREFIX. "product` AS `p`
        JOIN `". DB_PREFIX. "product_description` AS `pd` ON pd.product_id = p.product_id
    	LEFT JOIN `". DB_PREFIX. "product_to_category` AS `pc` ON pc.product_id = p.product_id
        LEFT JOIN `". DB_PREFIX. "product_special` AS `ps` ON ps.product_id = p.product_id
    	WHERE p.ean = ?s OR p.sku = ?s";

        $data = $this->_db->getAll($sql, $ean, $sku);
        if (count($data)) {
            $return = $data[0];
            $return['categories'] = array();
            $return['languages'] = array();
            unset($return['category_id']);
            unset($return['language_id']);
            foreach ($data as $row) {
                if ($row['category_id'] && !in_array($row['category_id'], $return['categories'])) $return['categories'][] = $row['category_id'];
                if ($row['language_id'] && !in_array($row['language_id'], $return['languages'])) $return['languages'][] = $row['language_id'];
            }
        }
        return $return;
    }

    function updateProduct($productId, array $data) {
        if (count($data)) {
            $sql = "UPDATE `". DB_PREFIX. "product` SET ?u WHERE `product_id`= $productId";
            $this->_db->query($sql, $data);
        }
    }

    function insertProduct(array $data) {
        $added = date('Y-m-d H:i');
        $manufacturerId = 0;
        if (isset($data['brand']) && $data['brand']) {
            $manufacturerId = $this->insertManufacturerIfNotExists($data['brand']);
        }
        $insert = array('sku' => $data['sku'] ,'model' => $data['model'], 'manufacturer_id' => $manufacturerId, 'quantity' => $data['quantity'], 'stock_status_id' => $data['quantity'] > 0?7:5, 'subtract' => 0,
            'status' => 1, 'shipping' => 1, 'price' => $data['price'], 'date_added' => $added, 'date_modified' => $added);

        foreach (array('ean', 'upc', 'mpn', 'minimum', 'weight', 'length', 'width', 'height', 'tax_class_id', 'url') as $fieldName) {
            if (isset($data[$fieldName]) && $data[$fieldName]) {
                $insert[$fieldName] = $data[$fieldName];
            }
        }

        $sql = "INSERT INTO `". DB_PREFIX. "product` SET ?u";
        $this->_db->query($sql, $insert) or die(mysqli_error($this->_db->conn));
        $productId = $this->_db->insertId();

        $picDir = PRODUCT_IMG_DIR. '/'. $data['pic_dir'];
        if (!file_exists($picDir)) mkdir($picDir, 0777, true);
        if (isset($data['images'][0])) {
            $imageUrl = $this->formatUrl($data['images'][0]);
            $fileInfo = pathinfo($imageUrl);
            $ext = $fileInfo['extension'];
            $imageData = $this->getPageHtml($imageUrl, false, array(), true);
            if (preg_match('/200\s+/i', $imageData['header'])) {
                $startPicPath = $picDir. '/'. $productId. "_1.$ext";
                file_put_contents($startPicPath, $imageData['body']);
                if (file_exists($startPicPath)) {
                    $startPicPath = str_replace(IMG_DIR. '/', '', $startPicPath);
                    $sql = "UPDATE `". DB_PREFIX. "product` SET `image` = ?s WHERE `product_id` = ?i";
                    $this->_db->query($sql, $startPicPath, $productId);
                }else {
                    echo "Wrong picture: $imageUrl". DELIM;
                    flush();
                }
                unset($data['images'][0]);
            }
            $picCounter = 2;
            $insertPicsSqlParts = array();
            $sql = "INSERT INTO `". DB_PREFIX. "product_image` (`product_id`, `image`, `sort_order`) VALUES";
            $imgNum = 0;
            foreach ($data['images'] as $picUrl) {
                $fileInfo = pathinfo($picUrl);
                $ext = $fileInfo['extension'];
                $picUrl = $this->formatUrl($picUrl);
                $imageData = $this->getPageHtml($picUrl, false, array(), true);
                if (preg_match('/200\s+/i', $imageData['header'])) {
                    $picPath = $picDir. '/'. $productId. "_$picCounter.$ext";
                    file_put_contents($picPath, $imageData['body']);
                    if (file_exists($picPath)) {
                        $picPath = str_replace(IMG_DIR. '/', '', $picPath);
                        $insertPicsSqlParts[] = "($productId, '$picPath', $picCounter - 1)";
                        $picCounter ++;
                    }else {
                        echo "Wrong picture: $picUrl". DELIM;
                        flush();
                    }
                }
            }
            if (count($insertPicsSqlParts)) {
                $sql.= implode(",", $insertPicsSqlParts);
                $this->_db->query($sql);
            }
        }

        foreach ($data['categories'] as $categoryId) {
            $sql = "INSERT INTO `". DB_PREFIX. "product_to_category` (`product_id`, `category_id`) VALUES ( ?i, ?i )";
            $this->_db->query($sql, $productId, $categoryId);
        }

        $sql = "INSERT INTO `" . DB_PREFIX . "product_to_store` (`product_id`, `store_id`) VALUES ( ?i, ?i )";
        $this->_db->query($sql, $productId, 0);

        foreach ($data['content'] as $languageId => $content) {
            $productName = str_replace('"', '', $content['name']);
            $descInsert = array('product_id' => $productId, 'name' => $productName, 'meta_title' => $productName, 'language_id' => $languageId);
            if (isset($content['description']) && $content['description']) {
                $descInsert['description'] = $content['description'];
            }
            $sql = "INSERT INTO `" . DB_PREFIX . "product_description` SET ?u";
            $this->_db->query($sql, $descInsert);

            if (count($content['attr'])) {
                $this->setProductAttributes($productId, $content['attr'], $languageId);
            }
        }

        return $productId;
    }

    private function setProductAttributes($productId, array $attr, $languageId) {
        if (count($attr)) {
            $attrIdByName = [];
            foreach ($attr as $attrId => $row) {
                $attrName = $row['name'];
                $attrValue = $row['value'];
                $sql = "SELECT `attribute_id` FROM `". DB_PREFIX. "attribute` WHERE `attribute_id` = $attrId";
                $res = $this->_db->getOne($sql);
                if (!$res) {
                    $sql = "INSERT INTO `" . DB_PREFIX . "attribute` SET `attribute_group_id` = $this->_attrGroupId, `attribute_id` = $attrId";
                    $this->_db->query($sql);
                }
                $sql = "SELECT `attribute_id` FROM `". DB_PREFIX. "attribute_description` WHERE `attribute_id` = $attrId AND `name` = ?s AND `language_id` = $languageId";
                $res = $this->_db->getOne($sql, $attrName);
                if (!$res) {
                    $sql = "INSERT INTO `". DB_PREFIX. "attribute_description` (`attribute_id`, `language_id`, `name`) VALUES ($attrId, $languageId, ?s)";
                    $this->_db->query($sql, $attrName);
                }

                $sql = "INSERT IGNORE INTO `". DB_PREFIX. "product_attribute` (`product_id`, `attribute_id`, `language_id`, `text`) VALUES ($productId, $attrId, $languageId, ?s)";
                $this->_db->query($sql, $attrValue);

            }
        }
    }

    function insertManufacturerIfNotExists($name, $image = null) {
        $sql = "SELECT `manufacturer_id` FROM `". DB_PREFIX. "manufacturer` WHERE `name` = ?s";
        $manufId = $this->_db->getOne($sql, $name);
        if ($manufId) {
            return $manufId;
        }
        $img = '';
        if ($image) {
            $imgUrl = $this->formatUrl($image);
            if (!preg_match('/\.([a-z]+)$/i', $imgUrl, $extMatches)) {
                exit('No extension: '. $imgUrl);
            }
            $imgExt = $extMatches[1];
            $imageData = $this->getPageHtml($imgUrl, false, array(), true);
            if (preg_match('/200\s+/i', $imageData['header'])) {
                $imgDir = IMG_DIR. '/catalog/manufacturer';
                if (!file_exists($imgDir)) {
                    mkdir($imgDir, 0777, true);
                }
                $imgPath = "$imgDir/". $this->totranslit($name). ".$imgExt";
                file_put_contents($imgPath, $imageData['body']);
                if (file_exists($imgPath)) {
                    $img = str_replace(IMG_DIR. '/', '', $imgPath);
                }
            }else {
                exit('Image not found: '. $imgUrl);
            }
        }
        $sql = "INSERT INTO `". DB_PREFIX. "manufacturer` SET `name` = ?s, sort_order = 0";
        if ($img) {
            $sql .= ", `image` = '$img'";
        }
        $this->_db->query($sql, $name);
        $manufacturerId = $this->_db->insertId();
        $sql = "INSERT INTO `". DB_PREFIX. "manufacturer_to_store` SET `manufacturer_id` = ?i, `store_id` = 0";
        $this->_db->query($sql, $manufacturerId);

        return $manufacturerId;
    }

    function setCategoryPath(array $parents = array()) {
        if (count($parents)) {
            $directParentId = end($parents);
        }else {
            $sql = "TRUNCATE `". DB_PREFIX. "category_path`";
            $this->_db->query($sql);
            $directParentId = 0;
        }
        $sql = "SELECT `category_id` FROM `". DB_PREFIX. "category` WHERE `parent_id` = $directParentId";
        $categories = array();
        foreach ($this->_db->getAll($sql) as $row) {
            $categories[] = $row['category_id'];
        }
        if (count($categories)) {
            foreach ($categories as $categoryId) {
                $allids = array_merge($parents, array($categoryId));
                $insert = array();
                foreach ($allids as $level => $currentId) {
                    $insert[] = "($categoryId, $currentId, $level)";
                }
                if (count($insert)) {
                    $sql = "INSERT IGNORE INTO `". DB_PREFIX. "category_path` (`category_id`, `path_id`, `level`) VALUES ". implode(',', $insert);
                    $this->_db->query($sql);
                }
                $this->setCategoryPath($allids);
            }
        }
    }

    function setSeoUrls() {
        foreach ([1, 2, 3] as $languageId) {
            $sql = "SELECT `query`, `keyword` FROM `". DB_PREFIX. "seo_url` WHERE `language_id` = $languageId";
            $uriAliases = array();
            foreach($this->_db->getAll($sql) as $row) {
                $uriAliases[$row['query']] = $row['keyword'];
            }
            $sql = "SELECT `category_id`, `name` FROM `". DB_PREFIX. "category_description` WHERE `language_id` = $languageId ORDER BY `category_id` ASC";
            foreach($this->_db->getAll($sql) as $row) {
                $key = 'category_id='. $row['category_id'];
                if (!isset($uriAliases[$key])) {
                    $alias = $this->totranslit($row['name']);
                    $sql = "SELECT `seo_url_id` FROM `". DB_PREFIX. "seo_url` WHERE `keyword` = '$alias' AND `language_id` = $languageId";
                    $seoUrlId = $this->_db->getOne($sql);
                    if ($seoUrlId) {
                        $alias = $row['category_id']. '_'. $this->totranslit($row['name']);
                    }
                    $sql = "INSERT INTO `". DB_PREFIX. "seo_url` SET `query` = '$key', `keyword` = '$alias', `language_id` = $languageId";
                    $this->_db->query($sql);
                }
            }
            $sql = "SELECT `product_id`, `name` FROM `". DB_PREFIX. "product_description` WHERE `language_id` = $languageId";
            foreach($this->_db->getAll($sql) as $row) {
                $key = 'product_id='. $row['product_id'];
                if (!isset($uriAliases[$key])) {
                    $alias = $this->totranslit($row['name']). '-'. $languageId. '-'. $row['product_id'];
                    /*$sql = "SELECT `seo_url_id` FROM `". DB_PREFIX. "seo_url` WHERE `keyword` = '$alias' AND `language_id` = $languageId";
                    $seoUrlId = $this->_db->getOne($sql);
                    if ($seoUrlId) {
                        $alias = $row['product_id']. '_'. $this->totranslit($row['name']);
                    }*/
                    $sql = "INSERT INTO `". DB_PREFIX. "seo_url` SET `query` = '$key', `keyword` = '$alias', `language_id` = $languageId";
                    $this->_db->query($sql);
                }
            }
            $sql = "SELECT `manufacturer_id`, `name` FROM `". DB_PREFIX. "manufacturer`";
            foreach($this->_db->getAll($sql) as $row) {
                $key = 'manufacturer_id='. $row['manufacturer_id'];
                if (!isset($uriAliases[$key])) {
                    $alias = $this->totranslit($row['name']);
                    $sql = "SELECT `seo_url_id` FROM `". DB_PREFIX. "seo_url` WHERE `keyword` = '$alias' AND `language_id` = $languageId";
                    $seoUrlId = $this->_db->getOne($sql);
                    if ($seoUrlId) {
                        $alias = $row['manufacturer_id']. '_'. $this->totranslit($row['name']);
                    }
                    $sql = "INSERT INTO `". DB_PREFIX. "seo_url` SET `query` = '$key', `keyword` = '$alias', `language_id` = $languageId";
                    $this->_db->query($sql);
                }
            }
        }
    }

    function adjustPrice($price) {
        $procent = 0;
        if($price > 0 and $price <= 50) {
            $procent = 18; // количество процентов наценки товара
        }elseif($price > 50 and $price <= 300){
            $procent = 15; // количество процентов наценки товара
        }elseif($price > 100 and $price <= 300){
            $procent = 14; // количество процентов наценки товара
        }elseif($price > 300 and $price <= 600){
            $procent = 12; // количество процентов наценки товара
        }elseif($price > 600 and $price <= 1000){
            $procent = 8; // количество процентов наценки товара
        }elseif($price > 1000 and $price <= 1500){
            $procent = 6; // количество процентов наценки товара
        }elseif($price > 1500 and $price <= 2200){
            $procent = 5; // количество процентов наценки товара
        }elseif($price > 2200 and $price <= 9999999){
            $procent = 4; // количество процентов наценки товара
        }else{
            $procent = 18; // количество процентов наценки товара
        }
        $price += $procent / 100 * $price;

        return $price;
    }

    function translateShort($source, $target, $data) {
        $return = [];
        $post = ['q' => $data];
        $resp = $this->getPageHtml('https://www.googleapis.com/language/translate/v2?key='. GOOGLE_API_KEY. '&source='. $source. '&target='. $target, json_encode($post), ['Content-Type: application/json; charset=utf-8']);
        $json = json_decode($resp, true);
        $translations = isset($json['data']['translations'])?$json['data']['translations']:[];

        if (count($translations) != count($data)) {
            exit('Translations count <> sources count');
        }

        for ($i = 0; $i < count($translations); $i ++) {
            $translatedText = html_entity_decode($translations[$i]['translatedText']);
            $return[] = html_entity_decode($translations[$i]['translatedText']);
        }
        return $return;
    }

    function translate($source, $target, $productData) {
        $return = [];
        $post = ['q' => [$productData['name'], $productData['description']]];
        foreach ($productData['attr'] as $attrId => $attrData) {
            $post['q'][] = $attrData['name'];
            $post['q'][] = $attrData['value'];
        }

        $resp = $this->getPageHtml('https://www.googleapis.com/language/translate/v2?key='. GOOGLE_API_KEY. '&source='. $source. '&target='. $target, json_encode($post), ['Content-Type: application/json; charset=utf-8']);
        $json = json_decode($resp, true);

        if (!$json) {
            exit('Not json: '. $resp);
        }
        $translations = isset($json['data']['translations'])?$json['data']['translations']:[];
        if (!$translations) {
            exit('Translations not found: '. $resp);
        }

        if (count($translations) != count($productData['attr']) * 2 + 2) {
            exit('Translations count <> sources count');
        }

        $attr = [];
        for ($i = 0; $i < count($translations); $i ++) {
            $translatedText = html_entity_decode($translations[$i]['translatedText']);
            if ($i == 0) {
                $return['name'] = $translatedText;
            }elseif ($i == 1) {
                $return['description'] = $translatedText;
            }elseif ($i < count($translations) - 1) {
                $this->_translations[$productData['description']] = $translatedText;
                $i ++;
                $attr[] = ['name' => ucfirst($translatedText), 'value' => html_entity_decode($translations[$i]['translatedText'])];
            }
        }

        $return['attr'] = array_combine(array_keys($productData['attr']), $attr);

        return $return;
    }


    function totranslit($var, $lower = true) {
        $langtranslit = array(
            'а' => 'a', 'б' => 'b', 'в' => 'v',
            'г' => 'g', 'д' => 'd', 'е' => 'e',
            'ё' => 'e', 'ж' => 'zh', 'з' => 'z',
            'и' => 'i', 'й' => 'y', 'к' => 'k',
            'л' => 'l', 'м' => 'm', 'н' => 'n',
            'о' => 'o', 'п' => 'p', 'р' => 'r',
            'с' => 's', 'т' => 't', 'у' => 'u',
            'ф' => 'f', 'х' => 'h', 'ц' => 'c',
            'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch',
            'ь' => '', 'ы' => 'y', 'ъ' => '',
            'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
            "ї" => "yi", "є" => "ye",

            'А' => 'A', 'Б' => 'B', 'В' => 'V',
            'Г' => 'G', 'Д' => 'D', 'Е' => 'E',
            'Ё' => 'E', 'Ж' => 'Zh', 'З' => 'Z',
            'И' => 'I', 'Й' => 'Y', 'К' => 'K',
            'Л' => 'L', 'М' => 'M', 'Н' => 'N',
            'О' => 'O', 'П' => 'P', 'Р' => 'R',
            'С' => 'S', 'Т' => 'T', 'У' => 'U',
            'Ф' => 'F', 'Х' => 'H', 'Ц' => 'C',
            'Ч' => 'Ch', 'Ш' => 'Sh', 'Щ' => 'Sch',
            'Ь' => '', 'Ы' => 'Y', 'Ъ' => '',
            'Э' => 'E', 'Ю' => 'Yu', 'Я' => 'Ya',
            "Ї" => "yi", "Є" => "ye",
        );

        $var = trim( strip_tags( $var ) );
        $var = preg_replace( "/\s+/ums", "-", $var );
        $var = strtr($var, $langtranslit);
        $var = preg_replace('/\.+/', '_', $var);
        $var = preg_replace( "/[^a-z0-9\_\-]+/mi", "", $var );

        $var = preg_replace( '#[\-]+#i', '-', $var );
        if ($lower) $var = strtolower( $var );
        $var = str_ireplace( ".php", "", $var );
        $var = str_ireplace( ".php", ".ppp", $var );
        if( strlen($var) > 200 ) {
            $var = substr( $var, 0, 200 );
            if(($temp_max = strrpos( $var, '-' ))) $var = substr($var, 0, $temp_max);
        }

        return $var;
    }

    function formatUrl($url) {
        if ($url && !preg_match('/^https*:\/\//', $url)) {
            if (substr($url, 0, 1) == '?') $url = '/catalog'. $url;
            $url = $this->_mainUrl. (!preg_match('/^\//', $url)?'/':''). $url;
        }
        $url = str_replace(' ', '%20', $url);
        return $url;
    }


    function getPageHtml($url, $post = false, array $addHeaders = array(), $full = false, $counter = 0) {
        $headers = array(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36',
            'Accept-Language: en-US,en;q=0.5',
            'Connection: keep-alive'
        );
        if (count($addHeaders)) {
            $headers = array_merge($headers, $addHeaders);
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 400);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt ($ch, CURLOPT_REFERER, $this->_mainUrl);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_COOKIEJAR, COOKIE_PATH);
        curl_setopt($ch, CURLOPT_COOKIEFILE, COOKIE_PATH);
        if ($post) {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        }
        $res = curl_exec($ch);
        $headerIndex = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        if (!$full) {
            $res = substr($res, $headerIndex);
            $res = mb_convert_encoding($res, 'HTML-ENTITIES', 'utf-8');
        }else {
            $res = array('header' => trim(substr($res, 0, $headerIndex - 1)), 'body' => trim(substr($res, $headerIndex)));
        }
        curl_close($ch);

        return $res;
    }

}