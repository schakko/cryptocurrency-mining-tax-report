# Create your crypto currency mining Einnahmenüberschussrechnung / EÜR for the German Finanzamt

This PHP script uses 4 CSV files to create the Einnahmenüberschussrechnung for the German Finanzamt. It is only interesting if you are doing crypto mining.
Create the four CSV files when you are doing your tax declaration, execute the script and add the output of the script to your tax declaration.

## Install
See `create_crypto_tax_report.php` how to create the four CSV files. I am using Electrum for BTC, Etherscan.io for Ethereum and Coinmarketcap for historical data.

## Execute

Run

	php create_crypto_tax_report.php
	
to create a readable overview.

If you need an Excel-compatible CSV format, use the *csv* parameter:

	php create_crypto_tax_report.php csv > result_2017.csv

You can import the CSV with Excel.