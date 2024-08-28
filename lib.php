<?php

use Binance\Util\Url;

function faillog() {
    $aParam = func_get_args();
    $aMsg = '';
    foreach ($aParam as $param) {
        if (is_string($param)) {$aMsg .=$param;} else {$aMsg .= var_export($param, true);};
    }

    file_put_contents(__DIR__.'/faillog.log', date('D, d M Y H:i:s',time())." - $aMsg\r\n", FILE_APPEND);
}//function faillog() {

//Есть число 0.012345000, есть кратность 0.0001 нужно получить 0.0123
function safe_increment($number, $increment){
    return (string)floor($number / $increment) * $increment;
}

function signRequest($params){
    global $config;
    $params['timestamp'] = round(microtime(true) * 1000);
    $params['apiKey'] = $config['apiKey'];
    ksort($params);
    $query = Url::buildQuery($params);
    $params['signature'] = hash_hmac('sha256', $query, $config['secretKey']);
    return $params;
}

