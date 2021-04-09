<?php

class Currency
{
    public $ci;
    public $redis;
    public $exchange_rates;

    public $currencies = array(
        'usd' => array('symbol' => '$'),
        'eur' => array('symbol' => '€'),
        'gbp' => array('symbol' => '£'),
    );

    public function __construct()
    {
        $this->ci =& get_instance();

        $redis_ip = $this->ci->config->item('redis_ip');
        $redis_port = $this->ci->config->item('redis_port');
        $redis_ttl = $this->ci->config->item('redis_ttl');

        $this->redis = new Redis();
        $this->redis->connect($redis_ip, $redis_port, $redis_ttl);
    }

    public function get_user_balance($username = null)
    {
        if ($username == null) {
            $username = $this->ci->sessions->get_username();
        }

        $query = $this->ci->db->select('balance_btc, balance_xmr')
            ->where('username', $username)
            ->get('users');

        if ($query->num_rows() > 0) {
            return array(
                'btc' => $query->row(0)->balance_btc,
                'xmr' => $query->row(0)->balance_xmr
            );
        }
        return array('btc' => 0, 'xmr' => 0);
    }

    public function btc_to_fiat($amount, $currency, $show_symbol = false)
    {
        $rate = $this->get_exchange_rates($currency)['btc'];

        foreach ($this->currencies as $item => $value) {
            if ($currency == $item) $symbol = $value['symbol'];
        }

        $new_amount = round($amount * $rate, 2);

        if ($show_symbol)
        {
            if (!isset($symbol)) return '? ' . $new_amount;
            return $symbol . $new_amount;
        }

        return $new_amount;
    }

    public function fiat_to_btc($amount, $currency)
    {
        $rate = $this->get_exchange_rates($currency)['btc'];

        return round($amount / $rate, 6);
    }

    public function xmr_to_fiat($amount, $currency, $show_symbol = false)
    {
        $rate = $this->get_exchange_rates($currency)['xmr'];

        foreach ($this->currencies as $item => $value) {
            if ($currency == $item) $symbol = $value['symbol'];
        }

        $new_amount = round($amount * $rate, 2);

        if ($show_symbol)
        {
            if (!isset($symbol)) return '? ' . $new_amount;
            return $symbol . $new_amount;
        }

        return $new_amount;
    }

    public function get_old_exchange_rates($currency)
    {
        $rates_cache = $this->redis->get('old_exchange_rates');

        if ($rates_cache !== false)
        {
            switch($rates_cache)
            {
                case 'btc':
                    return $rates_cache['btc'];
                    break;
                case 'xmr':
                    return $rates_cache['xmr'];
                    break;
                default:
                    return $rates_cache;
            }
        }

        switch($rates_cache)
        {
            case 'btc':
                return $this->get_rates_from_database('btc');
                break;
            case 'xmr':
                return $this->get_rates_from_database('xmr');
                break;
            default:
                return $this->get_rates_from_database();
                break;
        }
    }

    public function get_rates_from_database($currency = 'both')
    {
        
    }

    public function fiat_to_xmr($amount, $currency)
    {
        $rate = $this->get_exchange_rates($currency)['xmr'];

        return round($amount / $rate, 6);
    }

    public function get_exchange_rates($currency = false)
    {
        if (isset($this->exchange_rates))
        {
            $rates = $this->exchange_rates;
        }
        else
        {
            $rates_cache = $this->redis->get('exchange_rates');

            if ($rates_cache !== false)
            {
                $rates = unserialize($rates_cache);
            }
            else
            {
                $attempts = 0;

                do {
                    $blockchain = $this->get_blockchain();
                    $coindesk = $this->get_coindesk();
                    $monero = $this->get_monero();

                    // request to blockchain and coindesk was successful, get average rate
                    if ($blockchain !== false && $coindesk !== false)
                    {
                        $rates['btc'] = array(
                            'usd' => round(($blockchain['usd'] + $coindesk['usd']) / 2, 2),
                            'eur' => round(($blockchain['eur'] + $coindesk['eur']) / 2, 2),
                            'gbp' => round(($blockchain['gbp'] + $coindesk['gbp']) / 2, 2),
                        );
                    }
                    // only blockchain was successful, return blockchain rate
                    elseif ($blockchain !== false)
                    {
                        $rates['btc'] = $blockchain;
                    }
                    // only coindesk was successful, return coindesk rate
                    elseif ($coindesk !== false)
                    {
                        $rates['btc'] = $coindesk;
                    }
                    // both bitcoin lookups failed
                    else
                    {

                    }

                    if ($monero !== false) {
                        $rates['xmr'] = $monero;
                    }
                    $attempts++;
                } while ($attempts !== 1);

                $this->exchange_rates = $rates;
                $this->redis->set('exchange_rates', serialize($rates), 900);
                $this->redis->set('old_exchange_rates', serialize($rates));
            }
        }

        switch ($currency) {
            case 'usd':
                return array('btc' => $rates['btc']['usd'], 'xmr' => $rates['xmr']['usd']);
            case 'eur':
                return array('btc' => $rates['btc']['eur'], 'xmr' => $rates['xmr']['eur']);
            case 'gbp':
                return array('btc' => $rates['btc']['gbp'], 'xmr' => $rates['xmr']['gbp']);
            default:
                return $rates;
        }
    }

    public function get_recommended_fees($cache = true, $kilobytes = null)
    {
        if ($cache)
        {
            $fees_cache = $this->redis->get('estimated_fees');
            # if the fees are cached, return them, otherwise grab fees
            if ($fees_cache !== false) return unserialize($fees_cache);
        }
        else
        {

        }
        try {
            $client = new GuzzleHttp\Client();

            $response = $client->get('https://bitcoinfees.earn.com/api/v1/fees/recommended');

            $fees = json_decode($response);
            $fees = array(
                # the satoshi per byte fee
                'satoshi' => array(
                    'fastest' => $fees->fastestFee,
                    '30' => $fees->halfHourFee,
                    '60' => $fees->hourFee,
                ),
                ''
            );
        }
        catch (\GuzzleHttp\Exception\RequestException $e)
        {
            log_message('error', 'Could not connect to get recommended fees.. reverting to cache');

        }
    }

    public function get_blockchain()
    {
        $httpClient = new GuzzleHttp\Client();

        try {
            $response = $httpClient->get('http://blockchain.info/ticker');

            if ($response->getStatusCode() == 200) {
                $json_rates = json_decode($response->getBody());

                $rates = array(
                    'usd' => round($json_rates->USD->{'15m'}, 2),
                    'eur' => round($json_rates->EUR->{'15m'}, 2),
                    'gbp' => round($json_rates->GBP->{'15m'}, 2),
                );
                return $rates;
            } else {
                $old_exchange_rates = $this->redis->get('old_exchange_rates');

                if ($old_exchange_rates !== false) {
                    $rates = unserialize($old_exchange_rates)['btc'];
                    return $rates;
                }
                return false;
            }
        } catch (\GuzzleHttp\Exception\RequestException $e) {

        }
    }

    public function get_coindesk()
    {
        $httpClient = new GuzzleHttp\Client();

        try {
            $response = $httpClient->get('https://api.coindesk.com/v1/bpi/currentprice.json');

            if ($response->getStatusCode() == 200) {
                $json_rates = json_decode($response->getBody());

                $rates = array(
                    'usd' => round((float)str_replace(',', '', $json_rates->bpi->USD->rate), 2),
                    'eur' => round((float)str_replace(',', '', $json_rates->bpi->EUR->rate), 2),
                    'gbp' => round((float)str_replace(',', '', $json_rates->bpi->GBP->rate), 2),
                );
                return $rates;
            } else {
                $old_exchange_rates = $this->redis->get('old_exchange_rates');

                if ($old_exchange_rates !== false) {
                    $rates = unserialize($old_exchange_rates)['btc'];
                    return $rates;
                }
                return false;
            }
        } catch (\GuzzleHttp\Exception\RequestException $e) {

        }
    }

    public function get_monero()
    {
        $httpClient = new GuzzleHttp\Client();

        try {
            $usd_response = $httpClient->get('https://min-api.cryptocompare.com/data/price?fsym=XMR&tsyms=USD');

            if ($usd_response->getStatusCode() == 200) {
                $json_rates = json_decode($usd_response->getBody());

                $rates['usd'] = $json_rates->{'USD'};
            }

            $eur_response = $httpClient->get('https://min-api.cryptocompare.com/data/price?fsym=XMR&tsyms=EUR');

            if ($eur_response->getStatusCode() == 200) {
                $json_rates = json_decode($eur_response->getBody());

                $rates['eur'] = $json_rates->{'EUR'};
            }
            $gbp_response = $httpClient->get('https://min-api.cryptocompare.com/data/price?fsym=XMR&tsyms=GBP');

            if ($gbp_response->getStatusCode() == 200) {
                $json_rates = json_decode($gbp_response->getBody());

                $rates['gbp'] = $json_rates->{'GBP'};
            }

            if (!isset($rates)) return false;
            return $rates;
        }
        catch (\GuzzleHttp\Exception\RequestException $e)
        {
            return $this->get_old_exchange_rates('xmr');
        }
    }
}