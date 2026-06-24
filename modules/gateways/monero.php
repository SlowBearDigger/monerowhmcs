<?php
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}



function monero_MetaData()
{
    return array(
        'DisplayName' => 'Monero',
        'APIVersion' => '1.1', // Use API Version 1.1
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    );
}
function monero_Config(){
	return array(
		'FriendlyName' => array('Type' => 'System','Value' => 'Monero'),
		'address' => array('FriendlyName' => 'Monero Address','Type' => 'text','Size' => '94','Default' => '','Description' => 'Not used yet'),
		'secretkey' => array('FriendlyName' => 'Module Secret Key','Type' => 'text','Default' => '21ieudgqwhb32i7tyg','Description' => 'Enter a unique key to verify callbacks'),
		'daemon_host' => array('FriendlyName' => 'Wallet RPC Host','Type' => 'text','Default' => 'localhost','Description' => 'Connection settings for the Monero Wallet RPC daemon.'),
		'daemon_port' => array('FriendlyName' => 'Wallet RPC Port','Type'  => 'text','Default' => '18081','Description' => ''),
		'daemon_user' => array('FriendlyName' => 'Wallet RPC Username','Type'  => 'text','Default' => '','Description' => ''),
		'daemon_pass' => array('FriendlyName' => 'Wallet RPC Password','Type'  => 'text','Default' => '','Description' => ''),
		'discount_percentage' => array('FriendlyName' => 'Discount Percentage','Type'  => 'text','Default' => '0%','Description' => 'Percentage discount for paying with Monero.')
    );
}

/*
*  
*  Get the current XMR price in several currencies
*  
*  @param String $currencies  List of currency codes separated by comma
*  
*  @return String  A json string in the format {"CURRENCY_CODE":PRICE}
*  
*/
function monero_retrieve_price_list($currencies = 'BTC,USD,EUR,CAD,INR,GBP,BRL') {
	
	// cryptocompare (the original source) now returns HTTP 401 and requires an API key, so the
	// gateway can no longer fetch a rate and crashes on the fiat conversion. CoinGecko's simple
	// price endpoint is free and needs no key, which restores the original behaviour.
	$source = 'https://api.coingecko.com/api/v3/simple/price?ids=monero&vs_currencies='.strtolower($currencies);
	
	if (ini_get('allow_url_fopen')) {
		
		return file_get_contents($source);
		
	}
	
	if (!function_exists('curl_init')) {
		
		echo 'cURL not available.';
		
		return false;
		
	}
	
	$options = array (
		CURLOPT_URL            => $source,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_CONNECTTIMEOUT => 10,
		CURLOPT_TIMEOUT        => 20,
	);
	
	$ch = curl_init();
	curl_setopt_array($ch, $options);
	
	$xmr_price = curl_exec($ch);
	
	curl_close($ch);
	
	if ($xmr_price === false) {
		
		echo 'Error while retrieving XMR price list';
		
	}
	
	return $xmr_price;
	
}

function monero_retrieve_price($currency) {
	global $currency_symbol;
	// CoinGecko returns {"monero":{"usd":..,"eur":..,...}} with lowercase keys, so the lookup and
	// the currency symbol are mapped here. The downstream contract is unchanged: a numeric rate.
	$symbols = array('USD' => '$', 'EUR' => '€', 'CAD' => '$', 'GBP' => '£', 'INR' => '₹', 'BRL' => 'R$ ', 'BTC' => '₿');
	$currency = strtoupper($currency);
	if ($currency == 'XMR') {
		return '1';
	}
	if (isset($symbols[$currency])) {
		$currency_symbol = $symbols[$currency];
	}
	$xmr_price = monero_retrieve_price_list('btc,usd,eur,cad,inr,gbp,brl');
	$price = json_decode($xmr_price, TRUE);
	$vs = strtolower($currency);
	if (isset($price['monero'][$vs]) && $price['monero'][$vs] > 0) {
		return $price['monero'][$vs];
	}
	// fallback: some hosts (datacenter IPs) are blocked by CoinGecko's free endpoint. Kraken's
	// public ticker needs no key and is reachable from servers, but only carries the major pairs.
	$kraken_pairs = array('USD' => 'XMRUSD', 'EUR' => 'XMREUR', 'BTC' => 'XMRXBT');
	if (isset($kraken_pairs[$currency])) {
		$kr = @file_get_contents('https://api.kraken.com/0/public/Ticker?pair=' . $kraken_pairs[$currency]);
		$kd = json_decode($kr, true);
		if (isset($kd['result']) && is_array($kd['result'])) {
			$row = reset($kd['result']);
			if (isset($row['c'][0]) && $row['c'][0] > 0) {
				return $row['c'][0];
			}
		}
	}
	echo "There was an error retrieving the XMR price";
	return null;
}

function monero_changeto($amount, $currency){
    $xmr_live_price = monero_retrieve_price($currency);
	$live_for_storing = $xmr_live_price * 100; //This will remove the decimal so that it can easily be stored as an integer
	$new_amount = $amount / $xmr_live_price;
	$rounded_amount = round($new_amount, 12);
    return $rounded_amount;
}

function xmr_to_fiat($amount, $currency){
    $xmr_live_price = monero_retrieve_price($currency);
    $amount = $amount / 1000000000000;
	$new_amount = $amount * $xmr_live_price;
	$rounded_amount = round($new_amount, 2);
    return $rounded_amount;
}



function monero_link($params){
global $currency_symbol;

$gatewaymodule = "monero";
$gateway = getGatewayVariables($gatewaymodule);
if(!$gateway["type"]) die("Module not activated");


	$invoiceid = $params['invoiceid'];
	$amount = $params['amount'];
	$discount_setting = $gateway['discount_percentage'];
	$discount_percentage = 100 - (preg_replace("/[^0-9]/", "", $discount_setting));
	// money_format() was removed in PHP 8.0, which is what current WHMCS runs on. Format the
	// discounted amount as a plain decimal instead (no locale separators that could break parsing).
	$amount = number_format($amount * ($discount_percentage / 100), 2, '.', '');
	$currency = $params['currency'];
	$client_id = $params['clientdetails']['id'];
	$firstname = $params['clientdetails']['firstname'];
	$lastname = $params['clientdetails']['lastname'];
	$email = $params['clientdetails']['email'];
	$phone = $params['clientdetails']['phonenumber'];
	$city = $params['clientdetails']['city'];
	$state = $params['clientdetails']['state'];
	$postcode = $params['clientdetails']['postcode'];
	$country = $params['clientdetails']['country'];
	$address = $params['address'];
	$address1 = $params['clientdetails']['address1'];
	$address2 = $params['clientdetails']['address2'];
	$systemurl = $params['systemurl'];
    // Transform Current Currency into Monero
	$amount_xmr = monero_changeto($amount, $currency);
	
	$post = array(
        'invoice_id'    => $invoiceid,
        'systemURL'     => $systemurl,
        'buyerName'     => $firstname . ' ' . $lastname,
        'buyerAddress1' => $address1,
        'buyerAddress2' => $address2,
        'buyerCity'     => $city,
        'buyerState'    => $state,
        'buyerZip'      => $postcode,
        'buyerEmail'    => $email,
        'buyerPhone'    => $phone,
        'address'       => $address,
        'amount_xmr'    => $amount_xmr,
        'amount'        => $amount,
        'currency'      => $currency,
		'client_id'      => $client_id
    );
	$form = '<form action="' . $systemurl . 'modules/gateways/monero/createinvoice.php" method="POST">';
    foreach ($post as $key => $value) {
        $form .= '<input type="hidden" name="' . $key . '" value = "' . $value .'" />';
    }
    $form .= '<input type="submit" value="' . $params['langpaynow'] . '" />';
    $form .= '</form>';
	$form .= '<p>'.$amount_xmr. " XMR (". $currency_symbol . $amount . " " . $currency .')</p>';
	if ($discount_setting > 0) {
		$form .='<p><small>Discount Applied: ' . preg_replace("/[^0-9]/", "", $discount_setting) . '% </small></p>';
	}
    return $form;
}
