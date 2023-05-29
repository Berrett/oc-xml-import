<?php
error_reporting(E_ALL);
ini_set("display_errors", 1);

require_once('config.php');

const ENDPOINT = 'http://localhost/xml_convert.php';
const PATH_TO_PROD = 'products.product'; // means whatever->products->product

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

        $this->parent_cat = null;
        $this->image_path = null;
        $this->image_dir = null;
        $this->cat_separator = null;
        $this->tax_class_id = null;
        $this->stock_status_id = null;
        $this->weight_class_id = null;
        $this->identifier = null;
        $this->languages = [];
        $this->available_languages = [];
        $this->language_id = 1;
        $this->to_create = [];
        $this->to_update = [];

        $this->products = [];
        $this->create_products = [];
        $this->update_products = [];
        $this->create_cats = [];

        $this->existing_cats = [];
        $this->existing_prods = [];
        $this->existing_manufs = [];

        $this->dbConnect();
    }

    public function copyImage($img_data){
        if(!is_dir($this->image_dir))
            mkdir($this->image_dir);

        [$img_url, $img_path, $file_name] = $img_data;
        if(!is_dir($this->image_dir . '/' . $img_path))
            mkdir($this->image_dir . '/' . $img_path, 0775, true);

        if(strpos($img_url, 'http') === false)
            $img_url = 'https://'.$img_url;

        if(!file_exists($this->image_dir . '/' . $img_path . '/' . $file_name))
            copy($img_url, $this->image_dir . '/' . $img_path . '/' . $file_name);

        return $this->image_path . '/' . $img_path . '/' . $file_name;

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
            $stock_status_id = (int)($this->setValue($data, 'stock_status_id') ?? $this->stock_status_id);
            $weight_class_id = (int)($this->setValue($data, 'weight_class_id') ?? $this->weight_class_id);
            $minimum = $this->setValue($data, 'minimum') ?? 1;
            $subtract = $this->setValue($data, 'subtract') ?? 1;
            $shipping = $this->setValue($data, 'shipping') ?? 0;
            $points = $this->setValue($data, 'points') ?? 1;
            $weight = $this->setValue($data, 'weight') ?? 1;
            $length = $this->setValue($data, 'length') ?? 1;
            $width = $this->setValue($data, 'width') ?? 1;
            $height = $this->setValue($data, 'height') ?? 1;
            $length_class_id = $this->setValue($data, 'length_class_id') ?? 1;
            $status = $this->setValue($data, 'status') ?? 0;
            $tax_class_id = $this->setValue($data, 'tax_class_id') ?? $this->tax_class_id;
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

            foreach ($this->available_languages as $language_id) {
                $this->db->query("INSERT INTO " . DB_PREFIX . "product_description SET product_id = '" . (int)$product_id . "', language_id = '" . (int)$language_id . "', name = '" . $this->db->escape($data['name'][$language_id]) . "', description = '" . $this->db->escape($data['description'][$language_id]) . "', tag = '" . $this->db->escape($data['tags'][$language_id]) . "', meta_title = '" . $this->db->escape($data['name'][$language_id]) . "', meta_description = '" . strip_tags($this->db->escape($data['description'][$language_id])) . "'");
            }

            $this->db->query("INSERT INTO " . DB_PREFIX . "product_to_store SET product_id = '" . (int)$product_id . "', store_id = '0'");

            if (isset($data['categories'])) {
                foreach ($data['categories'] as $category_id) {
                    $this->db->query("INSERT INTO " . DB_PREFIX . "product_to_category SET product_id = '" . (int)$product_id . "', category_id = '" . (int)$category_id . "'");
                }
            }else{
                $this->db->query("INSERT INTO " . DB_PREFIX . "product_to_category SET product_id = '" . (int)$product_id . "', category_id = '" . (int)$this->parent_id . "'");
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
                foreach ($this->available_languages as $language_id) {
                    $this->db->query("INSERT INTO " . DB_PREFIX . "product_description SET product_id = '" . (int)$data['id'] . "', language_id = '" . (int)$language_id . "', name = '" . $this->db->escape($data['name'][$language_id]) . "', description = '" . $this->db->escape($data['description'][$language_id]) . "', tag = '" . $this->db->escape($data['tags'][$language_id]) . "', meta_title = '" . $this->db->escape($data['name'][$language_id]) . "', meta_description = '" . strip_tags($this->db->escape($data['description'][$language_id])) . "'");
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
                if(count($this->to_update) == 0)
                    continue;
                $action_fields = $this->to_update;
                $item['id'] = $product_info['product_id'];
                $item['old_price'] = $product_info['price'];
                $action_key = 'update_products';
            }
            else {
                if(count($this->to_create) == 0 || $product['name'][$this->language_id] == '')
                    continue;

                $item['description'] = [];
                $item['tags'] = [];
                foreach ($this->available_languages as $language) {
                    $item['description'][$language] = '';
                    $item['tags'][$language] = '';

                }
                $item['model'] = '';
                $item['price '] = 0.0;
                $action_fields = $this->to_create;
                $action_key = 'create_products';
            }

            foreach ($action_fields as $action_field) {
                if(!array_key_exists($action_field, $product))
                    continue;

                switch ($action_field){
                    case 'image':
                        $item[$action_field] = $this->prepareImage($product[$action_field]);
                        break;
                    case 'manufacturer':
                        $item[$action_field] = $this->prepareManufacturer($product[$action_field]);
                        break;
                    case 'additional_images':
                        $item[$action_field] = [];
                        foreach ($product[$action_field] as $image) {
                            $item[$action_field][] = $this->prepareImage($image);
                        }
                        break;
                    case 'categories':
                        $item[$action_field] = array_unique($this->prepareCategories($product[$action_field]));
                        break;
                    default:
                        $item[$action_field] = $product[$action_field];
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
        foreach($item as $key => $value) {
            if('image' === $key) {
                $product[$key] = (array)$value;
            }elseif('additional_images' === $key) {
                $product[$key] = [];
                foreach (array_values((array)$value) as $val) {
                    $product[$key][] = [
                        'name' => trim((string)$val->name),
                        'url' => trim((string)$val->url),
                    ];
                }
            }elseif('name' === $key || 'description' === $key) {
                $product[$key] = [];
                $value = (array)$value;
                foreach ($this->languages as $language_id => $language) {
                    $product[$key][$language_id] = trim((string)$value[$language]);
                }
            }else{
                $product[$key] = trim((string)$value);
            }
        }
        $this->products[] = $product;
    }

    public function productExist($xml_product){
        $product = array_filter($this->existing_prods, function($product) use($xml_product){
            return $product[$this->identifier] == $xml_product['uuid'];
        });

        if(count($product) > 0)
            return array_values($product)[0];
        else
            return false;
    }

    public function prepareImage($img){
        $img_parts = explode('/', $img['name']);
        $file_name = $img_parts[count($img_parts) - 1];
        array_pop($img_parts);
        $img_path = implode('/', $img_parts);
        return [$img["url"], $img_path, $file_name];
    }

    public function createParentCategory(){
        $query = $this->db->query("SELECT category_id FROM `" . DB_PREFIX . "category_description` WHERE name = '" . $this->parent_cat . "' ORDER BY category_id DESC LIMIT 1");
        if($query->row)
            $this->parent_id = $query->row['category_id'];
        else
            $this->parent_id = $this->createCategory($this->parent_cat, 0);
    }

    public function prepareCategories($new_categories){
        $new_categories = explode($this->cat_separator, $new_categories);

        $categories = [];
        $parent_id = $this->parent_id;
        foreach ($new_categories as $new_category) {
            $new_category = trim($new_category);
            if(strpos($new_category, '[') !== false || $new_category == '')
                continue;

            $new_category_path = $this->parent_cat . '|||' . $new_category;

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

        foreach ($this->available_languages as $language_id) {
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
            $txt = $update_product['id'] . "\n";
            fwrite($myfile, $txt);
        }
        fclose($myfile);
    }
}

$xml_importer = new xmlImporter();
$xml = $xml_importer->getXml();
$xml_importer->parent_cat = trim((string)$xml->parent_cat);
$xml_importer->image_path = trim((string)$xml->image_path);
$xml_importer->image_dir = DIR_IMAGE . trim((string)$xml_importer->image_path);
$xml_importer->cat_separator = trim((string)$xml->cat_separator);
$xml_importer->tax_class_id = trim((int)$xml->tax_class_id);
$xml_importer->stock_status_id = trim((int)$xml->stock_status_id);
$xml_importer->weight_class_id = trim((int)$xml->weight_class_id);
$xml_importer->identifier = trim((string)$xml->identifier);
$xml_importer->to_create = json_decode(trim((string)$xml->to_create), true);
$xml_importer->to_update = json_decode(trim((string)$xml->to_update), true);
$xml_importer->languages = json_decode(trim((string)$xml->languages), true);
$xml_importer->available_languages = array_keys($xml_importer->languages);
$xml_importer->language_id = array_key_first($xml_importer->languages);

$xml_importer->getCategories();
$xml_importer->getProducts();
$xml_importer->getManufacturers();

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
//    if($f>=1150)break;
//    if($f<1050)continue;
    $xml_importer->addProductToList($item);
}
$xml_importer->createParentCategory();
$xml_importer->prepareProducts();
$xml_importer->executeCreateProducts();
$xml_importer->executeUpdateProducts();
//$xml_importer->generateReport();
echo 'Created: ' . count($xml_importer->create_cats) . ' categories.';
echo 'Created: ' . count($xml_importer->create_products) . ' products.';
echo 'Updated: ' . count($xml_importer->update_products) . ' products.';
