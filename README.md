# MoneroWHMCS (PHP 8 / WHMCS 8.x and 9.x compatibility fix)

A fork of [monero-integrations/monerowhmcs](https://github.com/monero-integrations/monerowhmcs)
that makes the Monero payment gateway work again on current WHMCS. Tested on WHMCS 8.13.4 and
9.0.5, which run on PHP 8.x.

The original design is kept as it was: fully self-hosted, talking to your own `monero-wallet-rpc`
and daemon, integrated address plus payment id, and a QR or copiable address at checkout. Nothing
about how payments work was redesigned. This addresses the Monero bounty
["Fix WHMCS Payment Gateway for Monero"](https://bounties.monero.social/posts/158).

## What was broken

The last release of the original targeted WHMCS 7.2 and PHP 5/7. On the PHP 8.x that current WHMCS
runs on, it is non-functional. Two of these are hard fatals, and one has nothing to do with PHP but
stops payments from being priced:

1. `money_format()` (in `monero.php`, when rendering the pay page) was removed in PHP 8.0. The
   gateway throws `Call to undefined function money_format()` the moment a customer opens the
   invoice. This is the main reason the gateway looks dead once it is enabled.
2. `FILTER_SANITIZE_STRING` (`createinvoice.php` and `verify.php`) was deprecated in PHP 8.1.
3. `verify.php` read `$record[0]->transid` without checking the row exists, which is a fatal on
   PHP 8 whenever the transaction has not been recorded yet.
4. The price feed (cryptocompare's free endpoint) now returns HTTP 401 and requires an API key, so
   the fiat to XMR conversion got no rate and hit a division by zero.

## What was fixed

- `money_format()` replaced with `number_format()`.
- `FILTER_SANITIZE_STRING` replaced with `FILTER_UNSAFE_RAW`; checkout inputs that are printed on
  the payment page are reduced to the characters they can legitimately contain, and the verify poll
  keeps the existing callback hash check for the payment fields.
- The `tblaccounts` lookup uses `first()` with a null check.
- The price feed now uses CoinGecko (free, no key) with a Kraken fallback for USD, EUR and BTC,
  because CoinGecko's free endpoint blocks some datacenter IPs and WHMCS often runs on a VPS.

Each change is a small, in-place edit with a comment explaining why. The wallet-rpc connection, the
integrated-address flow, the payment-detection logic and the checkout flow all work the same way;
only these PHP 8 compatibility shims were applied to them.

## Two branches

The original drew the checkout QR by loading an image from a third-party API
(`api.qrserver.com`), which sends the payment address and amount to an outside service. That goes
against the self-hosted, no-third-parties goal, so it could not stay. There are two branches so you
can pick the trade-off you prefer:

- **`fix-php8-no-qr`** removes the third-party QR and keeps the copiable address. Smallest change.
- **`fix-php8-local-qr`** keeps a QR, but serves a small bundled MIT library
  ([qrcode-generator](https://github.com/kazuhikoarase/qrcode-generator)) and generates it in the
  customer's browser. The payment address and amount no longer go to a third-party QR service.

Both satisfy "QR or copiable address" with no third-party call.

## Evidence

See [docs/EVIDENCE.md](docs/EVIDENCE.md). The admin setup, checkout render, integrated-address
creation, verify poll, and invoice-credit path were tested on WHMCS 8.13.4 and 9.0.5 (PHP 8.3) in
Docker, with `monero-wallet-rpc` running on stagenet.

## Install

1. Copy `modules/gateways/monero.php` and the `modules/gateways/monero/` folder into your WHMCS
   `modules/gateways/` directory.
2. Optionally copy `modules/addons/moneroenable/` into `modules/addons/` for the fraud-check helper.
3. In WHMCS go to Configuration, System Settings, Payment Gateways, and activate Monero.
4. Set your `monero-wallet-rpc` host, port and login (if you use one) and a secret key.
5. Run `monero-wallet-rpc` against your own daemon, the same as before.

## Requirements

PHP 7.4 to 8.3, WHMCS 8.x or 9.x, and a self-hosted `monerod` plus `monero-wallet-rpc`.

## Credit

This is a fork of `monero-integrations/monerowhmcs` (MIT). All of the original work is by the
monero-integrations contributors. This fork only adds the compatibility fixes listed above.
