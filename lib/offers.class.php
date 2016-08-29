<?php

require_once( __DIR__ . '/settings.class.php' );
require_once( __DIR__ . '/strict_mode.funcs.php' );
require_once( __DIR__ . '/currencies.class.php' );

class offers {
    function __construct() {
    }
    
    /**
     * criteria keys:
     *  + market: eg 'dash_btc', or 'all'
     *  + datetime_from: timestamp utc
     *  + datetime_to: timestamp utc
     *  + direction: 'buy', 'sell'
     *  + limit: max offers
     *  + sort: asc | desc.  default = asc
     *  + integeramounts: bool.  default = true.
     *  + fields: array -- fields to return.
     *      available:  "currency", "direction", "price", "amount",
     *                  "date",
     *                  "useMarketBasedPrice", "marketPriceMargin",
     *                  "amount", "minAmount", "id",
     *                  "offerFeeTxId"
     */
    public function get_offers( $criteria ) {

        $offers = $this->get_all_offers();
        extract( $criteria );  // puts keys in local namespace.

        $sort = @$sort ?: 'desc';
        $dtfrom_milli = @$datetime_from * 1000;
        $dtto_milli = @$datetime_to * 1000;
        $limit = @$limit ?: PHP_INT_MAX;
        $integeramounts = @$integeramounts ?: true;
        
        $matches = [];
        foreach( $offers as $offer ) {
            if( @$market && $market != $offer['market']) {
                continue;
            }
            if( $dtfrom_milli && $dtfrom_milli > $offer['date']) {
                continue;
            }
            if( $dtto_milli && $dtto_milli < $offer['date']) {
                continue;
            }
            if( @$direction && $direction != $offer['direction'] ) {
                continue;
            }

            if( !@$integeramounts ) {
                $offer['price'] = btcutil::int_to_money4( $offer['price'] );
                $offer['amount'] = btcutil::int_to_money4( $offer['amount'] );
                $offer['minAmount'] = btcutil::int_to_btc( $offer['minAmount'] );
            }
            
            // convert to user specified field order list, if present.
            if( @$fields ) {
                $t = [];
                foreach( $fields as $f ) {
                    $t[$f] = @$offer[$f] ?: null;
                    $offer = $t;
                }
            }
            
            $matches[] = $offer;
            
            if( count($matches) >= $limit ) {
                break;
            }
        }
        

        if( $sort == 'asc') {
            $matches = array_reverse( $matches );
        }
        
        return $matches;
    }
    
    public function get_all_offers() {
        $json_file = settings::get('data_dir') . '/offers_statistics.json';

        // in case apcu is not installed.   ( slow )
        if( !function_exists( 'apcu_fetch' ) ) {
            // cache in mem for present request.
            static $result = null;
            static $warned = false;
            if( !$warned ) {
                error_log( "Warning: APCu not found. Please install APCu extension for better performance." );
                $warned = true;
            }
            
            if( $result ) {
                return $result;
            }
            $result = $this->get_all_offers_worker($json_file);
            return $result;
        }
        
        $result_key = 'all_offers_result';
        $ts_key = 'all_offers_timestamp';

        // We prefer to use apcu_entry if existing, because it is atomic.        
        if( function_exists( 'apcu_entry' ) ) {
            // note:  this case is untested!!!  my version of apcu is too old.
            $cached_ts = apcu_entry( $ts_key, function($key) { return time(); } );
            
            // invalidate cache if file on disk is newer than cached value.
            if( filemtime( $json_file ) > $cached_ts ) {
                apcu_delete( $result_key );
            }
            return apcu_entry( $result_key, function($key) use($json_file) {
                return $this->get_all_offers_worker($json_file);
            });
        }
        
        // Otherwise, use apcu_fetch, apcu_store.
        $cached_ts = apcu_fetch( $ts_key );
        $cached_result = apcu_fetch( $result_key );
        if( $cached_result && $cached_ts && filemtime( $json_file ) < $cached_ts ) {
            $result = $cached_result;
        }
        else {
            $result = $this->get_all_offers_worker($json_file);
            apcu_store( $ts_key, time() );
            apcu_store( $result_key, $result );
        }
        return $result;
    }
        
    private function get_all_offers_worker($json_file) {
        
        // only needed to determine if currency is fiat or not.
        $currencies = new currencies();
        $currlist = $currencies->get_all_currencies();
        
        // remove some garbage data at beginning of file, if present.
        $fh = fopen( $json_file, 'r' );
        
        // we use advisory locking.  hopefully bitsquare does too?
        if( !$fh || !flock( $fh, LOCK_SH ) ) {
            bail( 500, "Internal Server Error" );
        }
        $buf = stream_get_contents( $fh );
        fclose( $fh );
        
        $start = strpos( $buf, "\n")-1;
        $data = json_decode( substr($buf, $start), true );

        // add market key        
        foreach( $data as $idx => &$offer ) {
            
            // change currencyCode to currency, to match trades class.
            $curr = $offer['currency'] = $offer['currencyCode'];
            unset( $offer['currencyCode'] );

            list($left, $right) = explode('/', $offer['currencyPair'] );
            $cleft = @$currlist[$left];
            $cright = @$currlist[$right];
            if( !$cleft || !$cright ) {
                unset( $data[$idx]);
                continue;
            }

            // Here we normalize integers to 8 units of precision. calling code depends on this.
            // note: all currencies are presently specified with 8 units of precision in json files
            // but this has not always been the case and could change in the future.
            $offer['price'] = $offer['primaryMarketPrice'] * pow( 10, 8 - $cright['precision'] );
            $offer['amount'] = $offer['primaryMarketAmount'] * pow( 10, 8 - $cleft['precision'] );
            $offer['volume'] = $offer['primaryMarketVolume'] * pow( 10, 8 - $cright['precision'] );
            $offer['market'] = strtolower( str_replace( '/', '_', $offer['currencyPair'] ) );
            
            // trade direction is given to us Bitcoin-centric.  Here we make it refer to the left side of the market pair.
            $offer['direction'] = $offer['primaryMarketDirection'];
        }
        return $data;
    }
}
