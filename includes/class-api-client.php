<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit( 'restricted access' );
}

if ( ! class_exists( 'WC_API_MPS' ) ) {
    class WC_API_MPS {

        private $url;
        private $site_url;
        private $consumer_key;
        private $consumer_secret;
        private $logger;

        public function __construct( $url, $consumer_key, $consumer_secret ) {
            $this->url             = rtrim( $url, '/' ) . '/wp-json/wc/v3';
            $this->site_url        = $url;
            $this->consumer_key    = $consumer_key;
            $this->consumer_secret = $consumer_secret;
            $this->logger          = RPS_Logger::instance();
        }

        public function get_site_url() {
            return $this->site_url;
        }

        /**
         * Metodo centralizzato per tutte le chiamate API.
         * Riduce 1500 righe a ~50.
         */
        private function request( $method, $endpoint, $data = null, $caller = '' ) {
            $authorization = get_option( 'wc_api_mps_authorization' );
            $url = $this->url . $endpoint;
            $header = array( 'Content-Type' => 'application/json' );

            if ( $authorization == 'query' ) {
                $separator = ( strpos( $url, '?' ) !== false ) ? '&' : '?';
                $url .= $separator . http_build_query( array(
                    'consumer_key'    => $this->consumer_key,
                    'consumer_secret' => $this->consumer_secret,
                ) );
            } else {
                $header['Authorization'] = 'Basic ' . base64_encode( $this->consumer_key . ':' . $this->consumer_secret );
            }

            $args = array(
                'method'      => strtoupper( $method ),
                'timeout'     => 120,
                'httpversion' => '1.0',
                'headers'     => $header,
                'sslverify'   => false,
            );

            if ( $data !== null ) {
                $args['body'] = wp_json_encode( $data );
            }

            $log_context       = array( 'store_url' => $this->site_url );
            $max_attempts      = 3;
            $retryable_codes   = array( 500, 502, 503, 529 );
            $wp_response       = null;
            $response_code     = null;

            for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
                $is_last_attempt = ( $attempt === $max_attempts );

                // Execute
                if ( strtoupper( $method ) === 'GET' ) {
                    $wp_response = wp_remote_get( $url, $args );
                } elseif ( strtoupper( $method ) === 'DELETE' ) {
                    $wp_response = wp_remote_request( $url, $args );
                } else {
                    $wp_response = wp_remote_post( $url, $args );
                }

                // Handle WP error (timeout, connection error) — retryable
                if ( is_wp_error( $wp_response ) ) {
                    if ( ! $is_last_attempt ) {
                        $wait = pow( 2, $attempt ); // 2s, 4s
                        $this->logger->warning( $caller ?: 'api_request', sprintf(
                            'Tentativo %d/%d fallito (WP_Error: %s). Retry tra %d secondi - URL: %s',
                            $attempt,
                            $max_attempts,
                            $wp_response->get_error_message(),
                            $wait,
                            $url
                        ), $log_context );
                        sleep( $wait );
                        continue;
                    }

                    // Final attempt — log error and return null
                    $this->logger->error( $caller ?: 'api_request', sprintf(
                        'Errore di connessione: %s (Codice: %s) - URL: %s',
                        $wp_response->get_error_message(),
                        $wp_response->get_error_code(),
                        $url
                    ), array_merge( $log_context, array(
                        'request_data'  => $data,
                        'response_data' => $wp_response->get_error_message(),
                    ) ) );
                    return null;
                }

                $response_code = wp_remote_retrieve_response_code( $wp_response );

                // Retry on specific server-side HTTP codes
                if ( in_array( (int) $response_code, $retryable_codes, true ) && ! $is_last_attempt ) {
                    $wait = pow( 2, $attempt ); // 2s, 4s
                    $this->logger->warning( $caller ?: 'api_request', sprintf(
                        'Tentativo %d/%d fallito (HTTP %d). Retry tra %d secondi - URL: %s',
                        $attempt,
                        $max_attempts,
                        $response_code,
                        $wait,
                        $url
                    ), $log_context );
                    sleep( $wait );
                    continue;
                }

                // Not retryable or last attempt — exit loop
                break;
            }

            $body     = wp_remote_retrieve_body( $wp_response );
            $response = json_decode( $body );

            // HTTP errors
            if ( $response_code >= 400 ) {
                $this->logger->error( $caller ?: 'api_request', sprintf(
                    'Errore HTTP %d - URL: %s',
                    $response_code,
                    $url
                ), array_merge( $log_context, array(
                    'request_data'  => $data,
                    'response_data' => $body,
                ) ) );
            }

            // WC API errors
            if ( isset( $response->code ) && isset( $response->message ) ) {
                $this->logger->error( $caller ?: 'api_request', sprintf(
                    'Errore API: %s - %s - URL: %s',
                    $response->code,
                    $response->message,
                    $url
                ), array_merge( $log_context, array(
                    'request_data'  => $data,
                    'response_data' => $body,
                ) ) );
            }

            // Log completo anche per chiamate riuscite (per debug)
            if ( $response_code >= 200 && $response_code < 400 && $data !== null ) {
                $this->logger->info( $caller ?: 'api_request', sprintf(
                    'HTTP %d - %s %s',
                    $response_code,
                    strtoupper( $method ),
                    $endpoint
                ), array_merge( $log_context, array(
                    'request_data'  => $data,
                    'response_data' => $body,
                ) ) );
            }

            // 404 special handling
            if ( $response_code == 404 ) {
                return (object) array( 'code' => 404 );
            }

            return $response;
        }

        // ──── Products ────

        public function authentication() {
            $response = $this->request( 'GET', '/products?per_page=1', null, 'authentication' );
            if ( $response === null ) {
                return (object) array( 'code' => 'connection_error' );
            }
            return $response;
        }

        public function getProducts( $search ) {
            $sync_by = get_option( 'wc_api_mps_old_products_sync_by' );
            $param = ( $sync_by == 'sku' ) ? 'sku' : 'slug';
            return $this->request( 'GET', "/products?{$param}=" . urlencode( $search ), null, 'getProducts' );
        }

        public function getProduct( $product_id ) {
            return $this->request( 'GET', "/products/{$product_id}", null, 'getProduct' );
        }

        public function addProduct( $data ) {
            $response = $this->request( 'POST', '/products', $data, 'addProduct' );
            if ( $response && isset( $response->id ) ) {
                $this->logger->info( 'addProduct', "Prodotto creato con successo", array(
                    'store_url'  => $this->site_url,
                    'product_id' => $response->id,
                ) );
            }
            return $response;
        }

        public function updateProduct( $data, $product_id ) {
            $response = $this->request( 'POST', "/products/{$product_id}", $data, 'updateProduct' );
            if ( $response && isset( $response->id ) ) {
                $this->logger->info( 'updateProduct', "Prodotto aggiornato con successo", array(
                    'store_url'  => $this->site_url,
                    'product_id' => $product_id,
                ) );
            }
            return $response;
        }

        public function deleteProduct( $product_id, $force = 0 ) {
            $endpoint = "/products/{$product_id}" . ( $force ? '?force=true' : '' );
            $response = $this->request( 'DELETE', $endpoint, null, 'deleteProduct' );
            if ( $response && isset( $response->id ) ) {
                $this->logger->info( 'deleteProduct', "Prodotto eliminato", array(
                    'store_url'  => $this->site_url,
                    'product_id' => $product_id,
                ) );
            }
            return $response;
        }

        // ──── Variations ────

        public function getProductVariations( $product_id ) {
            return $this->request( 'GET', "/products/{$product_id}/variations?per_page=100", null, 'getProductVariations' );
        }

        public function getProductVariation( $product_id, $variation_id ) {
            return $this->request( 'GET', "/products/{$product_id}/variations/{$variation_id}", null, 'getProductVariation' );
        }

        public function addProductVariation( $data, $product_id ) {
            $response = $this->request( 'POST', "/products/{$product_id}/variations", $data, 'addProductVariation' );
            if ( $response && isset( $response->id ) ) {
                $this->logger->info( 'addProductVariation', "Variazione creata", array(
                    'store_url'  => $this->site_url,
                    'product_id' => $product_id,
                ) );
            }
            return $response;
        }

        public function updateProductVariation( $data, $product_id, $variation_id ) {
            $response = $this->request( 'POST', "/products/{$product_id}/variations/{$variation_id}", $data, 'updateProductVariation' );
            if ( $response && isset( $response->id ) ) {
                $this->logger->info( 'updateProductVariation', "Variazione aggiornata", array(
                    'store_url'  => $this->site_url,
                    'product_id' => $product_id,
                ) );
            }
            return $response;
        }

        public function deleteProductVariation( $product_id, $variation_id, $force = 0 ) {
            $endpoint = "/products/{$product_id}/variations/{$variation_id}" . ( $force ? '?force=true' : '' );
            return $this->request( 'DELETE', $endpoint, null, 'deleteProductVariation' );
        }

        // ──── Categories ────

        public function getCategories( $slug ) {
            return $this->request( 'GET', '/products/categories?slug=' . urlencode( $slug ), null, 'getCategories' );
        }

        public function getCategory( $category_id ) {
            return $this->request( 'GET', "/products/categories/{$category_id}", null, 'getCategory' );
        }

        public function addCategory( $data ) {
            return $this->request( 'POST', '/products/categories', $data, 'addCategory' );
        }

        public function updateCategory( $data, $category_id ) {
            return $this->request( 'POST', "/products/categories/{$category_id}", $data, 'updateCategory' );
        }

        // ──── Tags ────

        public function getTags( $slug ) {
            return $this->request( 'GET', '/products/tags?slug=' . urlencode( $slug ), null, 'getTags' );
        }

        public function getTag( $tag_id ) {
            return $this->request( 'GET', "/products/tags/{$tag_id}", null, 'getTag' );
        }

        public function addTag( $data ) {
            return $this->request( 'POST', '/products/tags', $data, 'addTag' );
        }

        public function updateTag( $data, $tag_id ) {
            return $this->request( 'POST', "/products/tags/{$tag_id}", $data, 'updateTag' );
        }

        // ──── Attributes ────

        public function getAttributes() {
            return $this->request( 'GET', '/products/attributes', null, 'getAttributes' );
        }

        public function addAttribute( $data ) {
            return $this->request( 'POST', '/products/attributes', $data, 'addAttribute' );
        }

        public function updateAttribute( $data, $attribute_id ) {
            return $this->request( 'POST', "/products/attributes/{$attribute_id}", $data, 'updateAttribute' );
        }

        // ──── Attribute Terms ────

        public function getAttributeTerms( $slug, $attribute_id ) {
            return $this->request( 'GET', "/products/attributes/{$attribute_id}/terms?slug=" . urlencode( $slug ), null, 'getAttributeTerms' );
        }

        public function getAttributeTerm( $term_id, $attribute_id ) {
            return $this->request( 'GET', "/products/attributes/{$attribute_id}/terms/{$term_id}", null, 'getAttributeTerm' );
        }

        public function addAttributeTerm( $data, $attribute_id ) {
            return $this->request( 'POST', "/products/attributes/{$attribute_id}/terms", $data, 'addAttributeTerm' );
        }

        public function updateAttributeTerm( $data, $term_id, $attribute_id ) {
            return $this->request( 'POST', "/products/attributes/{$attribute_id}/terms/{$term_id}", $data, 'updateAttributeTerm' );
        }

        // ──── Brands ────

        public function getBrands( $slug ) {
            return $this->request( 'GET', '/products/brands?slug=' . urlencode( $slug ), null, 'getBrands' );
        }

        public function getBrand( $brand_id ) {
            return $this->request( 'GET', "/products/brands/{$brand_id}", null, 'getBrand' );
        }

        public function addBrand( $data ) {
            return $this->request( 'POST', '/products/brands', $data, 'addBrand' );
        }

        public function updateBrand( $data, $brand_id ) {
            return $this->request( 'POST', "/products/brands/{$brand_id}", $data, 'updateBrand' );
        }

        // ──── Media ────

        public function getMedias( $search ) {
            $media_url = str_replace( 'wc/v3', 'wp/v2', $this->url );
            // We need to use the base request logic but with a different base URL
            // So we temporarily swap
            $original_url = $this->url;
            $this->url = $media_url;
            $response = $this->request( 'GET', '/media?slug=' . urlencode( $search ), null, 'getMedias' );
            $this->url = $original_url;
            return $response;
        }
    }
}
