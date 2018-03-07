<?php
/**
 * This script takes the values from Electrum (Bitcoin), Etherscan (Ethereum) and historical fiat/cryptocurrency pairs from Coinmarketcap and produces an output for the EÜR for the german tax.
 * See description below how to create the exports.
 *
 * @author Christopher Klein
 */

// go to https://coinmarketcap.com/currencies/bitcoin/historical-data/?start=20170701&end=20171231, copy the date range and save it to the file coinmarketcap_(bitcoin|ethereum).csv
// separator for coinmarketcap self-copied values is a tab
$coinmarketcap_bitcoin = file_get_contents('coinmarketcap_bitcoin.csv');
$coinmarketcap_ethereum = file_get_contents('coinmarketcap_ethereum.csv');
// go to https://etherscan.io/exportData?type=address&a=0x$YOUR_ADDRESS and select the date you want to start with. Edit CSV by hand to remove date range
$etherscan_export = file_get_contents('etherscan_export.csv');
// select Wallet -> History -> Export and remove any transactions which are not mined but bought or otherwise transferred
$electrum_export = file_get_contents('electrum_export.csv');

// only fetch values for the given fiscal year. You comment this line out or set it to '' to skip the check
define('FISCAL_YEAR', 2017);
// your fiat
define('FIAT', 'EUR');
// max value for a transaction to recognize it as a mining transaction
define('EUR_MINING_MAX', 200 /* define each value more than € 200 as non-mining entry */);

/**
 * Convert an CSV export created with Electrum to a data structure
 * @param string
 * @return array
 */
function electrum($content) {
	$r = [];

	$electrumLines = explode("\n", $content);

	for ($i = 0, $m = sizeof($electrumLines); $i < $m; $i++) {
		$cols = explode(",", $electrumLines[$i]);
		if ($cols[0] == 'transaction_hash') {
			continue;
		}
		
		$volume = $cols[3];
		$date = explode(" ", $cols[4]);	
		
		$day = $date[0];
		$r[] = ['day' => $day, 'volume' => $volume];
	}
	
	return $r;
}

/**
 * Read content from a manually done Etherscan.io export
 * @param $content
 * @return array
 */
function etherscan($content) {
	$r = [];
	
	// remove quotes
	$content = 	str_replace("\"", "", $content);
	$lines = explode("\n", $content);
	
	for ($i = 0, $m = sizeof($lines); $i < $m; $i++) {
		// remove empty line
		if (trim(strlen($lines[$i])) == 0) {
			continue;
		}
		
		$cols = explode(",", $lines[$i]);
		
		if (strtolower($cols[0]) == 'txhash') {
			continue;
		}
		
		$volume = $cols[7];
		// 12/5/2017 2:31:43 AM
		$date = explode(" ", $cols[3]);
		// remove time part
		$date = explode("/", $date[0]);
		$day = $date[2] . "-" . str_pad($date[0], 2, "0", STR_PAD_LEFT) . "-" . str_pad($date[1], 2, "0", STR_PAD_LEFT);
		// we assume that we only have incoming transactions. Enough for my case.
		$r[] = ['day' => $day, 'volume' => '+' . $volume];
	}
	
	return $r;
}

/**
 * Read content from a Coinmarketcap copied export
 * @param $content
 * @return array
 */
function coinmarketcap($content) {
	$r = [ /* key = Date, value = High - (High - Low) */ ];
	$months = ['Jan' => '01', 'Feb' => '02', 'Mar' => '03', 'Apr' => '04', 'May' => '05', 'Jun' => '06', 'Jul' => '07', 'Aug' => '08', 'Sep' => '09', 'Oct' => '10', 'Nov' => '11', 'Dec' => '12' ];
	
	$lines = explode("\n", $content);
	
	for ($i = 0, $m = sizeof($lines); $i < $m; $i++) {
		$cols = explode("\t", $lines[$i]);
		
		$date = explode(" ", $cols[0]);
		$month = $months[$date[0]];
		$day_in_month = str_replace(",","", $date[1]);
		$year = $date[2];
		$day = $year . "-" . $month . "-" . $day_in_month;
		
		// convert float format to PHP
		$high = (float)str_replace(",", ".", str_replace(".", "", $cols[2]));
		$low = (float)str_replace(",", ".", str_replace(".", "", $cols[3]));
		
		$avg = $high - ($high - $low);
		
		$r[$day] = $avg;
	}
	
	return $r;
}

/**
 * Creates the printable data structure from the $data array and aligns them with the historical Coinmarketcap data
 * @param $data (Etherscan, Electrum etc.)
 * @param $coinmarketcap
 */
function build_data($data, $coinmarketcap) {
	$balance = 0;
	$r = ['balance_crypto' => 0, 'balance_mining_crypto' => 0, 'balance_fiat' => 0, 'balance_mining_fiat' => 0, 'transactions' => []];

	for ($i = 0, $m = sizeof($data); $i < $m ; $i++) {
		$transaction = $data[$i];
		$volume = $transaction['volume'];
		$day = $transaction['day'];
		
		if (defined('FISCAL_YEAR')) {
			if (FISCAL_YEAR != '') {
				$date = explode("-", $day);
				
				// output only transactions from the given fiscal year
				if (FISCAL_YEAR != $date[0]) {
					continue;
				}
			}
		}
		
		$plus_minus = $volume{0};
		$value = (float)substr($volume, 1);

		if (!isset($coinmarketcap[$day])) {
			// fail hard if user has not copied the correct range
			die("Missing coinmarketcap data for day $day");
		}
		
		$avg_value_per_day = $coinmarketcap[$day];
		$valueFiat = $value * $avg_value_per_day;
		
		// detect non-mining transactions
		$is_mining = ($valueFiat > EUR_MINING_MAX) ? false : true;
		$row = ['is_mining' => $is_mining, 'day' => $day, 'avg' => $avg_value_per_day, 'value_crypto' => $value, 'value_fiat' => $valueFiat, 'win_loss'  => $plus_minus ];
		
		// yeah, it's eval.
		$to_eval = "\$r['balance_crypto'] $plus_minus= \$value;";
		eval($to_eval);

		$to_eval = "\$r['balance_fiat'] $plus_minus= \$valueFiat;";
		eval($to_eval);

		if ($is_mining) {
			$to_eval = "\$r['balance_mining_crypto'] $plus_minus= \$value;";
			eval($to_eval);

			$to_eval = "\$r['balance_mining_fiat'] $plus_minus= \$valueFiat;";
			eval($to_eval);
		}
		
		$r['transactions'][] = $row;
	}
	
	return $r;
}

/**
 * Print the structure in a readable way
 * @param $data
 * @param $crypto_currency
 */
function to_console($data, $crypto_currency) {
	for ($i = 0, $m = sizeof($data['transactions']); $i < $m; $i++) {
		$row = $data['transactions'][$i];
		$warn = $row['is_mining'] ? '' : '	NO MINING';
		
		print $row['day'] ."	AVG: " . $row['avg'] . "	Value (" . $crypto_currency . "): " . $row['value_crypto'] . "	" . FIAT . ": " . $row['win_loss'] . "" . $row['value_fiat'] . $warn . "\n";
	}
	
	print "TOTAL MINING: 	" . FIAT . " " . $data['balance_mining_fiat'] . "	$crypto_currency " . $data['balance_mining_crypto'] . "\n";
	print "TOTAL TRANSACTIONS:	" . FIAT . " " . $data['balance_fiat'] . "	$crypto_currency " . $data['balance_mining_fiat'] . "\n\n";
}

// no fancy hashmaps, no classes, just functions and duplicate code
$electrum = electrum($electrum_export);
$etherscan = etherscan($etherscan_export);
$historical_bitcoin_prices = coinmarketcap($coinmarketcap_bitcoin);
$historical_ethereum_prices = coinmarketcap($coinmarketcap_ethereum);

$normalized_bitcoin = build_data($electrum, $historical_bitcoin_prices);
$normalized_ethereum = build_data($etherscan, $historical_ethereum_prices);
to_console($normalized_bitcoin, 'BTC');
to_console($normalized_ethereum, 'ETH');
