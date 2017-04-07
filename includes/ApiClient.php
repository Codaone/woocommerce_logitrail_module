<?php

namespace Logitrail\Lib;

class ApiClient {
    private $merchantId;
    private $secretKey;

    private $orderId;
    private $firstName;
    private $lastName;
    private $address;
    private $postalCode;
    private $city;
    private $email;
    private $phone;
    private $companyName;
    private $countryCode;
    private $products = array();

    private $responseAsRaw = FALSE;

    private $testCheckoutUrl = 'http://checkout.test.logitrail.com/go';
    private $testApiUrl = 'http://api-1.test.logitrail.com/2015-01-01/';

    private $prodCheckoutUrl = 'https://checkout.logitrail.com/go';
    private $prodApiUrl = 'https://api-1.logitrail.com/2015-01-01/';

    private $checkoutUrl = 'http://checkout.logitrail.com/go';
    private $apiUrl = 'http://api-1.logitrail.com/2015-01-01/';

    /**
     * Use test or production Logitrail server
     * Default is production
     *
     * @param bool $useTest
     */
    public function useTest($useTest) {
        if ($useTest === TRUE) {
            $this->checkoutUrl = $this->testCheckoutUrl;
            $this->apiUrl      = $this->testApiUrl;
        }
        else {
            $this->checkoutUrl = $this->prodCheckoutUrl;
            $this->apiUrl      = $this->prodApiUrl;
        }
    }

    /**
     * Return Logitrail responses raw as gotten or converted to array
     * (Doesn't always return JSON in error cases, so converted responses may vary)
     * Default false (returns converted array)
     *
     * @param bool $responseAsRaw
     */
    public function setResponseAsRaw($responseAsRaw) {
        $this->responseAsRaw = $responseAsRaw;
    }

    /**
     * Add a product to data sent to Logitrail
     *
     * @param string   $id     Merchant's product id
     * @param string   $name   Product name
     * @param int      $amount How many pieces of product is ordered
     * @param int      $weight Product weight in grams
     * @param float    $price  Price of one item of the product, including taxes
     * @param float    $taxPct Tax percentage
     * @param bool     $barcode
     * @param bool|int $width  Product width in millimeters (Needed in product creation only)
     * @param bool|int $height Product height in millimeters (Needed in product creation only)
     * @param bool|int $length Product length in millimeters (Needed in product creation only)
     * @internal param string $ean Product barcode (GTIN/EAN) (Needed in product creation only)
     */
    public function addProduct($id, $name, $amount, $weight, $price, $taxPct, $barcode = FALSE, $width = FALSE, $height = FALSE, $length = FALSE) {
        $this->products[] = array(
            'id'      => $id,
            'name'    => $name,
            'barcode' => $barcode,
            'amount'  => $amount,
            'weight'  => $weight,
            'width'   => $width,
            'height'  => $height,
            'length'  => $length,
            'price'   => $price,
            'taxPct'  => $taxPct
        );
    }

    /**
     * Set Merchant ID used in communication with Logitrail
     *
     * @param string $merchantId
     */
    public function setMerchantId($merchantId) {
        $this->merchantId = $merchantId;
    }

    /**
     * Set secret key used in communication with Logitrail
     *
     * @param string $secretKey
     */
    public function setSecretKey($secretKey) {
        $this->secretKey = $secretKey;
    }

    /**
     * Set merchant's order id, which will be visible in Logitrail's system
     *
     * @param int $orderId
     */
    public function setOrderId($orderId) {
        $this->orderId = $orderId;
    }

    /**
     * Set customer information and delivery address of the order
     *
     * @param string $firstname
     * @param string $lastname
     * @param string $phone
     * @param string $email
     * @param string $address
     * @param string $postalCode
     * @param string $city
     * @param string $companyName
     * @param string $countryCode ISO 3166 standard see https://en.wikipedia.org/wiki/ISO_3166
     */
    public function setCustomerInfo($firstname, $lastname, $phone, $email, $address, $postalCode, $city, $companyName, $countryCode = 'FI') {
        $this->firstName   = $firstname;
        $this->lastName    = $lastname;
        $this->address     = $address;
        $this->postalCode  = $postalCode;
        $this->city        = $city;
        $this->phone       = $phone;
        $this->email       = $email;
        $this->companyName = $companyName;
        $this->countryCode = $countryCode;
    }

    /**
     * Returns a html form with provided data that will be automatically posted
     * to Logitrail and which starts the delivery method selection process
     *
     * @param string $lang
     * @return string
     */
    public function getForm($lang = 'fi', $fields = array()) {
        // TODO: Check that all mandatory values are set
        $post = array();

        $post['merchant']         = $this->merchantId;
        $post['request']          = 'new_order';
        $post['order_id']         = $this->orderId; // Merchant's own ID for the order.
        $post['customer_fn']      = $this->firstName;
        $post['customer_ln']      = $this->lastName;
        $post['customer_addr']    = $this->address;
        $post['customer_pc']      = $this->postalCode;
        $post['customer_city']    = $this->city;
        $post['customer_country'] = $this->countryCode;
        $post['customer_email']   = $this->email;
        $post['customer_phone']   = $this->phone;
        $post['language']         = $lang;

        foreach ($fields as $field => $value) {
            $post[$field] = $value;
        }

        // add products to post data
        foreach ($this->products as $id => $product) {
            $post['products_' . $id . '_id']     = $product['id'];
            $post['products_' . $id . '_name']   = $product['name'];
            $post['products_' . $id . '_amount'] = $product['amount'];
            $post['products_' . $id . '_weight'] = $product['weight'];
            $post['products_' . $id . '_price']  = $product['price'];
            $post['products_' . $id . '_tax']    = $product['taxPct'];
            $post['products_' . $id . '_gtin']   = $product['barcode'];
        }

        $mac         = $this->calculateMac($post, $this->secretKey);
        $post['mac'] = $mac;

        $form = '<form id="form" method="post" action="' . $this->checkoutUrl . '">';

        foreach ($post as $name => $value) {
            $form .= '<input type="hidden" name="' . $name . '" value="' . $value . '" />';
        }

        $form .= '</form>';
        $form .= "<script>document.getElementById('form').submit();</script>";

        return $form;
    }

    /**
     * Updates data for order already in Logitrail's system.
     *
     * @param string $logitrailOrderId
     * @return array The response returned by Logitrail
     */
    public function updateOrder($logitrailOrderId) {
        // TODO: Check that all mandatory values are given
        // TODO: currently doesn't support updating products for the order
        $orderData = array(
            'merchants_order' => $this->orderId,
            'customer'        => array(
                'firstName'        => $this->firstName,
                'lastName'         => $this->lastName,
                'email'            => $this->email,
                'phoneNumber'      => $this->phone,
                'address'          => $this->address,
                'city'             => $this->city,
                'postalCode'       => $this->postalCode,
                'organizationName' => $this->companyName,
                'countryCode'      => $this->countryCode
            )
        );

        return $this->doPost($this->apiUrl . 'orders/' . $logitrailOrderId, $orderData);
    }

    /**
     * Confirm a passive order reported earlier for delivery
     *
     * @param string $logitrailOrderId
     * @return array The response returned by Logitrail
     */
    public function confirmOrder($logitrailOrderId) {
        return $this->doPost($this->apiUrl . 'orders/' . $logitrailOrderId . '/_confirm');
    }

    /**
     * Create products to Logitrail's system.
     * Creates all products that were added with addProduct method and returns
     * array of responses from Logitrail.
     *
     * @return array Responses from Logitrail for each added product
     */
    public function createProducts() {
        // TODO: add support for subproducts
        $results = array();

        foreach ($this->products as $id => $product) {
            $productData = array(
                'merchants_id' => $product['id'],
                'name'         => $product['name'],
                'gtin'         => $product['barcode'],
                'weight'       => $product['weight'],
                'dimensions'   => array(
                    $product['width'],
                    $product['height'],
                    $product['length']
                )
            );

            $results[] = $this->doPost($this->apiUrl . 'products/', $productData);
        }

        return $results;
    }

    /**
     * Update products to Logitrail's system.
     * Updatess all products that were added with addProduct method and returns
     * array of responses from Logitrail.
     *
     * Products are matched for updating by id.
     *
     * @return array Responses from Logitrail for each added product
     */
    public function updateProducts() {
        // Convenience function to keep method naming logical
        // Create and update go to same endpoint in Logitrail and work the same way

        return $this->createProducts();
    }

    /**
     * Remove products from the instance
     */
    public function clearProducts() {
        $this->products = array();
    }

    /**
     * Remove customer info from the instance
     */
    public function clearCustomerInfo() {
        $this->firstName   = NULL;
        $this->lastName    = NULL;
        $this->address     = NULL;
        $this->postalCode  = NULL;
        $this->city        = NULL;
        $this->phone       = NULL;
        $this->email       = NULL;
        $this->companyName = NULL;
        $this->countryCode = NULL;
    }

    /**
     * Remove order id from instance
     */
    public function clearOrderId() {
        $this->orderId = NULL;
    }

    /**
     * Remove customer info, order id and products from the instance
     */
    public function clearAll() {
        $this->clearCustomerInfo();
        $this->clearOrderId();
        $this->clearProducts();
    }

    /**
     * Does a post call to Logireail's system to given endpoint with optional payload
     *
     * @param string     $url  URL of the endpoint to post to
     * @param array $data Data sent as JSON payload
     * @return array The response returned by Logitrail
     * @throws \Exception
     */
    private function doPost($url, array $data = array()) {
        if (!$this->merchantId || !$this->secretKey) {
            throw new \Exception('Missing merchant id or secret key');
        }

        $auth = 'M-' . $this->merchantId . ':' . $this->secretKey;
        $ch   = curl_init($url);

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $auth);

        if ($data) {
            $postData = json_encode($data);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($postData)
                )
            );

            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        }

        $response = curl_exec($ch);

        curl_close($ch);

        return ($this->responseAsRaw ? $response : json_decode($response, TRUE));
    }

    /**
     * Calculates the mac from order data to validate order content
     * at Logitrail's end
     *
     * @param array  $requestValues
     * @param string $secretKey
     * @return string
     */
    private function calculateMac($requestValues, $secretKey) {
        ksort($requestValues);

        $macValues = [];
        foreach ($requestValues as $key => $value) {
            if ($key === 'mac') {
                continue;
            }
            $macValues[] = $value;
        }

        $macValues[] = $secretKey;

        $macSource = join('|', $macValues);

        $correctMac = base64_encode(hash('sha512', $macSource, TRUE));

        return $correctMac;
    }

    /**
     * Processes json string and returns an associative array, returns empty array on json parse error
     * @param string $json returned by logitrail webhook
     * @return array
     */
    public function processWebhookData($json) {
        $parsed  = array();
        $decoded = json_decode($json, TRUE);
        if ($decoded) {
            $parsed['event_id']    = $decoded['event_id'];
            $parsed['webhook_id']  = $decoded['webhook_id'];
            $parsed['event_type']  = $decoded['event_type'];
            $parsed['ts']          = strtotime($decoded['ts']);
            $parsed['retry_count'] = (int) $decoded['retry_count'];
            $parsed['payload']     = $decoded['payload'];
        }
        return $parsed;
    }
}