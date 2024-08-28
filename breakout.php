<?php
// Enable error logging to file
ini_set('log_errors', 1);
ini_set('error_log', __DIR__.'/log/php-errors.log');
date_default_timezone_set('Europe/Kiev');

if ($argc < 2) {
    die("Usage: php breakout.php <timeframe 1m, 5m, 15m>");
}

$timeFrame = $argv[1];
$int_timeFrame = intval($timeFrame); 

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use React\EventLoop\Loop;

require "lib.php";
require "config.php";
require __DIR__."/config/eth.php";
require "vendor/autoload.php";





$log = new Logger($timeFrame);
$log->pushHandler(new StreamHandler(__DIR__ ."/log/breakout-".$timeFrame."-".date('Y-m-d').".log",  Logger::DEBUG));

$client_Spot = new \Binance\Spot(['key' => $config['apiKey'], 'secret' => $config['secretKey'], 'baseURL' => $config['api_endpoint'], 'logger' => $log]);



$loop = Loop::get();
$reactConnector = new \React\Socket\Connector($loop);
$connector = new \Ratchet\Client\Connector($loop, $reactConnector);
$client_WS = new \Binance\Websocket\Spot(['wsConnector' => $connector,
    'key'  => $config['apiKey'],
    'secret'  => $config['secretKey'],
    'baseURL' => $config['ws_endpoint'],
    'logger' => $log
    /*'showWeightUsage' => true optional, only if you need get order/ip weight usage*/]);


$GLOBALS['aBuyOrder'] = null; //Active buy order
$GLOBALS['aSellOrder'] = null; //Active sell order
$GLOBALS['price'] = null; //Current exchange rate
$GLOBALS['price_lastUpdate'] = 0; //When was the rate last updated, if more than 3 seconds. Stopping the script

/****We receive open orders*****/
try {
    $response = $client_Spot->openOrders(['symbol' => $config['symbol'], 'recvWindow' => 5000]);
    foreach ($response as $order){

        //We only check bot orders
        if (explode('_', $order['clientOrderId'])[0] !== "breakout-{$timeFrame}")
            continue;

        switch ($order['side']){
            case "BUY": {
                $GLOBALS['aBuyOrder'] = $order;
                break;   
            }
        }//switch ($value['side']){
    }// foreach ($response as $order){
}catch (Exception $e) {
    //No connection, write logs
    $log->error($e->getMessage());
    die();
}
/*****************************/

/********MONITORING THE EXECUTION OF ORDERS**********/
//Getting $listenKey for the order monitor
try {
    $response = $client_Spot->newListenKey();
    $listenKey = $response['listenKey'];
} catch (Exception $e) {
    //No connection, write logs
    $log->error($e->getMessage());
    die();
}

$client_WS->userData($listenKey, ['message' => function ($conn, $msg) use ($log, $loop, $timeFrame) {
    $aMsg = json_decode($msg, true);
    $log->info("userData.".$aMsg['e'], [$aMsg]);

    switch ($aMsg['e']){
      
        //There is a change in order https://binance-docs.github.io/apidocs/spot/en/#payload-order-update
        case "executionReport":
            $orderId = $aMsg['i'];
            $executed_qty = $aMsg['z'];
            $clientOrderId = $aMsg['c'];

            //Average order price
            $avg_order_price = $aMsg['L'];
            $order_price = $aMsg['p'];
            
            if ($aMsg['X']=='FILLED'){
                
                 //We only check bot orders
                if (explode('_', $clientOrderId)[0] !== "breakout-{$timeFrame}"){
                    $log->info("clientOrderId = {$clientOrderId}. Not a bot order, skip.");
                    return;
                }//if (explode('_', $clientOrderId)[0] !== 'breakout')

                //TODO You can check the commission
                $executed_price = $aMsg['Z'] / $aMsg['z'];

                //Cancel the timer for checking the order status
                if (isset($GLOBALS["order_status_{$clientOrderId}"])){
                    $log->info("Cancel the timer for checking the order status", ["clientOrderId" => $clientOrderId]);

                    $loop->cancelTimer($GLOBALS["order_status_{$clientOrderId}"]);
                    unset($GLOBALS["order_status_{$clientOrderId}"]);
                }//if (isset($GLOBALS["order_status_{$clientOrderId}"])){

                switch ($aMsg['S']){
                    case "BUY": {
                        $data = ['executed_price' => $executed_price,
                                 'executed_qty' => $executed_qty,
                                 'orderId' => $orderId];
                        $log->info("executionReport BUY .userData", $data);
                        triggerBuyOrderExecute($data);
                        break;
                    }//case "BUY":
                    /*    
                    case "SELL":{
                        $data = ['executed_price' => $executed_price,
                                 'executed_qty' => $executed_qty,
                                 'orderId' => $orderId];
                        $log->info("executionReport SELL .userData", $data);
                        triggerSellOrderExecute($data);
                        break;
                    }//case "SELL":{
                    */    
                }// switch ($aMsg['S']){
            }//if ($aMsg['X']=='FILLED'){
            break; //case "executionReport":
        //***************
    }//switch ($aMsg['e']){
}
]);
/***************************/

//Let's regenerate the key for user data
$loop->addPeriodicTimer(30*60 /*every 30 min*/, function () use ($client_Spot, $listenKey) {
    $client_Spot->renewListenKey($listenKey);
});

//Ping for exchange rate socket
$loop->addPeriodicTimer(8*60, function () use ($client_WS) {
    $client_WS->ping();
});

/******We update courses********/
$client_WS->bookTicker(['message' => function ($conn, $msg) use ($log, $client_Spot) {
    $aMsg = json_decode($msg, true);
    if (!$aMsg['a']){
        $log->error("No courses", ["aMsg" => $aMsg]);
        stopBot();
        botControl();
    }//if (!$aMsg['a']){

    $GLOBALS['price'] = @$aMsg['a'];
    $GLOBALS['price_lastUpdate'] = date('U');
    //$log->info($GLOBALS['price']);

}//'message' => function ($conn, $msg)
, 'close' => function ($conn, $msg) use ($log) {
    $log->error("bookTicker Close", [$msg]);
    die(); //We don’t write stop so that BASH runs the script again
    }], $config['symbol']);


/****We write to the file that the script is working, we read whether it needs to work*****/
$loop->addPeriodicTimer(5, function () use ($timeFrame){
    $data = [
        'last_update' => date('U')
    ];
    $json = json_encode($data);
    file_put_contents(__DIR__ ."/status_{$timeFrame}.json", $json);

    botControl();
});
/***********************/

/******Basic logic********/
$loop->addPeriodicTimer($int_timeFrame * 60 /*sec*/, function () use ($log,  $client_Spot, $config, $timeFrame){
    //If there is no course or the course has not been updated for more than N seconds
    if (!$GLOBALS['price'] || (date("U") - $GLOBALS['price_lastUpdate'] > 5)){
        $log->error("No course or no longer updated ".date("U") - $GLOBALS['price_lastUpdate']." sec. Waiting....");
        return;
    }//if (!$GLOBALS['price']

    //Place a limit order to buy if there is none already
    if (!$GLOBALS['aBuyOrder']){
        newOrder(['side' => 'BUY',   
                  'price' => $GLOBALS['price'] - PercentageToRate($GLOBALS['price'], $config[$timeFrame]['BUY_inc']) 
                ]);
        return;
    }// if (!$GLOBALS['aBuyOrder']){

    //There is a BUY order, check so that the price does not move away from the order
    elseif ($GLOBALS['aBuyOrder']){
       
        if (($GLOBALS['price'] - $GLOBALS['aBuyOrder']['price'] < PercentageToRate($GLOBALS['price'], $config[$timeFrame]['BUY_inc'] / $config['CDC']) ) ||
             $GLOBALS['price'] - $GLOBALS['aBuyOrder']['price'] > PercentageToRate($GLOBALS['price'], $config[$timeFrame]['BUY_inc'] * $config['CDC'])){
                //Rearrange the order
                $log->info("Rearrange the order", ["price" => $GLOBALS['price'], "orderPrice" => $GLOBALS['aBuyOrder']['price']]);

                //Cancel the order and place a new one
                try{
                    $response = $client_Spot->cancelOrder($config['symbol'], ['orderId' => $GLOBALS['aBuyOrder']['orderId'], 'recvWindow' => 5000]);
                    $log->info("BUY order canceled", $response);

                    //If the order is partially executed, we place the remainder for sale
                    if ($response['executedQty'] > 0){
                        $log->info("PARTIALLY_FILLED. Trying to create a sell order triggerBuyOrderExecute");
                        if ($response['executedQty'] < calcMinNational()){
                            $log->error("PARTIALLY_FILLED . executedQty < MIN_NATIONAL", ['executedQty'=> $response['executedQty'], 'MIN_NATIONAL'=>calcMinNational()]);
                            SaveUnexecutedQty(['orderId'=>$response['orderId'], 'price'=>$response['price'], 'qty'=>$response['executedQty']]);
                            $GLOBALS['aBuyOrder'] = null;
                        }else {
                            triggerBuyOrderExecute(['executed_price' => $response['price'],
                                'executed_qty' => $response['executedQty'],
                                'orderId' => $response['orderId']]);
                        }//if ($response['executedQty'] < calcMinNational()){
                    }else {
                        $GLOBALS['aBuyOrder'] = null;
                    }//if ($GLOBALS['aBuyOrder']['status'] == 'PARTIALLY_FILLED')
                    
                    newOrder(['side' => 'BUY', 'price' => $GLOBALS['price'] - PercentageToRate($GLOBALS['price'], $config[$timeFrame]['BUY_inc']) ]);

                } catch (Exception $e) {
                    $log->error($e->getMessage());
                    die();
                }//catch (Exception $e) {
        }//if (($GLOBALS['price'] - $GLOBALS['aBuyOrder']['price'] < 10 ) ||....
        else{
            $difference = $GLOBALS['price'] - $GLOBALS['aBuyOrder']['price'];
            // Calculate the percentage difference
            $percentDifference = ($difference / $GLOBALS['aBuyOrder']['price']) * 100;
            $log->info("There is a Buy order and it is within normal limits. Price:{$GLOBALS['price']}, Order_Price:{$GLOBALS['aBuyOrder']['price']}, Diff:{$percentDifference}");
        }
    }// elseif ($GLOBALS['aBuyOrder']){
});
/******************************/

$loop->run();



function stopBot(){
    global $log, $timeFrame;
    file_put_contents(__DIR__ ."/run_{$timeFrame}.ini","stop");
    $log->error("STOP bot");
}

function botControl(){
    global $log,  $client_Spot, $config, $timeFrame;

    $run_file = __DIR__ ."/run_{$timeFrame}.ini";

    if (!file_exists($run_file)) {
        file_put_contents($run_file, "run");
    }

    $res = file_get_contents($run_file);
    if (trim($res)!=='run'){
       $log->error("run_{$timeFrame}.ini Not Run", [$res]);
       //If there is a buy order, we remove it.
        if ($GLOBALS['aBuyOrder']){
            try{
                $client_Spot->cancelOrder($config['symbol'], ['orderId' => $GLOBALS['aBuyOrder']['orderId'], 'recvWindow' => 5000]);
            }
            catch (Exception $e) {
                $log->error($e->getMessage());
                }
            }//try
       die();
    }//if ($res!=='run'){
}// function botControl(){

function newOrder($aParam){
    global $log, $client_Spot, $config, $timeFrame;

    if (!$aParam['price']){
        $log->error("newOrder Нет price", [$aParam]);
        die();
    }//if (!$aParam['price']){

    $microtime = microtime(true);
    $microtime_parts = explode('.', sprintf('%.6f', $microtime));
    $sNewClientOrderId = "breakout-{$timeFrame}_".date('U').$microtime_parts[1];

    $aOrder =  ['quantity' => safe_increment(@$aParam['quantity'] ? $aParam['quantity'] : $config[$timeFrame]['ORDER_QTY'], $config['QTY_FILTER']),
                'price' => safe_increment($aParam['price'], $config['PRICE_FILTER']),
                'newClientOrderId' => $sNewClientOrderId,
                'timeInForce' => 'GTC',
                'newOrderRespType' => 'FULL',
                'recvWindow' => 5000];


    $log->info("newOrder {$aParam['side']} send", [$aOrder]);
    try {
        $response = $client_Spot->newOrder($config['symbol'], $aParam['side'], 'LIMIT', $aOrder);

        if ($aParam['side'] == 'BUY')
            $GLOBALS['aBuyOrder'] = $response;
        else
            $GLOBALS['aSellOrder'] = $response;

        //The order was executed immediately
        if ($response['status'] == 'FILLED') {
            $data = ['executed_price' => $response['price'],
                'executed_qty' => $response['executedQty'],
                'orderId' => $response['orderId']];
            $log->info("newOrder FILLED", $response);
            if ($response['side'] == 'BUY'){
                triggerBuyOrderExecute($data); 
            } else 
                triggerSellOrderExecute($data);
            return;
        }// if ($response['status']=='FILLED'){

        if ($aParam['side'] == 'BUY'){
            //Sometimes an order is executed immediately and the script thinks that the status is NEW, after a couple of seconds you need to check the status
            setLoopCheckOrderStatus(5 /*sec*/, $aOrder['newClientOrderId']);
        }

        $log->info("newOrder {$aParam['side']} response", $response);
    } catch (Exception $e) {
        $log->error($e->getMessage());
        stopBot();
        botControl();
    }//catch (Exception $e) {
}//function newOrder($aParam){

//Buy order - fully executed
function triggerBuyOrderExecute($param){
    global $log, $config, $timeFrame;
    $log->info("triggerBuyOrderExecute", $param);

    if (is_null($GLOBALS['aBuyOrder']) || @$GLOBALS['aBuyOrder']['orderId'] != $param['orderId']){
        $log->warning("triggerBuyOrderExecute The order was executed, but we don’t know it", ["param" => $param, "aOpenOrder_BUY" => @$GLOBALS['aBuyOrder']['orderId']]);
        return;
    }//if ($GLOBALS['aBuyOrder']['orderId'] != $param['orderId']){

    $aSellParam = ["side" => 'SELL',
                   "price" => $param['executed_price'] + PercentageToRate($param['executed_price'], $config[$timeFrame]['SELL_inc']),
                   "quantity" => $param['executed_qty'],
                   "aBuyOrder" => $GLOBALS['aBuyOrder']];

    $GLOBALS['aBuyOrder'] = null;

     /****Place a sell order****/
     newOrder($aSellParam);
}//function triggerBuyOrderExecute($param){


function triggerSellOrderExecute($param){
   return;
}


function setLoopCheckOrderStatus(int $int, $newClientOrderId){
    global $loop, $log, $client_Spot, $config;
      //Set a timer to get the result
      $GLOBALS["order_status_{$newClientOrderId}"] = $loop->addTimer($int, function () use ($newClientOrderId, $log, $client_Spot, $config, $int) {
          $log->info("Checking the status of the order via {$int} sec.", ['newClientOrderId' => $newClientOrderId]);
  
          try {
              $response = $client_Spot->getOrder($config['symbol'], [ 'origClientOrderId' => $newClientOrderId, 'recvWindow' => 5000]);
  
              if ($response['status']=='FILLED'){
                  $data = ['executed_price' => $response['price'],
                      'executed_qty' => $response['executedQty'],
                      'orderId' => $response['orderId']];
                  $log->info("setLoopCheckOrderStatus: newOrder FILLED", $data);
                  $response['side'] == 'BUY' ? triggerBuyOrderExecute($data) : triggerSellOrderExecute($data);
                  return;
              }else{
                  $log->info("setLoopCheckOrderStatus", $response);
              }// if ($response['status']=='FILLED'){
  
              /*
              if ($response['clientOrderId'] == $GLOBALS['aBuyOrder']['clientOrderId'])
                  $GLOBALS['aBuyOrder'] = $response;
              else
                  $GLOBALS['aSellOrders'][$response['clientOrderId']] = $response;*/
          } catch (Exception $e) {
              $log->error($e->getMessage());
              die();
          }//try { catch
      });// $loop->addTimer(2, function ()
  }//function newOrder($aParam){


  function calcMinNational(){
    global $config;
    return 1/$GLOBALS['price'] * $config['MIN_NOTIONAL'];
}

//If we cannot create a sell order, we need to remember the amount and rate so that we can exchange it later
function SaveUnexecutedQty($aData){
    global $config;
    $s = file_get_contents(__DIR__."/unexecutedQty.json");
    $aS = json_decode($s, true);
    $aUnexecutedQty = $aS ? $aS : [];
    $aUnexecutedQty[] = ["created_at" => date("d.m.Y H:i:s"),
                         "symbol" => $config['symbol'],
                         "buy_orderId" => $aData['orderId'],
                         "price" => $aData['price'],
                         "qty" => $aData['qty']];
    file_put_contents(__DIR__."/unexecutedQty.json", json_encode($aUnexecutedQty));
}

function PercentageToRate($price, $percentage){
    // Calculate the percentage of the exchange rate
    return ($price * $percentage) / 100;
}