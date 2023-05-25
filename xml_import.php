<?php
error_reporting(E_ALL);
ini_set("display_errors", 1);

require_once('config.php');
/*
 * configuration settings
 * */

const ENDPOINT = '';
const PATH_TO_PROD = 'product'; // means whatever->products->product
const WHEN_KEYS_EXIST = ['name', 'description', 'price', 'MPN']; // only if all these values exist in product context
const VAT = 24;
const AVAIL_LANGUAGES = [2];
const DEFAULT_LANGUAGE = 2;
const NEW_PRODUCT_STATUS = 0; // 1: active, 0: inactive
const PARENT_CAT = "Main Category";
const SPECIFIC_IMG_PATH = "xml-import";
const IMAGE_DIR = DIR_IMAGE . SPECIFIC_IMG_PATH;
const SOURCE_DIR = "";
const CAT_SEPARATOR = '>';
const TAX_CLASS_ID = 9;
const STOCK_STATUS_ID = 9;
const WEIGHT_CLASS_ID = 1;
const SET_QUANTITY = 'availability:in stock=10|0';
//const SET_QUANTITY = null;
const PRODUCT_IDENTIFIER = [
    'source' => 'model', // opencart
    'destination' => 'MPN', // xml
];
/*
 * opencart field => xml key
 * */
const DEFAULT_MAPPING = [ // opencart field => xml key
    'model'             => 'MPN',
    'name'              => 'name',
    'description'       => 'description',
    'sku'               => 'partNo',
    'mpn'               => 'MPN',
    'upc'               => 'upc',
    'ean'               => 'barcode',
    'jan'               => 'jan',
    'isbn'              => 'isbn',
    'image'             => 'image',
    'status'            => 'status',
    'price'             => 'price',
    'manufacturer'      => 'manufacturer',
    'additional_images' => [
        'type'  => 'separation', // avail values prefix and array
        'separator'  => '|', // avail values prefix and array
        'value' => 'additional_images', // prefix or array
    ],
//    'additional_images' => [
//        'type'  => 'prefix', // avail values prefix and array
//        'value' => 'images_', // prefix or array
//    ],
    'quantity'          => 'availability',
    'categories'        => 'category',
    'tags'              => 'tags',
];
// opencart fields - let it empty for not update prods
const UPDATE_FIELDS = [
    'additional_images',
    'quantity',
];
// opencart fields - let it empty for not create prods
const CREATE_FIELDS = [
    'name',
    'description',
    'quantity',
    'price',
    'model',
//    'status',
    'manufacturer',
    'image',
//    'ean',
    'categories'
];

/*
 * EOF CONFIGURATION
 * */


if(is_dir('vqmod')) {
    require_once('./vqmod/vqmod.php');
    VQMod::bootup();
    require_once(VQMod::modCheck(DIR_SYSTEM . 'startup.php'));
}else{
    require_once(DIR_SYSTEM . 'startup.php');
}
require_once(modification(DIR_SYSTEM . 'engine/controller.php'));
require_once(modification(DIR_SYSTEM . 'engine/event.php'));
require_once(modification(DIR_SYSTEM . 'engine/model.php'));
require_once(modification(DIR_SYSTEM . 'engine/registry.php'));


function dd(...$vars): void
{
    echo '<pre>';
    if (!is_array($vars)) {
        print_r($vars);
    }

    foreach ($vars as $v) {
        var_dump($v);
    }

    echo '</pre>';
    exit(1);
}

class xmlImporter{
    public function __construct()
    {
        $this->config = new Config();
        $this->registry = new Registry();
        $this->language_id = DEFAULT_LANGUAGE;

        $this->products = [];
        $this->create_products = [];
        $this->update_products = [];
        $this->create_cats = [];

        $this->existing_cats = [];
        $this->existing_prods = [];
        $this->existing_manufs = [];
        $this->mapping = [];

        $this->dbConnect();
        $this->getCategories();
        $this->getProducts();
        $this->getManufacturers();
        $this->getMapping();

        if(!is_dir(IMAGE_DIR))
            mkdir(IMAGE_DIR);
    }

    public function copyImage($img_data){
        [$img_url, $img_path, $file_name] = $img_data;
        if(!is_dir(IMAGE_DIR . $img_path))
            mkdir(IMAGE_DIR . $img_path, 0775, true);

        if(strpos($img_url, 'http') === false)
            $img_url = 'https://'.$img_url;

        if(!file_exists(IMAGE_DIR . $img_path . '/' . $file_name))
            copy($img_url, IMAGE_DIR . $img_path . '/' . $file_name);

        return SPECIFIC_IMG_PATH . $img_path . '/' . $file_name;

    }

    private function setValue($data, $key){
        if(array_key_exists($key, $data))
            return $data[$key];
        else
            return null;
    }

    public function executeCreateProducts()
    {
        foreach($this->create_products as $data) {
            $stock_status_id = (int)($this->setValue($data, 'stock_status_id') ?? STOCK_STATUS_ID);
            $weight_class_id = (int)($this->setValue($data, 'weight_class_id') ?? WEIGHT_CLASS_ID);
            $minimum = $this->setValue($data, 'minimum') ?? 1;
            $subtract = $this->setValue($data, 'subtract') ?? 1;
            $shipping = $this->setValue($data, 'shipping') ?? 0;
            $points = $this->setValue($data, 'points') ?? 1;
            $weight = $this->setValue($data, 'weight') ?? 1;
            $length = $this->setValue($data, 'length') ?? 1;
            $width = $this->setValue($data, 'width') ?? 1;
            $height = $this->setValue($data, 'height') ?? 1;
            $length_class_id = $this->setValue($data, 'length_class_id') ?? 1;
            $status = $this->setValue($data, 'status') ?? NEW_PRODUCT_STATUS;
            $tax_class_id = $this->setValue($data, 'tax_class_id') ?? TAX_CLASS_ID;
            $sort_order = $this->setValue($data, 'sort_order') ?? 1;
            $manufacturer_id = $this->setValue($data, 'manufacturer');
            $quantity = $this->setValue($data, 'quantity') ?? 0;


            $sql = "INSERT INTO " . DB_PREFIX . "product SET model = '" . $this->db->escape($data['model']) . "',
             sku = '" . $this->setValue($data, 'sku') . "',
             upc = '" . $this->setValue($data, 'upc') . "',
             ean = '" . $this->setValue($data, 'ean') . "',
             jan = '" . $this->setValue($data, 'jan') . "',
             isbn = '" . $this->setValue($data, 'isbn') . "',
             mpn = '" . $this->setValue($data, 'mpn') . "',
             location = '" . $this->setValue($data, 'location') . "',
             minimum = " . $minimum . ",
             subtract = " . $subtract . ",
             stock_status_id = " . $stock_status_id . ",
             shipping = " . $shipping . ",
             price = '" . (float)$data['price'] . "',
             points = " . $points . ",
             weight = " . $weight . ",
             weight_class_id = " . $weight_class_id . ",
             length = " . $length . ",
             width = " . $width . ",
             height = " . $height . ",
             length_class_id = " . $length_class_id . ",
             status = " . $status . ",
             tax_class_id = " . $tax_class_id . ",
             sort_order = " . $sort_order . ",
             date_modified = NOW(),
             date_added = NOW()";

            if($manufacturer_id)
                $sql .= ",manufacturer_id = " . $manufacturer_id;

            $sql .= ",quantity = " . $quantity;

            $this->db->query($sql);

            $product_id = $this->db->getLastId();

            if (isset($data['image'])) {
                $path = $this->copyImage($data['image']);
                $this->db->query("UPDATE " . DB_PREFIX . "product SET image = '" . $this->db->escape($path) . "' WHERE product_id = '" . (int)$product_id . "'");
            }

            if (isset($data['additional_images'])) {
                foreach ($data['additional_images'] as $order => $product_image_data) {
                    $path = $this->copyImage($product_image_data);
                    $this->db->query("INSERT INTO " . DB_PREFIX . "product_image SET product_id = '" . (int)$product_id . "', image = '" . $this->db->escape($path) . "', sort_order = '" . (int)$order . "'");
                }
            }

            foreach (AVAIL_LANGUAGES as $language_id) {
                $this->db->query("INSERT INTO " . DB_PREFIX . "product_description SET product_id = '" . (int)$product_id . "', language_id = '" . (int)$language_id . "', name = '" . $this->db->escape($data['name']) . "', description = '" . $this->db->escape($data['description']) . "', tag = '" . $this->db->escape($data['tags']) . "', meta_title = '" . $this->db->escape($data['name']) . "', meta_description = '" . strip_tags($this->db->escape($data['description'])) . "'");
            }

            $this->db->query("INSERT INTO " . DB_PREFIX . "product_to_store SET product_id = '" . (int)$product_id . "', store_id = '0'");

            if (isset($data['categories'])) {
                foreach ($data['categories'] as $category_id) {
                    $this->db->query("INSERT INTO " . DB_PREFIX . "product_to_category SET product_id = '" . (int)$product_id . "', category_id = '" . (int)$category_id . "'");
                }
            }

            $this->db->query("INSERT INTO " . DB_PREFIX . "product_to_layout SET product_id = '" . (int)$product_id . "', store_id = '0', layout_id = '0'");
        }
    }

    public function executeUpdateProducts()
    {
        foreach($this->update_products as $data) {

            $run = false;
            $sql = "UPDATE " . DB_PREFIX . "product SET
             date_modified = NOW()";

            if(isset($data['manufacturer_id'])) {
                $run = true;
                $sql .= ",manufacturer_id = " . $data['manufacturer_id'];
            }

            if(isset($data['quantity'])) {
                $run = true;
                $sql .= ",quantity = " . $data['quantity'];
            }

            if($run) {
                $sql .= ' WHERE product_id = '. $data['id'];
                $this->db->query($sql);
            }

            if(array_key_exists('name', $data) && array_key_exists('description', $data) ) {
                $this->db->query("DELETE FROM " . DB_PREFIX . "product_description WHERE product_id = '" . (int)$data['id'] . "'");
                foreach (AVAIL_LANGUAGES as $language_id) {
                    $this->db->query("INSERT INTO " . DB_PREFIX . "product_description SET product_id = '" . (int)$data['id'] . "', language_id = '" . (int)$language_id . "', name = '" . $this->db->escape($data['name']) . "', description = '" . $this->db->escape($data['description']) . "', meta_title = '" . $this->db->escape($data['name']) . "', meta_description = '" . strip_tags($this->db->escape($data['description'])) . "'");
                }
            }

            if (isset($data['image'])) {
                $path = $this->copyImage($data['image']);
                $this->db->query("UPDATE " . DB_PREFIX . "product SET image = '" . $this->db->escape($path) . "' WHERE product_id = '" . (int)$data['id'] . "'");
            }

            if (isset($data['additional_images'])) {
                $this->db->query("DELETE FROM " . DB_PREFIX . "product_image WHERE product_id = '" . (int)$data['id'] . "'");
                foreach ($data['additional_images'] as $order => $product_image_data) {
                    $path = $this->copyImage($product_image_data);
                    $this->db->query("INSERT INTO " . DB_PREFIX . "product_image SET product_id = '" . (int)$data['id'] . "', image = '" . $this->db->escape($path) . "', sort_order = '" . (int)$order . "'");
                }
            }
        }
    }

    public function prepareProducts()
    {
        $item = [];
        foreach($this->products as $key => $product) {

            if($product_info = $this->productExist($product)) {
                if(empty(UPDATE_FIELDS) || $product_info['product_id'] <= 20556)
                    continue;

                $action_fields = UPDATE_FIELDS;
                $item['id'] = $product_info['product_id'];
                $item['old_price'] = $product_info['price'];
//                $item['name'] = $product_info['name'];
//                $item['description'] = $product_info['description'];
//                $item['quantity'] = $product_info['quantity'];
                $action_key = 'update_products';
            }
            else {
                if(count(CREATE_FIELDS) == 0)
                    continue;

                $item['name'] = '';
                $item['description'] = '';
                $item['model'] = '';
                $item['tags'] = '';
                $item['price '] = 0.0;
                $action_fields = CREATE_FIELDS;
                $action_key = 'create_products';
            }

            foreach ($action_fields as $action_field) {
                if(!array_key_exists($action_field, $this->mapping))
                    dd('key '.$action_field .' does not exist in xml!');

                switch ($action_field){
                    case 'price':
                        $price = str_replace('€','', $product[$this->mapping[$action_field]]);
                        $price = str_replace(',','.', $price);
                        $item[$action_field] = $this->removeVAT(trim($price));
                        break;
                    case 'image':
                        $item[$action_field] = $this->prepareImage($product[$this->mapping[$action_field]]);
                        break;
                    case 'manufacturer':
                        $item[$action_field] = $this->prepareManufacturer($product[$this->mapping[$action_field]]);
                        break;
                    case 'additional_images':
                        $item[$action_field] = [];
                        foreach ($product[$action_field] as $image)
                            $item[$action_field][] = $this->prepareImage($image);
                        break;
                    case 'categories':
                        $item[$action_field] = array_unique($this->prepareCategories($product[$this->mapping[$action_field]]));
                        break;
                    case 'model':
                        $item[$action_field] = $product[$this->mapping[$action_field]];
                        break;
                    case 'quantity':
                        $quantity = null;
                        if(is_int(SET_QUANTITY))
                            $quantity = SET_QUANTITY;
                        elseif(is_string(SET_QUANTITY)) {
                            if(strpos(SET_QUANTITY, '|')) {
                                $q = explode('|', SET_QUANTITY);
                                $quantity = $q[1]; // after last pipe is something like else
                                for ($i = 0; $i < count($q) - 1; $i++) {
                                    $cond = explode(':', $q[$i]);
                                    $re = explode('=', $cond[1]);
                                    if ($product[$cond[0]] == $re[0]) {
                                        $quantity = $re[1];
                                        break;
                                    }
                                }
                            }
                            else{
                                $quantity = (int)$product[$this->mapping[$action_field]];
                            }
                        }
                        $item[$action_field] = (int)$quantity;
                        break;
                    default:
                        if(array_key_exists($this->mapping[$action_field], $product))
                            $item[$action_field] = $product[$this->mapping[$action_field]];
                }
            }

            $this->$action_key[$key] = $item;
        }
    }

    public function getXml()
    {
        return simplexml_load_file(ENDPOINT);
    }

    public function addProductToList($item){
        $product['additional_images'] = [];
        $foundCount = 0;
        foreach($item as $key => $value) {
            if (in_array($key, WHEN_KEYS_EXIST)) {
                $foundCount++;
            }
            // additional images
            if(array_key_exists('additional_images', $this->mapping) &&
                array_key_exists('type', $this->mapping['additional_images']) &&
                array_key_exists('value', $this->mapping['additional_images']) &&
                $this->mapping['additional_images']['type'] == 'prefix' &&
                strpos($key,$this->mapping['additional_images']['value']) !== false
            ){
                $product['additional_images'][] = (string)$value;
            }elseif(array_key_exists('additional_images', $this->mapping) &&
                array_key_exists('type', $this->mapping['additional_images']) &&
                array_key_exists('value', $this->mapping['additional_images']) &&
                $this->mapping['additional_images']['type'] == 'array' &&
                $key == $this->mapping['additional_images']['value']
            ){
                $product['additional_images'] = $value;
            }elseif(array_key_exists('additional_images', $this->mapping) &&
                array_key_exists('type', $this->mapping['additional_images']) &&
                array_key_exists('value', $this->mapping['additional_images']) &&
                $this->mapping['additional_images']['type'] == 'separation' &&
                $key == $this->mapping['additional_images']['value']
            ){
                $product['additional_images'] = explode($this->mapping['additional_images']['separator'], $value);
            }else{
                $product[$key] = trim((string)$value);
            }
        }

        if($foundCount != count(WHEN_KEYS_EXIST))
            return;

        $this->products[] = $product;
    }

    public function productExist($xml_product){
        $product = array_filter($this->existing_prods, function($product) use($xml_product){
            return $product[PRODUCT_IDENTIFIER['source']] == $xml_product[PRODUCT_IDENTIFIER['destination']];
        });

        if(count($product) > 0)
            return array_values($product)[0];
        else
            return false;
    }

    public function removeVAT($price){
        if(!is_numeric($price))
            $price = 0;
        $gross = $price;
        $nett = $gross/(1 + VAT / 100);
        return $nett;
    }

    public function addVat($price){
        if(!is_numeric($price))
            $price = 0;
        $gross = $price;
        $nett = $gross*(1 + VAT / 100);
        return round($nett, 2);
    }

    public function prepareImage($img){
        $img_path = str_replace(SOURCE_DIR, '', $img);
        $img_parts = explode('/', $img_path);
        $file_name = $img_parts[count($img_parts) - 1];
        array_pop($img_parts);
        $img_path = implode('/', $img_parts);
        return [$img, $img_path, $file_name];
    }

    /*
    public function prepareCategories($new_category_path){
        $new_category_path = PARENT_CAT . '|||' . str_replace(', ', '|||', $new_category_path);

        $category = array_filter($this->existing_cats, function($cat)use($new_category_path){
            return $cat['path_name'] == $new_category_path;
        });

        if(!empty($category))
            return array_values($category)[0]['category_id'];
        else{
            $new_category_path_names = explode('|||', $new_category_path);
            $parent_id = 0;
            $path_name = [];
            foreach ($new_category_path_names as $new_category_name) {
                $path_name[] = $new_category_name;
                $category = array_filter($this->existing_cats, function($cat)use($new_category_name, $parent_id, $path_name){
                    return $cat['path_name'] == implode('|||', $path_name) && $cat['parent_id'] == $parent_id;
                });

                if(!empty($category))
                    $parent_id = array_values($category)[0]['category_id'];
                else {
                    $prev_parent_id = $parent_id;
                    $parent_id = $this->createCategory($new_category_name, $parent_id);
                    $this->existing_cats[$parent_id] = [
                        'category_id' => $parent_id,
                        'parent_id' => $prev_parent_id,
                        'path_name' => implode('|||', $path_name),
                        'name' => $new_category_name
                    ];
                }
            }
            return $parent_id;
        }
    }
    */

    public function createParentCategory(){
        $query = $this->db->query("SELECT category_id FROM `" . DB_PREFIX . "category_description` WHERE name = '" . PARENT_CAT . "' ORDER BY category_id DESC LIMIT 1");
        if($query->row)
            $this->parent_id = $query->row['category_id'];
        else
            $this->parent_id = $this->createCategory(PARENT_CAT, 0);
    }

    public function prepareCategories($new_categories){
        $new_categories = explode(CAT_SEPARATOR, $new_categories);

        $categories = [];
        $parent_id = $this->parent_id;
        foreach ($new_categories as $new_category) {
            $new_category = trim($new_category);
            if(strpos($new_category, '[') !== false || $new_category == '')
                continue;

            $new_category_path = PARENT_CAT . '|||' . $new_category;

            $category = array_filter($this->existing_cats, function($cat)use($new_category_path){
                return $cat['path_name'] == $new_category_path;
            });

            if(!empty($category))
                $categories[] = array_values($category)[0]['category_id'];
            else{
                $new_cat_id = $this->createCategory($new_category, $parent_id);
                $parent_id = $new_cat_id;
                $this->create_cats[] = [$new_cat_id, $new_category_path];
                $this->existing_cats[$new_cat_id] = [
                    'category_id' => $new_cat_id,
                    'parent_id' => $parent_id,
                    'path_name' => $new_category_path,
                    'name' => $new_category
                ];
                $categories[] =  $new_cat_id;
            }
        }
        return [$categories[count($categories)-1]];
    }

    public function prepareManufacturer($manufacturer){
        if(is_null($manufacturer))
            return null;

        $manufacturer = trim($manufacturer);
        foreach ($this->existing_manufs as $existing_manuf) {
            if( strtolower($existing_manuf['name']) == strtolower($manufacturer)) {
                return $existing_manuf['manufacturer_id'];
            }
        }
        $manufacturer_id = $this->createManufacturer($manufacturer);
        $this->existing_manufs[] = [
            'manufacturer_id' => $manufacturer_id,
            'name' => $manufacturer
        ];
        return $manufacturer_id;
    }

    public function createManufacturer($manufacturer){
        $this->db->query("INSERT INTO " . DB_PREFIX . "manufacturer SET name = '" . $manufacturer . "'");
        $manufacturer_id = $this->db->getLastId();
        $this->db->query("INSERT INTO " . DB_PREFIX . "manufacturer_to_store SET manufacturer_id = '" . (int)$manufacturer_id . "', store_id = '0'");
        return $manufacturer_id;
    }

    public function createCategory($category_name, $parent_id){
        $this->db->query("INSERT INTO " . DB_PREFIX . "category SET parent_id = '" . (int)$parent_id . "', `top` = 0, `column` = 1, sort_order = '', status = '1', date_modified = NOW(), date_added = NOW()");

        $category_id = $this->db->getLastId();

        foreach (AVAIL_LANGUAGES as $language_id) {
            $this->db->query("INSERT INTO " . DB_PREFIX . "category_description SET category_id = '" . (int)$category_id . "', language_id = '" . (int)$language_id . "', name = '" . $this->db->escape($category_name) . "', description = '" . $category_name . "', meta_title = '" . $category_name . "', meta_description = '" . $category_name . "', meta_keyword = '" . $category_name . "'");
        }

        // MySQL Hierarchical Data Closure Table Pattern
        $level = 0;

        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "category_path` WHERE category_id = '" . (int)$parent_id . "' ORDER BY `level` ASC");

        foreach ($query->rows as $result) {
            $this->db->query("INSERT INTO `" . DB_PREFIX . "category_path` SET `category_id` = '" . (int)$category_id . "', `path_id` = '" . (int)$result['path_id'] . "', `level` = '" . (int)$level . "'");
            $level++;
        }

        $this->db->query("INSERT INTO `" . DB_PREFIX . "category_path` SET `category_id` = '" . (int)$category_id . "', `path_id` = '" . (int)$category_id . "', `level` = '" . (int)$level . "'");

        $this->db->query("INSERT INTO " . DB_PREFIX . "category_to_store SET category_id = '" . (int)$category_id . "', store_id = '0'");

        $this->db->query("INSERT INTO " . DB_PREFIX . "category_to_layout SET category_id = '" . (int)$category_id . "', store_id = '0', layout_id = '0'");

        return $category_id;
    }

    public function getCategories()
    {
        $query = $this->db->query("SELECT cp.category_id AS category_id, GROUP_CONCAT(cd1.name ORDER BY cp.level SEPARATOR '|||') AS path_name, cd1.name AS name, c1.parent_id, c1.sort_order FROM " . DB_PREFIX . "category_path cp LEFT JOIN " . DB_PREFIX . "category c1 ON (cp.category_id = c1.category_id) LEFT JOIN " . DB_PREFIX . "category c2 ON (cp.path_id = c2.category_id) LEFT JOIN " . DB_PREFIX . "category_description cd1 ON (cp.path_id = cd1.category_id) LEFT JOIN " . DB_PREFIX . "category_description cd2 ON (cp.category_id = cd2.category_id) WHERE cd1.language_id = ".$this->language_id." AND cd2.language_id = ".$this->language_id." GROUP BY cp.category_id ORDER BY name ASC");

        $existing_cats = [];
        foreach ($query->rows as $row)
            $existing_cats[$row['category_id']] = $row;

        $this->existing_cats = $existing_cats;
    }

    public function getProducts()
    {
        $query = $this->db->query("
        SELECT 
            p.product_id AS product_id, 
            pd.name AS name,
            pd.description AS description,
            p.price,
            p.model,
            p.sku,
            p.mpn,
            p.model,
            p.quantity,
            m.name AS manufacturer_name,
            m.manufacturer_id
        FROM " . DB_PREFIX . "product p
        LEFT JOIN " . DB_PREFIX . "product_description pd
            ON pd.product_id = p.product_id
        LEFT JOIN " . DB_PREFIX . "manufacturer m
            ON m.manufacturer_id = p.manufacturer_id
        WHERE pd.language_id = ".$this->language_id." ORDER BY name ASC");

        $this->existing_prods = $query->rows;

    }

    public function getManufacturers()
    {
        $query = $this->db->query("
        SELECT 
            manufacturer_id,
            name
        FROM " . DB_PREFIX . "manufacturer");

        $existing_manufs = [];
        foreach ($query->rows as $row)
            $existing_manufs[$row['manufacturer_id']] = $row;

        $this->existing_manufs = $existing_manufs;
    }

    public function test()
    {
        return count($this->products);
        $cat = [];
        foreach($this->products as $product) {
            $tmp = explode(',', $product['categories']);
            $cat[] = $tmp[count($tmp) - 1];
        }
        sort($cat);
        return array_unique($cat);
    }

    private function dbConnect()
    {
        $this->db = new DB(DB_DRIVER, DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, DB_PORT);
    }

    public function generateReport(){
        $myfile = fopen("xml_import_".time().".txt", "w") or die("Unable to open file!");

        $txt = 'Created: ' . count($this->create_cats) . ' categories.'. "\n";
        $txt .= 'Created: ' . count($this->create_products) . ' products.'. "\n";
        $txt .= 'Updated: ' . count($this->update_products) . ' products.'. "\n\n";

        $txt .= "NEW CATEGORIES\n";
        $txt .= "id - name\n";
        fwrite($myfile, $txt);
        foreach ($this->create_cats as $create_cat) {
            $txt = $create_cat[0] . " - " . $create_cat[1] . "\n";
            fwrite($myfile, $txt);
        }
        $txt = "\nNEW PRODUCTS\n";
        $txt .= "name - category_ids\n";
        fwrite($myfile, $txt);
        foreach ($this->create_products as $create_product) {
            $txt = $create_product['name'] . " - " . implode(', ', $create_product['categories']) . "\n";
            fwrite($myfile, $txt);
        }
        $txt = "\nUPDATE PRODUCTS\n";
        $txt .= "name - old price => new price\n";
        fwrite($myfile, $txt);
        foreach ($this->update_products as $update_product) {
            if(array_key_exists('price', $update_product))
                $txt = $update_product['id'] . " - " . $this->addVat($update_product['old_price']) . " => " . $this->addVat($update_product['price']) . "\n";
            else
                $txt = $update_product['id'];
            fwrite($myfile, $txt);
        }
        fclose($myfile);
    }

    private function getMapping(){
        $this->mapping = DEFAULT_MAPPING;
    }
}

$xml_importer = new xmlImporter();
$xml = $xml_importer->getXml();

$segments = explode('.', PATH_TO_PROD);

// Traverse through each segment to access the desired element
$currentElement = $xml;
foreach ($segments as $segment) {
    if (isset($currentElement->$segment)) {
        $currentElement = $currentElement->$segment;
    }
    else
        dd($segment . ' does not exist in xml path!');
}
$f = 0;
foreach ($currentElement as $item){
    $f++;
    if($f>=1150)break;
    if($f<1050)continue;
    $xml_importer->addProductToList($item);
}
//dd($xml_importer->products);
$xml_importer->createParentCategory();
$xml_importer->prepareProducts();
$xml_importer->executeCreateProducts();
$xml_importer->executeUpdateProducts();
//$xml_importer->generateReport();
echo 'Created: ' . count($xml_importer->create_cats) . ' categories.';
echo 'Created: ' . count($xml_importer->create_products) . ' products.';
echo 'Updated: ' . count($xml_importer->update_products) . ' products.';
