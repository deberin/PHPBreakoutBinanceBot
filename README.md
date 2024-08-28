# PHPBreakoutBinanceBot

![Logo](https://github.com/deberin/PHPBreakoutBinanceBot/blob/main/screenshots/1.png)

The PHPBreakoutBinanceBot implements a breakout trading strategy on Binance, designed to operate on customizable timeframes of 1 minute, 5 minutes, 15 minutes, and 30 minutes. The bot places a buy order, and if the order is executed, it immediately sets a corresponding sell order according to the predefined settings. If the buy order is not fulfilled within the specified timeframe and the price moves away, the bot will reposition the order to adapt to market conditions.

## Installation

1.  **Download the repository**: First, clone or download the bot repository to your computer.
2.  **Install libraries with Composer**: Navigate to the project directory and run the command ```composer install``` to install all necessary dependencies.
3.  **Configure settings**: Rename the file ```config.php.example``` to ```config.php``` and enter your API keys. You can set the parameter const TESTNET = false; to true for testing on the testnet.
    
## Usage/Examples

```bash
php breakout.php 1m <timeframe 1m, 5m, 15m>
```

