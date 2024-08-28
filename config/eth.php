<?php
$config['symbol'] = "ETHFDUSD";
$config['coin1'] = "ETH";
$config['coin2'] = "FDUSD";

$config['PRICE_FILTER'] = 0.01;
$config['QTY_FILTER'] = 0.0001;
$config['MIN_NOTIONAL'] = 5;

$config['CDC'] = 1.5; //Deviation coefficient of the rate. If the price rises or falls by this percentage, we adjust the order.

$config['1m'] = ['ORDER_QTY' => 0.01,
                    'BUY_inc' => 0.7,
                    'SELL_inc' => 0.7];
$config['5m'] = ['ORDER_QTY' => 0.02,
                    'BUY_inc' => 1.2,
                    'SELL_inc' => 1.2];
$config['15m'] = ['ORDER_QTY' => 0.04,
                    'BUY_inc' => 2,
                    'SELL_inc' => 2];
$config['30m'] = ['ORDER_QTY' => 0.06,
                    'BUY_inc' => 3.5,
                    'SELL_inc' => 3.5];
