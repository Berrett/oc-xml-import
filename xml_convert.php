<?php
error_reporting(E_ALL);
ini_set("display_errors", 1);

const WHEN_KEYS_EXIST = ['NAME', 'CODE'];
const NEW_PRODUCT_STATUS = 0;
const VAT = 24;

function dd($vars): void
{
    echo '<pre>';
    print_r($vars);
    echo '</pre>';
    exit(1);
}

function removeVat($price){
    $price = str_replace('€','', $price);
    $price = str_replace(',','.', $price);
    if(!is_numeric($price))
        $price = 0;
    $gross = $price;
    $nett = $gross/(1 + VAT / 100);
    return $nett;
}

$_data = [
    'parent_cat' => "palaiologos",
    'image_path' => "palaiologos",
    'cat_separator' => '>',
    'tax_class_id' => 9,
    'stock_status_id' => 9,
    'weight_class_id' => 1,
    'identifier' => 'model',
    'to_create' => json_encode([
        'name',
        'description',
        'quantity',
        'price',
        'model',
        'manufacturer',
        'image',
        'additional_images',
    ]),
    'to_update' => json_encode([
        'image',
        'additional_images',
        'quantity',
    ]),
    'languages' => json_encode([
        '2' => 'el'
    ])
];


//$xml = simplexml_load_file('https://palaiologos.com/pegasus_xmls/products_0.xml');
$xml = simplexml_load_file('xml_import/palaiologos.xml');

$data = [];

$f = 0;
foreach ($xml->PRODUCT as $item){
    if($f > 4 ) break;
    $foundCount = 0;
    foreach($item as $key => $value)
        if (in_array($key, WHEN_KEYS_EXIST))
            $foundCount++;
    if(!$foundCount) continue;
    $data[$f] = [
        'uuid' => trim((string)$item->CODE), // detect existing products
        'name' => [
            'el' => trim((string)$item->NAME),
        ],
        'description' => [
            'el' => trim((string)$item->TEXT),
        ],
        'price' => removeVat(trim((string)$item->TIMH_LIANIKIS)),
        'status' => NEW_PRODUCT_STATUS,
        'manufacturer' => trim((string)$item->MANUFACTURER_DESC),
        'model' => trim((string)$item->CODE),
        'mpn' => trim((string)$item->CODE),
        'quantity' => trim((string)$item->AVAILABLE_QUANTITY),
        'availability' => trim((string)$item->NAME),
    ];

    @$image = trim((string)$item->PHOTOS->PHOTO_0->URL);
    if($image != '') {
        $tmp_prefix = str_replace('-','',$data[$f]['uuid']);
        $tmp = explode('&type=', explode('i02nr01=', $image)[1])[0];
        $data[$f]['image'] = [
            'name' => $tmp_prefix.'/'.$tmp.'.png',
            'url' => trim((string)$item->PHOTOS->PHOTO_0->URL)
        ];
    }

    for ($i = 1; $i <= 90; $i++){
        $img_key = 'PHOTO_'.$i;
        @$img = trim((string)$item->PHOTOS->{$img_key}->URL);
        if($img=='') break;

        $tmp = explode('&type=', explode('i02nr01=', $img)[1])[0];
        $data[$f]['additional_images'][strtolower($img_key)] = [
            'name' => $tmp_prefix.'/'.$tmp.'.png',
            'url' => $img
        ];
    }

    $f++;
}

$_data['products'] = $data;

// Create a new SimpleXMLElement
$xml = new SimpleXMLElement('<data/>');

// Function to convert array to XML elements
function arrayToXml($data, &$xml)
{
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            // Handle nested elements
            if (is_numeric($key)) {
                // Handle multiple books
                $subNode = $xml->addChild('product');
            } else {
                $subNode = $xml->addChild($key);
            }
            arrayToXml($value, $subNode);
        } else {
            // Handle simple values
            if (strpos($value, '<![CDATA[') === 0 && strrpos($value, ']]>') === (strlen($value) - 3)) {
                // Value is already wrapped in CDATA section, no need to encode
                $xml->addChild($key, $value);
            } else {
                $xml->addChild($key, htmlspecialchars($value));
            }
        }
    }
}

// Convert the array to XML elements
arrayToXml($_data, $xml);

// Set the content type to XML
header('Content-type: text/xml');

// Output the XML
echo $xml->asXML();
?>
