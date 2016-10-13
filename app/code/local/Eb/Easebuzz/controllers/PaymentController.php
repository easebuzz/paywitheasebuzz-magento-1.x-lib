<?php



class Eb_Easebuzz_PaymentController extends Mage_Core_Controller_Front_Action {

 
    public function redirectAction() {
        $order = Mage::getModel('sales/order')->load(Mage::getSingleton('checkout/session')->getLastOrderId());
        $orderId = Mage::getSingleton('checkout/session')->getLastRealOrderId();
        $order->loadByIncrementId($orderId);
        $amount = $order->getBaseGrandTotal();
        //If they have no customer id, they're a guest.
        if ($order->getCustomerId() === NULL) {
            $fname = $order->getBillingAddress()->getFirstname();
            $lanme = $order->getBillingAddress()->getLastname();
            $phno = $order->getBillingAddress()->getTelephone();
            $email = $order->getBillingAddress()->getEmail();
        } else {
            //else, they're a normal registered user.
            $customer = Mage::getModel('customer/customer')->load($order->getCustomerId());
            $fname = $customer->getDefaultBillingAddress()->getFirstname();
            $lanme = $customer->getDefaultBillingAddress()->getLastname();
            $phno = $customer->getDefaultBillingAddress()->getTelephone();
            $email = $customer->getEmail();
        }
        $mkey = Mage::getStoreConfig('payment/easebuzz/mkey');
        $msalt = Mage::getStoreConfig('payment/easebuzz/msalt');
        $mode = Mage::getStoreConfig('payment/easebuzz/payment_mode');
        if ($mode == "0") {
            $env = "test";
        } else {
            $env = "prod";
        }
        $url = Mage::getUrl("easebuzz/payment/response");
        echo "<br/>";
        $result = $this->easepay_page(array('key' => $mkey,
            'txnid' => $orderId,
            'amount' => $amount,
            'firstname' => $fname,
            'email' => $email,
            'phone' => $phno,
            'productinfo' => $orderId,
            "udf1"=> $orderId,
            'surl' => $url,
            'furl' => $url), $msalt, $env);
        $this->loadLayout();
        $block = $this->getLayout()->createBlock('Mage_Core_Block_Template', 'easebuzz', array('template' => 'easebuzz/redirect.phtml'))->setData('result', $result);
        $this->getLayout()->getBlock('content')->append($block);
        $this->renderLayout();
    }

    // The response action is triggered when the gateway sends back a response after processing the customer's payment
    public function responseAction() {
        if ($this->getRequest()->isPost()) {
            if ($this->getRequest()->getPost("status") == "success" && $this->getRequest()->getPost("udf1")) {
                $orderId = $this->getRequest()->getPost("udf1");
                $order = Mage::getModel('sales/order')->loadByIncrementId($orderId);
                $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true, 'Payment Success.');
                $order->save();

                Mage::getSingleton('checkout/session')->unsQuoteId();
                Mage_Core_Controller_Varien_Action::_redirect('checkout/onepage/success', array('_secure' => false));
            } else {
                 $orderId = $this->getRequest()->getPost("udf1");
		               $cart = Mage::getSingleton('checkout/cart');
                 $order = Mage::getModel('sales/order')->loadByIncrementId($orderId);
                 $items = $order->getItemsCollection();
                 foreach ($items as $item) {
                    try {
                        $cart->addOrderItem($item);
                    } catch (Mage_Core_Exception $e) {
                        $session->addError($this->__($e->getMessage()));
                        Mage::logException($e);
                        continue;
                    }
                }
                $cart->save();
                Mage::getSingleton('core/session')->addError('Your payment failed. Please try again later');
                $this->_redirect('checkout/cart');   
            }
        } else {
            Mage_Core_Controller_Varien_Action::_redirect('checkout/onepage/error', array('_secure' => false));
        }

    }

    // The cancel action is triggered when an order is to be cancelled
    public function cancelAction() {
        if (Mage::getSingleton('checkout/session')->getLastRealOrderId()) {
            $order = Mage::getModel('sales/order')->loadByIncrementId(Mage::getSingleton('checkout/session')->getLastRealOrderId());
            if ($order->getId()) {
                // Flag the order as 'cancelled' and save it
                $order->cancel()->setState(Mage_Sales_Model_Order::STATE_CANCELED, true, 'Gateway has declined the payment.')->save();
            }
        }
    }

    /*     * **************************************lib**************** */

    /**
     * Displays the pay page.
     *
     * @param unknown $params
     * @param unknown $salt
     * @throws Exception
     */
    public function easepay_page($params, $salt, $env = 'test') {
        if (!function_exists('curl_init')) :

            define('CURLOPT_URL', 1);
            define('CURLOPT_USERAGENT', 2);
            define('CURLOPT_POST', 3);
            define('CURLOPT_POSTFIELDS', 4);
            define('CURLOPT_RETURNTRANSFER', 5);
            define('CURLOPT_REFERER', 6);
            define('CURLOPT_HEADER', 7);
            define('CURLOPT_TIMEOUT', 8);
            define('CURLOPT_CONNECTTIMEOUT', 9);
            define('CURLOPT_FOLLOWLOCATION', 10);
            define('CURLOPT_AUTOREFERER', 11);
            define('CURLOPT_PROXY', 12);
            define('CURLOPT_PORT', 13);
            define('CURLOPT_HTTPHEADER', 14);
            define('CURLOPT_SSL_VERIFYHOST', 15);
            define('CURLOPT_SSL_VERIFYPEER', 16);

            function curl_init($url = false) {
                return new Curl($url);
            }

            function curl_setopt(&$ch, $name, $value) {
                $ch->setopt($name, $value);
            }

            function curl_exec($ch) {
                return $ch->exec();
            }

            function curl_close(&$ch) {
                unset($ch);
            }

            function curl_errno($ch) {
                return $ch->error;
            }

            function curl_error($ch_error) {
                return "Could not open socket";
            }

            function curl_getinfo($ch, $opt = NULL) {
                return $ch->info;
            }

            function curl_setopt_array(&$ch, $opt) {
                $ch->setoptArray($opt);
            }

        endif;
        $result = $this->pay($params, $salt, $env);
        return $result;
//        Misc::show_page($result);
    }

    /**
     * Returns the pay page url or the merchant js file.
     *
     * @param unknown $params
     * @param unknown $salt
     * @throws Exception
     * @return Ambigous <multitype:number string , multitype:number Ambigous <boolean, string> >
     */
    function pay($params, $salt, $env = 'test') {
        if (!is_array($params))
            throw new Exception('Pay params is empty');

        if (empty($salt))
            throw new Exception('Salt is empty');

        $payment = new Payment($salt, $env);
        $result = $payment->pay($params);
        unset($payment);
        return $result;
    }

    /**
     * Returns the response object.
     *
     * @param unknown $params
     * @param unknown $salt
     * @throws Exception
     * @return number
     */
    function response($params, $salt) {
        if (!is_array($params))
            throw new Exception('response params is empty');

        if (empty($salt))
            throw new Exception('Salt is empty');

        if (empty($params['status']))
            throw new Exception('Status is empty');

        $response = new Response($salt);
        $result = $response->get_response($_POST);
        unset($response);

        return $result;
    }

}

class Payment {

    private $url;
    private $salt;
    private $params = array();

    public function __construct($salt, $env = 'test') {
        $this->salt = $salt;
        switch ($env) {
            case 'test' :
                $this->url = "https://testpay.easebuzz.in/";
                break;
            case 'prod' :
                $this->url = 'https://pay.easebuzz.in/';
                break;
            default :
                $this->url = "https://testpay.easebuzz.in/";
        }
    }

    public function __destruct() {
        unset($this->url);
        unset($this->salt);
        unset($this->params);
    }

    public function __set($key, $value) {
        $this->params[$key] = $value;
    }

    public function __get($key) {
        return $this->params[$key];
    }

    public function pay($params = null) {
        if (is_array($params)){
            foreach ($params as $key => $value){
                $this->params[$key] = $value;
                $this->params[$key] = trim($value);
            }
        }
        $error = $this->check_params();

        if ($error === true) {
            $this->params['hash'] = Misc::get_hash($this->params, $this->salt);
            $result = Misc::curl_call($this->url . 'payment/initiateLink', http_build_query($this->params));
            $transaction_id = ($result['curl_status'] === Misc::SUCCESS) ? $result['result'] : null;
            if (empty($transaction_id)) {
                return array('status' => Misc::FAILURE,
                    'data' => $result['error']);
            } else {
                return array(
                    'status' => Misc::SUCCESS,
                    'data' => $this->url . 'pay/' . $transaction_id);
            }
        } else {
            return array('status' => Misc::FAILURE, 'data' => $error);
        }
    }

    private function check_params() {
        if (empty($this->params['key']))
            return $this->error('key');
        if (empty($this->params['txnid']))
            return $this->error('txnid');
        if (empty($this->params['amount']))
            return $this->error('amount');
        if (empty($this->params['firstname']))
            return $this->error('firstname');
        if (empty($this->params['email']))
            return $this->error('email');
        if (empty($this->params['phone']))
            return $this->error('phone');
        if (empty($this->params['productinfo']))
            return $this->error('productinfo');
        if (empty($this->params['surl']))
            return $this->error('surl');
        if (empty($this->params['furl']))
            return $this->error('furl');
        return true;
    }

    private function error($key) {
        return 'Mandatory parameter ' . $key . ' is empty';
    }

}

class Misc {

    const SUCCESS = 1;
    const FAILURE = 0;

    public static function get_hash($params, $salt) {
        $posted = array();

        if (!empty($params))
            foreach ($params as $key => $value)
                $posted[$key] = htmlentities($value, ENT_QUOTES);

        $hash_sequence = "key|txnid|amount|productinfo|firstname|email|udf1|udf2|udf3|udf4|udf5|udf6|udf7|udf8|udf9|udf10";

        $hash_vars_seq = explode('|', $hash_sequence);
        $hash_string = null;

        foreach ($hash_vars_seq as $hash_var) {
            $hash_string .= isset($posted[$hash_var]) ? $posted[$hash_var] : '';
            $hash_string .= '|';
        }

        $hash_string .= $salt;
        return strtolower(hash('sha512', $hash_string));
    }

    public static function reverse_hash($params, $salt, $status) {
        $posted = array();
        $hash_string = null;

        if (!empty($params))
            foreach ($params as $key => $value)
                $posted[$key] = htmlentities($value, ENT_QUOTES);

        $hash_string = "";
        $hash_sequence = "udf10|udf9|udf8|udf7|udf6|udf5|udf4|udf3|udf2|udf1|email|firstname|productinfo|amount|txnid|key";
        $hash_vars_seq = explode('|', $hash_sequence);
        $hash_string .= $salt . '|' . $status;

        foreach ($hash_vars_seq as $hash_var) {
            $hash_string .= '|';
            $hash_string .= isset($posted[$hash_var]) ? $posted[$hash_var] : '';
        }
        return strtolower(hash('sha512', $hash_string));
    }

    public static function curl_call($url, $data) {
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/36.0.1985.125 Safari/537.36',
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0));
        $o = curl_exec($ch);
        if (curl_errno($ch)) {
            $c_error = curl_error($ch);
            if (empty($c_error))
                $c_error = 'Server Error';
            return array('curl_status' => Misc::FAILURE, 'error' => $c_error);
        }
        $o = trim($o);

        $res = json_decode($o);
        if ($res->status == 1) {
            return array('curl_status' => Misc::SUCCESS, 'result' => $res->data);
        } else {
            return array('curl_status' => Misc::FAILURE, 'error' => $res->data);
        }
    }

//    public static function show_page($result) {
//        if ($result['status'] === Misc::SUCCESS) {
//            header('Location:' . $result['data']);
//        } else {
//            echo $result['data'];
//        }
//    }
}

class Curl {

    private $url = "";
    private $user_agent = "libCurl";
    private $return_result = false;
    private $referrer = false;
    private $cookies_on = false;
    private $proxy = array();
    private $timeout = 30;
    private $cookies;
    private $headers;
    private $method = "GET";
    private $httpHeader = "application/x-www-form-urlencoded";
    public $error = 0;
    public $info = array();

    function __construct($url = false) {
        $this->cookies = new Cookies();
        $this->url = $url;
        $this->info['total_time'] = time();
    }

    function __destruct() {

    }

    private function getHost($url) {
        $url = str_replace(array("http://", "https://"), "", $url);
        $tmp = explode("/", $url);
        return $tmp[0];
    }

    private function getQuery($url) {
        $url = str_replace(array("http://", "https://"), "", $url);
        $tmp = explode("/", $url, 2);
        return "/" . $tmp[1];
    }

    private function _parseRawData($rawData) {
        $array = explode("\r\n\r\n", $rawData, 2);
        $this->header_data = $array[0];
        $this->content = $array[1];
        $this->_parseHeaders($array[0]);
    }

    private function _parseHeaders($rawHeaders) {
        $rawHeaders = trim($rawHeaders);
        $headers = explode("\r\n", $rawHeaders);

        foreach ($headers as $header) {
            if (preg_match("|http/1\.. (\d+)|i", $header, $match)) {
                $this->status_code = $match[1];
                continue;
            }

            $headerArray = explode(":", $header);
            $headerName = trim($headerArray[0]);
            $headerValue = trim($headerArray[1]);

            if (preg_match("|set-cookie2?|i", $headerName))
                $this->cookies->add($headerValue);
            if (isset($headerName))
                $this->headers[strtolower($headerName)] = $headerValue;
        }

        if (isset($this->headers["location"])) {
            $this->url = $this->headers["location"];
            $this->exec();
        }
    }

    public function setopt($name, $value = false) {
        switch ($name) {
            case CURLOPT_URL :
                $this->url = $value;
                $this->proxy["port"] = substr($this->url, 0, 5) === 'https' ? 443 : 80;
                break;
            case CURLOPT_USERAGENT :
                $this->user_agent = $value;
                break;
            case CURLOPT_POST :
                $this->method = ($value == true) ? "POST" : "GET";
                break;
            case CURLOPT_POSTFIELDS :
                $this->post_data = $value;
                break;
            case CURLOPT_RETURNTRANSFER :
                $this->return_result = ($value == true);
                break;
            case CURLOPT_REFERER :
                $this->referrer = $value;
                break;
            case CURLOPT_HEADER :
                $this->options["header"] = ($value == true);
                break;
            case CURLOPT_PROXY :
                list ( $this->proxy["host"], $this->proxy["port"] ) = explode(":", $value);
                break;
            case CURLOPT_CONNECTTIMEOUT : /* Fall through. */
            case CURLOPT_TIMEOUT :
                $this->timeout = ($value >= 0) ? $value : 30;
                break;
            case CURLOPT_PORT :
                $this->proxy["port"] = $value ? $value : (substr($this->url, 0, 5) === 'https' ? 443 : 80);
                break;
            case CURLOPT_HTTPHEADER :
                $this->httpHeader = substr(implode(";", $value), 0, - 1);
                break;
        }
    }

    public function setoptArray($options) {
        foreach ($options as $name => $value)
            $this->setopt($name, $value);
    }

    public function exec() {
        $errno = false;
        $errstr = false;
        $url = $this->url;

        $host = $this->getHost($url);
        $query = $this->getQuery($url);

        $this->proxy["host"] = $host;

        if (isset($this->proxy["port"])) {
            $this->proxy["host"] = (443 === $this->proxy["port"]) ? "ssl://$host" : $host;
            $fp = pfsockopen($this->proxy["host"], $this->proxy["port"], $errno, $errstr, $this->timeout);
            $request = $query;
        } else {
            $fp = pfsockopen($host, 80, $errno, $errstr, $this->timeout);
            $request = $query;
        }

        if (!$fp) { /* trigger_error($errstr, E_WARNING); */
            $this->error = 1;
            return;
        }

        $headers = $this->method . " $request HTTP/1.0 \r\nHost: $host \r\n";
        if ($this->user_agent)
            $headers .= "User-Agent: " . $this->user_agent . "\r\n";
        if ($this->referrer)
            $headers .= "Referrer: " . $this->referrer . "\r\n";
        if ($this->method == "POST") {
            $headers .= "Content-Type: " . $this->httpHeader . "\r\n";
            $headers .= "Content-Length: " . strlen($this->post_data) . "\r\n";
        }

        if ($this->cookies_on)
            $headers .= $this->cookies->createHeader();
        $headers .= "Connection: Close\r\n\r\n";
        if ("POST" == $this->method)
            $headers .= $this->post_data;
        $headers .= "\r\n\r\n";

        fwrite($fp, $headers);
        $rawData = "";
        while (!feof($fp))
            $rawData .= fread($fp, 512);
        /* fclose($fp); /* Too lazy to read the docs. */
        $this->info['total_time'] = time() - $this->info['total_time'];

        $this->_parseRawData($rawData);
        if ($this->options["header"])
            $this->content = $rawData;
        if ($this->return_result)
            return $this->content;
        echo $this->content;
    }

}

class Cookies {

    private $cookies;

    function __construct() {

    }

    function __destruct() {

    }

    public function add($cookie) {
        list ( $data, $etc ) = explode(";", $cookie, 2);
        list ( $name, $value ) = explode("=", $data);
        $this->cookies[trim($name)] = trim($value);
    }

    public function createHeader() {
        if (0 == count($this->cookies) || !is_array($this->cookies))
            return "";
        $output = "";
        foreach ($this->cookies as $name => $value)
            $output .= "$name=$value; ";
        return "Cookies: $output\r\n";
    }

}

class Response {

    private $salt;
    private $params = array();

    public function __construct($salt) {
        $this->salt = $salt;
    }

    public function __destruct() {
        unset($this->salt);
        unset($this->params);
    }

    public function __set($key, $value) {
        $this->params[$key] = $value;
    }

    public function __get($key) {
        return $this->params[$key];
    }

    public function get_response($params = null) {
        $this->params = (is_array($params) && count($params)) ? $params : $_POST;

        $error = $this->check_params();

        if ($error === true) {
            if (Misc::reverse_hash($this->params, $this->salt, $this->params['status']) === $this->params['hash']) {
                switch ($this->params['status']) {
                    case 'success' :
                        return array(
                            'status' => Misc::SUCCESS,
                            'data' => $this->params['surl']);
                        break;
                    case 'failure' :
                        return array(
                            'status' => Misc::SUCCESS,
                            'data' => $this->params['furl']);
                        break;
                    default :
                        return array(
                            'status' => Misc::FAILURE,
                            'data' => 'Unmapped status');
                }
            } else {
                return array(
                    'status' => Misc::FAILURE,
                    'data' => 'Hash Mismatch');
            }
        } else {
            return array('status' => Misc::FAILURE, 'data' => $error);
        }
    }

    private function check_params() {
        if (empty($this->params['key']))
            return $this->error('key');
        if (empty($this->params['txnid']))
            return $this->error('txnid');
        if (empty($this->params['amount']))
            return $this->error('amount');
        if (empty($this->params['firstname']))
            return $this->error('firstname');
        if (empty($this->params['email']))
            return $this->error('email');
        if (empty($this->params['phone']))
            return $this->error('phone');
        if (empty($this->params['productinfo']))
            return $this->error('productinfo');
        if (empty($this->params['surl']))
            return $this->error('surl');
        if (empty($this->params['furl']))
            return $this->error('furl');

        return true;
    }

    private function error($key) {
        return 'Mandatory parameter ' . $key . ' is empty';
    }

}
