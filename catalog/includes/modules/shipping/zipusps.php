<?php
    

/*
* $Id: zipusps.php
* $Loc: /includes/modules/shipping/
*
* Name: ZipShippingUSPS
* Version: 1.6.0
* Release Date: 
* Author: Preston Lord
* 	 phoenixaddons.com / @zipurman / plord@inetx.ca
*
* License: Released under the GNU General Public License
*
* Comments: Copyright (c) 2024: Preston Lord - @zipurman - Intricate Networks Inc.
* 
* 
*   Redistribution and use in source and binary forms, with or without modification, are permitted provided that the following conditions are met:
* 
*   1. Re-distributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
* 
*   2. Re-distributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.
* 
*   3. Neither the name of the copyright holder nor the names of its contributors may be used to endorse or promote products derived from this software without specific prior written permission.
* 
*   THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
*
* (Packaged with Zipur Bundler v2.2.0)
*/





    /**
     * Class zipusps
     */
    class zipusps extends abstract_shipping_module {

        const CONFIG_KEY_BASE = 'MODULE_SHIPPING_ZIP_USPS_';

        private $shipment_details = [];
        private $api_connection = [];

        public function __construct() {

            global $order;

            parent::__construct();

            if ( ! empty( $order ) ) {

                //define api connection
                $this->api_connection ['protocol'] = 'https';
                $this->api_connection ['host']     = 'secure.shippingapis.com/';
                $this->api_connection ['port']     = '443';
                $this->api_connection ['path']     = 'ShippingAPI.dll';

                //start shipment definition
                $this->shipment_details['shipment'] = [
                    'insure_shipment' => MODULE_SHIPPING_ZIP_USPS_INSURE == 'Yes',
                    'commercialrates' => MODULE_SHIPPING_ZIP_USPS_COMMERCIALRATES == 'Yes',
                    'insure_limits'   => MODULE_SHIPPING_ZIP_USPS_INSURELIMITS,
                    'international'   => MODULE_SHIPPING_ZIP_USPS_INTERNATIONAL,
                    'total_value'     => number_format( ceil( $order->info['subtotal'] ), 2, '.', '' ),
                    'currency'        => $order->info['currency'],
                ];
                $this->shipment_details['quote']    = [];

                //used by abstract_shipping_module
                $this->tax_class = MODULE_SHIPPING_ZIP_USPS_TAX_CLASS;
            }
        }

        /**
         * @param $query
         * @param $link
         *
         * @return mixed
         */
        public function db_query( $query, $link = 'db' ) {

            if ( version_compare( '1.0.8.19', $this->phoenix_version() ) > 0 ) {
                return tep_db_query( $query );
            } else {
                return $GLOBALS[ $link ]->query( $query );
            }

        }

        /**
         * @param $db_query
         *
         * @return mixed
         */
        function fetch_array( $db_query ) {

            if ( version_compare( '1.0.8.19', $this->phoenix_version() ) > 0 ) {
                return tep_db_fetch_array( $db_query );
            } else {
                return $db_query->fetch_assoc();
            }

        }

        /**
         * @param $string
         * @param $link
         *
         * @return mixed
         */
        function db_input( $string, $link = 'db' ) {

            if ( version_compare( '1.0.8.19', $this->phoenix_version() ) > 0 ) {
                return tep_db_input( $string, $link );
            } else {
                return $GLOBALS[ $link ]->real_escape_string( $string );
            }

        }

        /**
         * @param $src
         *
         * @return string
         */
        function image( $src) {

            if ( version_compare( '1.0.8.19', $this->phoenix_version() ) > 0 ) {
                return tep_image( $src);
            } else {
                $image = new Image( $src );

                return "$image";

            }
        }

        /**
         * @return string
         */
        function phoenix_version() {

            return trim( file_get_contents( DIR_FS_CATALOG . 'includes/version.php' ) );
        }

        /**
         * @param $shipping_method
         *
         * @return array
         */
        public function quote( $shipping_method ) {

            $this->calcWeight();
            $this->setOrigin();
            $this->setDestination();
            $this->getUSPSQuote();

            //after selection - limit to selected
            if ( ! empty( $shipping_method ) ) {
                foreach ( $this->quotes['methods'] as $item ) {
                    if ( $item['id'] == $shipping_method ) {
                        $this->quotes['methods'] = [ $item ];
                    }
                }
            }

            //abstract_shipping_module - abstract_zoneable_module will take care of ZONE/TAX
            $this->quote_common();

            if ( ! empty( $this->quotes ) && ! empty( $this->quotes['methods'] ) ) {
                return $this->quotes;
            } else {
                //added for pre-login freight estimators
                $this->quotes['methods'] = [];

                return $this->quotes;
            }

        }

        /**
         * @return void
         */
        private function getUSPSQuote() {

            $shipdate = date_create();
            date_add( $shipdate, date_interval_create_from_date_string( MODULE_SHIPPING_ZIP_USPS_TURNAROUNDTIME . ' day' ) );
            $shipdate_text = date_format( $shipdate, 'Y-m-d' );

            $service_types = MODULE_SHIPPING_ZIP_USPS_SERVICES;
            $service_types = str_replace( '--none--', '', $service_types );
            $service_types = explode( ';', $service_types );
            foreach ( $service_types as $key => $service_type ) {
                if ( $service_type == '' ) {//could be 0 so dont use !empty
                    unset( $service_types[ $key ] );
                } else {

                    $service_types[ $key ] = $this->zip_get_usps_service_code( $service_type );
                }
            }

            $services   = $service_types;
            $stop_dupes = [];

            //DOMESTIC
            foreach ( $services as $service ) {
                if ( $this->shipment_details['destination']['country'] == 'US' ) {
                    if ( empty( $stop_dupes[ $service ] ) ) {
                        $xml    = $this->calcDomesticRequestXML( $service, $shipdate_text );
                        $result = $this->callUSPSAPI( $xml, 'RateV4' );
                        $this->processDomesticUSPSRate( $result );
                        $stop_dupes[ $service ] = 1;
                    }
                }
            }

            //INTERNATIONAL
            if ( $this->shipment_details['shipment']['international'] != 'No' && $this->shipment_details['destination']['country'] != 'US' ) {
                $xml    = $this->calcInternationalRequestXML( 'ALL', $shipdate_text );
                $result = $this->callUSPSAPI( $xml, 'IntlRateV2' );
                $this->processInternationalUSPSRate( $result );
            }


            $this->sortRates( $this->quotes['methods'], 'cost', SORT_ASC );
            $this->quotes['icon'] = $this->image( 'images/icons/shipping_USPS.png' );

        }

        /**
         * @param $servicetype
         * @param $shipdate_text
         *
         * @return string
         */
        private function calcInternationalRequestXML( $servicetype, $shipdate_text ) {

            global $shipping_num_boxes;

            $xmlRequest = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
            $xmlRequest .= '<IntlRateV2Request USERID="' . MODULE_SHIPPING_ZIP_USPS_USER_ID . (! empty($this->base_constant('PASSWORD')) ? '" PASSWORD="' . MODULE_SHIPPING_ZIP_USPS_PASSWORD : '') . '">' . PHP_EOL;
            $xmlRequest .= '<Revision>2</Revision>' . PHP_EOL;

            //PACKAGE START
            if ( $this->shipment_details['shipment'] ['weight'] >= 1 ) {
                $boxweight_lbs = ceil( $this->shipment_details['shipment'] ['weight'] / $shipping_num_boxes );
                $boxweight_oz  = 0;
            } else {
                $boxweight_lbs = 0;
                $boxweight_oz  = round( 16 * $this->shipment_details['shipment'] ['weight'], 1 );
            }

            $xmlRequest .= '<Package ID="0">' . PHP_EOL;
            $xmlRequest .= '<Pounds>' . $boxweight_lbs . '</Pounds>' . PHP_EOL;
            $xmlRequest .= '<Ounces>' . $boxweight_oz . '</Ounces>' . PHP_EOL;

            $container = ( MODULE_SHIPPING_ZIP_USPS_CONTAINER == '--none--' ) ? '' : MODULE_SHIPPING_ZIP_USPS_CONTAINER;

            if ( strpos( $container, 'ENVELOPE' ) !== false ) {
                $xmlRequest .= '<Machinable>TRUE</Machinable>' . PHP_EOL;
            } else {
                $xmlRequest .= '<Machinable>FALSE</Machinable>' . PHP_EOL;
            }

            if ( strpos( $container, 'ENVELOPE' ) !== false ) {
                $xmlRequest .= '<MailType>LETTER</MailType>' . PHP_EOL;
            } else {
                $xmlRequest .= '<MailType>PACKAGE</MailType>' . PHP_EOL;
            }

            if ( strpos( $this->shipment_details['destination']['address'], 'PO BOX' ) !== false || strpos( $this->shipment_details['destination']['address'], 'P.O. BOX' ) !== false || strpos( $this->shipment_details['destination']['address'], 'BOX ' ) !== false ) {
                $xmlRequest .= '<GXG>' . PHP_EOL;
                $xmlRequest .= '<POBoxFlag>Y</POBoxFlag>' . PHP_EOL;
                $xmlRequest .= '</GXG>' . PHP_EOL;
            }

            $xmlRequest .= '<ValueOfContents>' . $this->shipment_details['shipment']['total_value'] . '</ValueOfContents>' . PHP_EOL;
            $xmlRequest .= '<Country>' . $this->shipment_details['destination']['country_name'] . '</Country>' . PHP_EOL;
            $xmlRequest .= '<Width></Width>' . PHP_EOL;
            $xmlRequest .= '<Length></Length>' . PHP_EOL;
            $xmlRequest .= '<Height></Height>' . PHP_EOL;
            $xmlRequest .= '<Girth></Girth>' . PHP_EOL;

            $xmlRequest .= '<OriginZip>' . $this->shipment_details['origin']['postal_code'] . '</OriginZip>' . PHP_EOL;

            if ( $this->shipment_details['shipment']['international'] == 'Commercial' ) {
                $xmlRequest .= '<CommercialFlag>Y</CommercialFlag>' . PHP_EOL;
            } else if ( $this->shipment_details['shipment']['international'] == 'Commercial Plus' ) {
                $xmlRequest .= '<CommercialPlusFlag>Y</CommercialPlusFlag>' . PHP_EOL;
            } else if ( $this->shipment_details['shipment']['international'] == 'Basic' ) {
                $xmlRequest .= '<CommercialFlag>N</CommercialFlag>' . PHP_EOL;
            }

            $xmlRequest .= '<AcceptanceDateTime>' . $shipdate_text . 'T14:30:00-06:00</AcceptanceDateTime>' . PHP_EOL;
            $xmlRequest .= '<DestinationPostalCode>' . $this->shipment_details['destination']['postal_code'] . '</DestinationPostalCode>' . PHP_EOL;

            $xmlRequest .= '</Package>' . PHP_EOL;

            //PACKAGE END

            $xmlRequest .= '</IntlRateV2Request>';

            return $xmlRequest;
        }

        /**
         * @param $servicetype
         * @param $shipdate_text
         *
         * @return string
         */
        private function calcDomesticRequestXML( $servicetype, $shipdate_text ) {

            global $shipping_num_boxes;

            $xmlRequest = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
            $xmlRequest .= '<RateV4Request USERID="' . MODULE_SHIPPING_ZIP_USPS_USER_ID . (! empty($this->base_constant('PASSWORD')) ? '" PASSWORD="' . MODULE_SHIPPING_ZIP_USPS_PASSWORD : '') . '">' . PHP_EOL;
            $xmlRequest .= '<Revision>2</Revision>' . PHP_EOL;

            //PACKAGE START
            if ( $this->shipment_details['shipment'] ['weight'] >= 1 ) {
                $boxweight_lbs = ceil( $this->shipment_details['shipment'] ['weight'] / $shipping_num_boxes );
                $boxweight_oz  = 0;
            } else {
                $boxweight_lbs = 0;
                $boxweight_oz  = round( 16 * $this->shipment_details['shipment'] ['weight'], 1 );
            }

            $xmlRequest .= '<Package ID="0">' . PHP_EOL;
            $xmlRequest .= '<Service>' . $servicetype . '</Service>' . PHP_EOL;

            $first_class_type = '';
            if ( strpos( $servicetype, 'First Class' ) !== false && strpos( $servicetype, 'Commercial' ) !== false ) {
                $first_class_type = 'PACKAGE SERVICE';
            } else if ( strpos( $servicetype, 'First Class' ) !== false ) {
                $first_class_type = 'LETTER';
            }

            if ( ! empty( $first_class_type ) ) {
                $xmlRequest .= '<FirstClassMailType>' . $first_class_type . '</FirstClassMailType>' . PHP_EOL;
            }

            $container = ( MODULE_SHIPPING_ZIP_USPS_CONTAINER == '--none--' ) ? '' : MODULE_SHIPPING_ZIP_USPS_CONTAINER;

            $xmlRequest .= '<ZipOrigination>' . $this->shipment_details['origin']['postal_code'] . '</ZipOrigination>' . PHP_EOL;
            $xmlRequest .= '<ZipDestination>' . $this->shipment_details['destination']['postal_code'] . '</ZipDestination>' . PHP_EOL;
            $xmlRequest .= '<Pounds>' . $boxweight_lbs . '</Pounds>' . PHP_EOL;
            $xmlRequest .= '<Ounces>' . $boxweight_oz . '</Ounces>' . PHP_EOL;
            $xmlRequest .= '<Container>' . $container . '</Container>' . PHP_EOL;
            $xmlRequest .= '<Width></Width>' . PHP_EOL;
            $xmlRequest .= '<Length></Length>' . PHP_EOL;
            $xmlRequest .= '<Height></Height>' . PHP_EOL;
            $xmlRequest .= '<Girth></Girth>' . PHP_EOL;
            $xmlRequest .= '<Value>' . $this->shipment_details['shipment']['total_value'] . '</Value>' . PHP_EOL;

            if ( strpos( $servicetype, 'First Class' ) !== false && $first_class_type == 'LETTER' ) {
                $xmlRequest .= '<Machinable>TRUE</Machinable>' . PHP_EOL;
            } else {
                $xmlRequest .= '<Machinable>FALSE</Machinable>' . PHP_EOL;
            }

            $xmlRequest .= '<ShipDate>' . $shipdate_text . '</ShipDate>' . PHP_EOL;
            $xmlRequest .= '</Package>' . PHP_EOL;
            //PACKAGE END

            $xmlRequest .= '</RateV4Request>';

            return $xmlRequest;
        }

        /**
         * @param $xmlRequest
         * @param $APIV
         *
         * @return mixed
         */
        private function callUSPSAPI( $xmlRequest, $APIV ) {

            $debug = ('Yes' === $this->base_constant('SCREEN_API'));

            if (!empty($debug)) {
                $xml = simplexml_load_string($xmlRequest, "SimpleXMLElement", LIBXML_NOCDATA);
                $json = json_encode($xml);
                $array = json_decode($json, TRUE);
                var_dump($array);
            }

            $url = $this->api_connection['protocol'] . "://" . $this->api_connection['host'] . $this->api_connection['path'];

            $curl = curl_init( $url );
            curl_setopt( $curl, CURLOPT_SSL_VERIFYPEER, true );
            curl_setopt( $curl, CURLOPT_SSL_VERIFYHOST, 2 );
            curl_setopt( $curl, CURLOPT_POST, true );
            curl_setopt( $curl, CURLOPT_POSTFIELDS, 'API=' . $APIV . '&XML=' . $xmlRequest );
            curl_setopt( $curl, CURLOPT_TIMEOUT, 60 );
            curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true );
            curl_setopt( $curl, CURLOPT_HTTPHEADER, [
                'Content-Type: application/x-www-form-urlencoded',
            ] );

            $curl_response = curl_exec( $curl ); // Execute REST Request

            if (!empty($debug)) {
                $xml = simplexml_load_string($curl_response, "SimpleXMLElement", LIBXML_NOCDATA);
                $json = json_encode($xml);
                $array = json_decode($json, TRUE);
                var_dump($array);
            }

            if ( curl_errno( $curl ) ) {
                $error_from_curl = sprintf( 'Error [%d]: %s', curl_errno( $curl ), curl_error( $curl ) );
                if ( MODULE_SHIPPING_ZIP_USPS_EMAIL_ERRORS == 'Yes' ) {
                    error_log( "Error from cURL: " . $error_from_curl . " experienced by customer with id " . $_SESSION['customer_id'] . " on " . date( 'Y-m-d H:i:s' ), 1, STORE_OWNER_EMAIL_ADDRESS );
                }
                if ( MODULE_SHIPPING_ZIP_USPS_SCREEN_ERRORS == 'Yes' ) {
                    $this->debugToScreen( $xmlRequest, $error_from_curl );
                }
            }

            curl_close( $curl );

            libxml_use_internal_errors( true );

            //            var_dump( json_decode( json_encode( simplexml_load_string( $curl_response ) ), true ) );
            return json_decode( json_encode( simplexml_load_string( $curl_response ) ), true );

        }

        /**
         * @param $desc
         *
         * @return bool
         */
        private function ignoreErrors( $desc ) {

            if ( strpos( $desc, 'must weigh' ) === false && strpos( $desc, 'Invalid value specified for Service' ) === false && strpos( $desc, 'The entered weight' ) === false ) {
                return true;
            } else {
                return false;
            }
        }

        /**
         * @param $result
         */
        private function processInternationalUSPSRate( $result ) {

            global $shipping_num_boxes, $language;

            $service_types = MODULE_SHIPPING_ZIP_USPS_SERVICES;
            $service_types = str_replace( '--none--', '', $service_types );
            $service_types = explode( ';', $service_types );

            if ( ! empty( $result['Number'] ) ) {

                //ignore under/over weight errors
                if ( $this->ignoreErrors( $result['Description'] ) ) {
                    if ( MODULE_SHIPPING_ZIP_USPS_EMAIL_ERRORS == 'Yes' ) {
                        error_log( "Error from cURL: " . $result['Number'] . ' ' . $result['Description'] . " experienced by customer with id " . $_SESSION['customer_id'] . " on " . date( 'Y-m-d H:i:s' ), 1, STORE_OWNER_EMAIL_ADDRESS );
                    }
                    if ( MODULE_SHIPPING_ZIP_USPS_SCREEN_ERRORS == 'Yes' ) {
                        $this->debugToScreen( $result, '' );
                    }
                }

            } else if ( ! empty( $result ) && ! empty( $result['Package'] ) && ! empty( $result['Package']['Service'] ) ) {

                if ( empty( $this->quotes ) ) {
                    $this->quotes = [
                        'id'      => $this->code,
                        'module'  => MODULE_SHIPPING_ZIP_USPS_LANG_TEXT_TITLE,
                        'methods' => [],
                    ];
                }

                $dow       = date( 'N' );
                $estimates = $result['Package']['Service'];

                foreach ( $estimates as $estimate ) {

                    if ( ! empty( $estimate['MailType'] ) && in_array( 'I' . $estimate['@attributes']['ID'], $service_types ) ) {

                        if ( (int) $estimate['MaxWeight'] >= ( $this->shipment_details['shipment'] ['weight'] / $shipping_num_boxes ) ) {

                            $postage_rate = $estimate['Postage'];
                            if ( ! empty( $this->shipment_details['shipment']['commercialrates'] ) && ! empty( $estimate['CommercialPostage'] ) ) {
                                $postage_rate = $estimate['CommercialPostage'];
                            }

                            $service = [
                                'id'              => $estimate['@attributes']['ID'],
                                'name'            => html_entity_decode( $estimate['SvcDescription'] ),
                                'price'           => $postage_rate,
                                'commitment_date' => empty( $estimate['SvcCommitments'] ) ? '' : $estimate['SvcCommitments'],
                                //                        'commitment_name' => empty( $estimate['CommitmentName'] ) ? '' : $estimate['CommitmentName'],
                            ];

                            if ( MODULE_SHIPPING_ZIP_USPS_HANDLING_TYPE == 'Flat Fee' ) {
                                $service['price'] += MODULE_SHIPPING_ZIP_USPS_HANDLING;
                            } else if ( MODULE_SHIPPING_ZIP_USPS_HANDLING_TYPE == 'Percentage' ) {
                                $service['price'] = ( ( MODULE_SHIPPING_ZIP_USPS_HANDLING * $service['price'] ) / 100 ) + $service['price'];
                            }

                            if ( ! empty( $service['commitment_date'] ) ) {
                                $service['name'] .= '<br/>(' . $service['commitment_date'] . ')';
                            }

                            $addons                = explode( ';', MODULE_SHIPPING_ZIP_USPS_ADDONS );
                            $insurance_only_addons = [ 107 ];//<---- DO NOT EDIT THIS
                            if ( ! empty( $estimate['SpecialServices']['SpecialService'] ) ) {
                                foreach ( $estimate['SpecialServices']['SpecialService'] as $extra ) {
                                    if ( in_array( $extra['ServiceID'], $addons ) ) {
                                        if ( $extra['Available'] == 'true' ) {
                                            if ( in_array( $extra['ServiceID'], $insurance_only_addons ) ) {
                                                //only add insurance if turned on
                                                if ( ! empty( $this->shipment_details['shipment']['insure_shipment'] ) ) {

                                                    //only add insurance if turned on
                                                    $charge_insurance = ( empty( $this->shipment_details['shipment']['insure_limits'] ) ) ? 1 : $this->checkInsuranceLimit( $estimate['@attributes']['CLASSID'], $this->shipment_details['shipment']['total_value'] );

                                                    if ( ! empty( $this->shipment_details['shipment']['insure_shipment'] ) && ! empty( $charge_insurance ) ) {
                                                        $service['price'] += $extra['Price'];
                                                    }
                                                }
                                            } else {
                                                $service['price'] += $extra['Price'];
                                            }

                                        }
                                    }
                                }
                            }

                            if ( ! empty( $service['price'] ) ) {

                                $weight_to_show = ( $this->shipment_details['shipment'] ['weight'] >= 1 ) ? $this->shipment_details['shipment'] ['weight'] . 'lbs' : round( 16 * $this->shipment_details['shipment'] ['weight'] ) . 'oz';

                                $num_package_text = ( $shipping_num_boxes > 1 ) ? MODULE_SHIPPING_ZIP_USPS_LANG_PACKAGES : MODULE_SHIPPING_ZIP_USPS_LANG_PACKAGE;

                                $weight_to_show .= ( $shipping_num_boxes > 1 ) ? ' ' . MODULE_SHIPPING_ZIP_USPS_LANG_TOTAL : '';

                                $this->quotes['methods'][] = [
                                    'id'    => $service['id'],
                                    'title' => $service['name'] . '<br/>' . $shipping_num_boxes . ' ' . $num_package_text . ' @ ' . $weight_to_show,
                                    'cost'  => $service['price'] * $shipping_num_boxes,
                                    //multiply boxes as weight & value already divided by boxes
                                ];
                            }
                        }
                    }
                }
            }
        }

        /**
         * @param $result
         */
        private function processDomesticUSPSRate( $result ) {

            global $shipping_num_boxes, $language;

            $service_types = MODULE_SHIPPING_ZIP_USPS_SERVICES;
            $service_types = str_replace( '--none--', '', $service_types );
            $service_types = explode( ';', $service_types );

            if ( ! empty( $result['Package']['Error'] ) ) {

                //ignore under/over weight errors
                if ( $this->ignoreErrors( $result['Package']['Error']['Description'] ) ) {
                    if ( MODULE_SHIPPING_ZIP_USPS_EMAIL_ERRORS == 'Yes' ) {
                        error_log( "Error from cURL: " . $result['Package']['Error']['Number'] . ' ' . $result['Package']['Error']['Description'] . " experienced by customer with id " . $_SESSION['customer_id'] . " on " . date( 'Y-m-d H:i:s' ), 1, STORE_OWNER_EMAIL_ADDRESS );
                    }
                    if ( MODULE_SHIPPING_ZIP_USPS_SCREEN_ERRORS == 'Yes' ) {
                        $this->debugToScreen( $result, '' );
                    }
                }

            } else if ( ! empty( $result ) && ! empty( $result['Package'] ) ) {

                if ( empty( $this->quotes ) ) {
                    $this->quotes = [
                        'id'      => $this->code,
                        'module'  => MODULE_SHIPPING_ZIP_USPS_LANG_TEXT_TITLE,
                        'methods' => [],
                    ];
                }

                $dow      = date( 'N' );
                $estimate = $result['Package']['Postage'];
                if ( stripos( $estimate['MailService'], 'Stamped' ) !== false ) {
                    //                    $estimate['@attributes']['CLASSID'] = -1;
                }
                if ( stripos( $estimate['MailService'], 'Large' ) !== false ) {
                    //                    $estimate['@attributes']['CLASSID'] = -1;
                }

                if ( ! empty( $estimate['MailService'] ) && in_array( $estimate['@attributes']['CLASSID'], $service_types ) ) {

                    $service = [
                        'id'              => $estimate['@attributes']['CLASSID'],
                        'name'            => html_entity_decode( $estimate['MailService'] ),
                        'price'           => $estimate['Rate'],
                        'commitment_date' => empty( $estimate['CommitmentDate'] ) ? '' : $estimate['CommitmentDate'],
                        'commitment_name' => empty( $estimate['CommitmentName'] ) ? '' : $estimate['CommitmentName'],
                    ];

                    if ( MODULE_SHIPPING_ZIP_USPS_HANDLING_TYPE == 'Flat Fee' ) {
                        $service['price'] += MODULE_SHIPPING_ZIP_USPS_HANDLING;
                    } else if ( MODULE_SHIPPING_ZIP_USPS_HANDLING_TYPE == 'Percentage' ) {
                        $service['price'] = ( ( MODULE_SHIPPING_ZIP_USPS_HANDLING * $service['price'] ) / 100 ) + $service['price'];
                    }

                    if ( ! empty( $service['commitment_date'] ) ) {
                        $service['name'] .= '<br/>(' . $service['commitment_date'] . ')';
                    }
                    /* if ( ! empty( $service['commitment_name'] ) ) {
                         $service['name'] .= '<br/>(' . $service['commitment_name'] . ')';
                     }*/

                    $addons                = explode( ';', MODULE_SHIPPING_ZIP_USPS_ADDONS );
                    $insurance_only_addons = [ 100, 101, 125, 177, 178, 179, 180 ];//<---- DO NOT EDIT THIS
                    if ( ! empty( $estimate['SpecialServices']['SpecialService'] ) ) {
                        foreach ( $estimate['SpecialServices']['SpecialService'] as $extra ) {
                            if ( in_array( $extra['ServiceID'], $addons ) ) {
                                if ( $extra['Available'] == 'true' ) {
                                    if ( in_array( $extra['ServiceID'], $insurance_only_addons ) ) {

                                        //only add insurance if turned on
                                        $charge_insurance = ( empty( $this->shipment_details['shipment']['insure_limits'] ) ) ? 1 : $this->checkInsuranceLimit( $estimate['@attributes']['CLASSID'], $this->shipment_details['shipment']['total_value'] );

                                        if ( ! empty( $this->shipment_details['shipment']['insure_shipment'] ) && ! empty( $charge_insurance ) ) {
                                            $service['price'] += $extra['Price'];
                                        }

                                    } else {
                                        $service['price'] += $extra['Price'];

                                    }

                                }
                            }
                        }
                    }

                    if ( ! empty( $service['price'] ) ) {

                        $weight_to_show = ( $this->shipment_details['shipment'] ['weight'] >= 1 ) ? $this->shipment_details['shipment'] ['weight'] . 'lbs' : round( 16 * $this->shipment_details['shipment'] ['weight'] ) . 'oz';

                        $num_package_text = ( $shipping_num_boxes > 1 ) ? MODULE_SHIPPING_ZIP_USPS_LANG_PACKAGES : MODULE_SHIPPING_ZIP_USPS_LANG_PACKAGE;

                        $weight_to_show .= ( $shipping_num_boxes > 1 ) ? ' ' . MODULE_SHIPPING_ZIP_USPS_LANG_TOTAL : '';

                        $this->quotes['methods'][] = [
                            'id'    => $service['id'],
                            'title' => $service['name'] . '<br/>' . $shipping_num_boxes . ' ' . $num_package_text . ' @ ' . $weight_to_show,
                            'cost'  => $service['price'] * $shipping_num_boxes,
                            //multiply boxes as weight & value already divided by boxes
                        ];
                    }
                }
            } elseif ( ! empty( $result['Number'] ) ) {

                // errors at this level are fatal!
                $error_msg = "Error from API: " . $result['Number'] . ' ' . $result['Description'] . ' ' . $result['Source'];

                if ( MODULE_SHIPPING_ZIP_USPS_EMAIL_ERRORS == 'Yes' ) {
                    error_log( $error_msg . " experienced by customer with id " . $_SESSION['customer_id'] . " on " . date( 'Y-m-d H:i:s' ), 1, STORE_OWNER_EMAIL_ADDRESS );
                }
                if ( MODULE_SHIPPING_ZIP_USPS_SCREEN_ERRORS == 'Yes' ) {
                    $this->debugToScreen( $error_msg, '' );
                }

            } 
        }

        /**
         * @param $serviceid
         * @param $amount
         *
         * @return bool
         */
        function checkInsuranceLimit( $serviceid, $amount ) {

            if ( empty( $this->shipment_details['shipment']['insure_limits'] ) ) {
                return true;
            } else {
                $limits = explode( ',', $this->shipment_details['shipment']['insure_limits'] );
                if ( is_array( $limits ) ) {
                    foreach ( $limits as $limit ) {
                        if ( $this->checkInsuranceLimitEval( $limit, $serviceid, $amount ) ) {
                            return true;
                        }
                    }
                } else {
                    if ( $this->checkInsuranceLimitEval( $limits, $serviceid, $amount ) ) {
                        return true;
                    }
                }
            }

            return false;

        }

        /**
         * @param $limit
         * @param $serviceid
         * @param $amount
         *
         * @return bool
         */
        function checkInsuranceLimitEval( $limit, $serviceid, $amount ) {

            $match = '';
            if ( strpos( $limit, '<=' ) !== false ) {
                $match = '<=';
            } else if ( strpos( $limit, '>=' ) !== false ) {
                $match = '>=';
            } else if ( strpos( $limit, '<' ) !== false ) {
                $match = '<';
            } else if ( strpos( $limit, '>' ) !== false ) {
                $match = '>';
            } else if ( strpos( $limit, '=' ) !== false ) {
                $match = '=';
            } else if ( strpos( $limit, '==' ) !== false ) {
                $match = '=';
            } else {
                return false;
            }

            $sep         = strpos( $limit, $match );
            $service     = substr( $limit, 0, $sep );
            $limitamount = substr( $limit, $sep + 1 );

            if ( $service == $serviceid ) {
                $foundmatch = 0;
                if ( $match == '<' ) {
                    if ( $amount < $limitamount ) {
                        $foundmatch = 1;
                    }
                } else if ( $match == '>' ) {
                    if ( $amount > $limitamount ) {
                        $foundmatch = 1;
                    }
                } else if ( $match == '<=' ) {
                    if ( $amount <= $limitamount ) {
                        $foundmatch = 1;
                    }
                } else if ( $match == '>=' ) {
                    if ( $amount >= $limitamount ) {
                        $foundmatch = 1;
                    }
                } else if ( $match == '=' ) {
                    if ( $amount == $limitamount ) {
                        $foundmatch = 1;
                    }
                }

                if ( ! empty( $foundmatch ) ) {
                    return true;
                } else {
                    return false;
                }

            } else {
                return false;
            }

        }

        /**
         * Next function used for sorting the shipping quotes on rate: low to high is default.
         *
         * @param     $arr
         * @param     $col
         * @param int $dir
         *
         * @return int
         */
        function sortRates( &$arr, $col, $dir = SORT_ASC ) {

            $sort_col = [];
            if ( ! empty( $arr ) ) {
                foreach ( $arr as $key => $row ) {
                    $sort_col[ $key ] = $row[ $col ];
                }

                array_multisort( $sort_col, $dir, $arr );
            }
        }

        /**
         * Calculate shipment weight based on order lines
         */
        private function calcWeight() {

            global $order, $shipping_num_boxes, $language, $shipping_weight;

            //changed 02-01-2023 - kept old code just in case someone wants to use old way
            $calc_method = 'new';

            if ( $calc_method == 'old' ) {

                $this->shipment_details['shipment'] ['weight'] = 0;
                foreach ( $order->products as $product ) {
                    if ( empty( $product['weight'] ) ) {
                        $product['weight'] = 0;
                    }
                    $this->shipment_details['shipment'] ['weight'] += $product['qty'] * $product['weight'];
                }

                if ( empty( $this->shipment_details['shipment'] ['weight'] ) ) {
                    $this->shipment_details['shipment'] ['weight'] = 1;
                }

                $this->shipment_details['shipment'] ['weight'] = round( $this->shipment_details['shipment'] ['weight'], 3 );

            } else {
                $weight                                       = $shipping_weight * $shipping_num_boxes;
                $this->shipment_details['shipment']['weight'] = round( $weight, 3 );
            }

        }

        /**
         * Set destination of shipment
         */
        private function setDestination() {

            global $order;

            $state_prov = '';
            $query      = $this->db_query( "select zone_code from zones where zone_name = '" . $this->db_input( $order->delivery['state'] ) . "' and zone_country_id = '" . $order->delivery['country']['id'] . "'" );
            $zone       = $this->fetch_array( $query );
            if ( ! empty( $zone ) ) {
                $state_prov = $zone['zone_code'];
            }

            $name = ( isset( $order->delivery['entry_lastname'] ) ) ? $order->delivery['entry_lastname'] . ', ' . $order->delivery['entry_firstname'] : $order->delivery['name'];

            $this->shipment_details['destination'] = [
                'name'         => $name,
                'address'      => $order->delivery['street_address'],
                'city'         => $order->delivery['city'],
                'state_prov'   => $state_prov,
                'country'      => $order->delivery['country']['iso_code_2'],
                'country_name' => $order->delivery['country']['name'],
            ];

            if ( $order->delivery['country']['iso_code_2'] == 'US' ) {
                $this->shipment_details['destination']['postal_code'] = substr( str_replace( ' ', '', $order->delivery['postcode'] ), 0, 5 );
            } else if ( $order->delivery['country']['iso_code_2'] == 'BR' ) {
                $this->shipment_details['destination']['postal_code'] = substr( str_replace( ' ', '', $order->delivery['postcode'] ), 0, 5 );
            } else if ( $order->delivery['country']['iso_code_2'] == 'CA' ) {
                $this->shipment_details['destination']['postal_code'] = strtoupper( str_replace( ' ', '', $order->delivery['postcode'] ) );
            } else {
                $this->shipment_details['destination']['postal_code'] = $order->delivery['postcode'];
            }

        }

        /**
         * Set origin of shipment
         */
        private function setOrigin() {

            $store_address = explode( "\n\r", STORE_ADDRESS );

            $this->shipment_details['origin'] = [
                'name'       => STORE_OWNER,
                'address'    => $store_address,
                'city'       => MODULE_SHIPPING_ZIP_USPS_CITY,
                'state_prov' => MODULE_SHIPPING_ZIP_USPS_STATEPROV,
                'country'    => MODULE_SHIPPING_ZIP_USPS_COUNTRY,
            ];
            if ( MODULE_SHIPPING_ZIP_USPS_COUNTRY == 'US' ) {
                $this->shipment_details['origin']['postal_code'] = substr( str_replace( ' ', '', MODULE_SHIPPING_ZIP_USPS_POSTALCODE ), 0, 5 );
            } else {
                $this->shipment_details['origin']['postal_code'] = strtoupper( str_replace( ' ', '', MODULE_SHIPPING_ZIP_USPS_POSTALCODE ) );
            }
        }

        /**
         * @param $key
         *
         * @return string
         */
        private function zip_get_usps_service_code( $key ) {

            $realservice = '';

            //Duplicates are important to leave as below to avoid multiple queries to API for the same info
            $services = [
//                '0'   => 'First Class',//REMOVED 2023
                '1'   => 'Priority',
                '3'   => 'Priority Mail Express',
//                '4'   => 'Parcel Select Ground',//REMOVED 2023
                '6'   => 'Media',
                '7'   => 'Library',
                '13'  => 'Priority Mail Express',
//                '15'  => 'First Class',//REMOVED 2023
                '16'  => 'Priority',
                '17'  => 'Priority',
                '22'  => 'Priority',
                '23'  => 'Priority Mail Express',
                '25'  => 'Priority Mail Express',
                '28'  => 'Priority',
                '29'  => 'Priority',
                '30'  => 'Priority',
                '32'  => 'Priority',
                '42'  => 'Priority',
                '44'  => 'Priority',
                '47'  => 'Priority',
                '49'  => 'Priority',
                '55'  => 'Priority Mail Express',
                '57'  => 'Priority Mail Express',
                '58'  => 'Priority',
//                '61'  => 'First Class Commercial',//REMOVED 2023
                '62'  => 'Priority Mail Express',
                '64'  => 'Priority Mail Express',
                '1058'  => 'Ground Advantage',
                'I1'  => 'Priority Mail Express International',
                'I2'  => 'Priority Mail International',
                'I4'  => 'Global Express Guaranteed (GXG)',
                'I5'  => 'Global Express Guaranteed; Document',
                'I8'  => 'Priority Mail International; Flat Rate Envelope',
                'I9'  => 'Priority Mail International; Medium Flat Rate Box',
                'I10' => 'Priority Mail Express International; Flat Rate Envelope',
                'I11' => 'Priority Mail International; Large Flat Rate Box',
                'I13' => 'First-Class Mail; International Letter',
                'I14' => 'First-Class Mail; International Large Envelope',
                'I15' => 'First-Class Package International Service',
                'I16' => 'Priority Mail International; Small Flat Rate Box',
                'I23' => 'Priority Mail International; Padded Flat Rate Envelope',
                'I27' => 'Priority Mail Express International; Padded Flat Rate Envelope',
            ];

            if ( ! empty( $services[ $key ] ) ) {
                $realservice = $services[ $key ];
            }

            return $realservice;
        }

        /**
         * @param     $arraytoshow
         * @param int $showfullscreen
         */
        public function ShowMe( $arraytoshow, $showfullscreen = 0 ) {

            if ( $showfullscreen == 1 ) {
                echo '<div style="position: fixed; top: 0px; left: 0px; width: 2000px; height: 1000px; overflow: auto;">';
            }
            echo '<pre>';
            var_dump( $arraytoshow );
            echo '</pre>';
            if ( $showfullscreen == 1 ) {
                echo '</div>';
            }
        }

        /**
         * @param $xmlRequest
         * @param $error_from_soap
         */
        public function debugToScreen( $xmlRequest, $error_from_soap ) {

            $this->ShowMe( $xmlRequest );

            if ( ! empty( $error_from_soap ) ) {
                $this->ShowMe( $error_from_soap );
            }
        }

        protected function update_old_functions() {

            if ( version_compare( '1.0.8.19', $this->phoenix_version() ) >= 0 ) {

                $this->db_query( "UPDATE configuration SET set_function = REPLACE(set_function, 'tep_cfg_select_option(', 'Config::select_one(') WHERE set_function LIKE 'tep_cfg_select_option(%' AND configuration_key LIKE '" . $this->config_key_base . "%';" );
                $this->db_query( "UPDATE configuration SET set_function = 'Config::select_geo_zone(' WHERE set_function = 'tep_cfg_pull_down_zone_classes(' AND configuration_key LIKE '" . $this->config_key_base . "%';" );
                $this->db_query( "UPDATE configuration SET use_function = 'Tax::get_class_title' WHERE use_function = 'tep_get_tax_class_title' AND configuration_key LIKE '" . $this->config_key_base . "%';" );
                $this->db_query( "UPDATE configuration SET set_function = 'Config::select_tax_class(' WHERE set_function = 'tep_cfg_pull_down_tax_classes(' AND configuration_key LIKE '" . $this->config_key_base . "%';" );
                $this->db_query( "UPDATE configuration SET use_function = 'geo_zone::fetch_name' WHERE use_function = 'tep_get_zone_class_title' AND configuration_key LIKE '" . $this->config_key_base . "%';" );
                $this->db_query( "UPDATE configuration SET use_function = 'geo_zone::fetch_name' WHERE use_function = 'tep_get_geo_zone_name' AND configuration_key LIKE '" . $this->config_key_base . "%';" );

            }

        }

        /**
         * @return string[][]
         */
        protected function get_parameters() {

            $this->update_old_functions();

            if ( version_compare( '1.0.8.3', $this->phoenix_version() ) >= 0 ) {
                $select_set_func                  = 'tep_cfg_select_option';
                $select_geo_set_func              = 'tep_cfg_pull_down_zone_classes';
                $select_zone_class_title          = 'tep_get_zone_class_title';
                $select_tax_class_title           = 'tep_get_tax_class_title';
                $select_cfg_pull_down_tax_classes = 'tep_cfg_pull_down_tax_classes';
            } else {
                $select_set_func                  = 'Config::select_one';
                $select_geo_set_func              = 'Config::select_geo_zone';
                $select_zone_class_title          = 'geo_zone::fetch_name';
                $select_tax_class_title           = 'Tax::get_class_title';
                $select_cfg_pull_down_tax_classes = 'Config::select_tax_class';
            }

            return [
                $this->config_key_base . 'STATUS'          => [
                    'title'    => 'Enable USPS Shipping',
                    'value'    => 'True',
                    'desc'     => 'Do you want to offer USPS shipping?',
                    'set_func' => "$select_set_func(['True', 'False'], ",
                ],
                $this->config_key_base . 'USER_ID'         => [
                    'title' => 'USPS API Username',
                    'value' => '',
                    'desc'  => 'Enter Your USPS API Username.',
                ],
                $this->config_key_base . 'PASSWORD'         => [
                    'title' => 'USPS API Password',
                    'value' => '',
                    'desc'  => 'Enter Your USPS API Password.',
                ],
                $this->config_key_base . 'CITY'            => [
                    'title' => 'Origin City',
                    'value' => '',
                    'desc'  => 'Enter the name of the origin city.',
                ],
                $this->config_key_base . 'STATEPROV'       => [
                    'title' => 'Origin State/Province',
                    'value' => '',
                    'desc'  => 'Enter the name of the origin state/province.',
                ],
                $this->config_key_base . 'COUNTRY'         => [
                    'title' => 'Origin Country',
                    'value' => 'US',
                    'desc'  => 'Choose your origin country.',
                    'set_func' => "zip_usps_cfg_select_country(",
                ],
                $this->config_key_base . 'POSTALCODE'      => [
                    'title' => 'Origin Zip/Postal Code',
                    'value' => '',
                    'desc'  => 'Enter your origin zip/postalcode (from which the parcel will be sent).',
                ],
                $this->config_key_base . 'SERVICES'        => [
                    'title'    => 'Services to Offer (Depending on weight etc):',
                    'value'    => '',
                    'desc'     => 'Select the services to offer. Only selected services available for client location and package size will be returned to clients.',
                    'use_func' => 'zip_get_multioption_usps_xml',
                    'set_func' => "zip_usps_cfg_select_multioption(['1','3','6','7','13','16','17','22','23','25','28','29','30','32','42','44','47','49','55','57','58','62','64','1058','I1','I2','I4','I5','I8','I9','I10','I11','I13','I14','I15','I16','I23','I27'], ",
                ],
                $this->config_key_base . 'INSURE'          => [
                    'title'    => 'Enable Insurance',
                    'value'    => 'Yes',
                    'desc'     => 'Do you want to insure packages shipped by USPS? *Make sure to specify insurance add-ons below or insurance may not be added.*',
                    'set_func' => "$select_set_func(['Yes', 'No'], ",
                ],
                $this->config_key_base . 'INSURELIMITS'    => [
                    'title' => 'Insurance Limits',
                    'value' => '',
                    'desc'  => 'Leave this blank if no limits. If you want to limit a specific Service, for example to only charge insurance on  [I1] Priority Mail Express International to charge interest on shipments over $200, enter the following: <span class="text-primary">I1>200</span> . If you want to limit more than one service, use a comma to separate, example <span class="text-primary">I1>200,3>10</span>. You can get the service codes in the services list above. Make sure you use a capital "I" on International Services as shown above. Order amounts are rounded up to the nearest dollar for logic. Operators available are &lt; &lt;= &gt; &gt;= =',
                ],
                $this->config_key_base . 'INTERNATIONAL'   => [
                    'title'    => 'Enable International Shipments',
                    'value'    => 'Commercial',
                    'desc'     => 'Do you want to enable International Shipments with USPS? If yes, choose the pricing your qualify for.',
                    'set_func' => "$select_set_func(['No', 'Commercial', 'Commercial Plus', 'Basic'], ",
                ],
                $this->config_key_base . 'COMMERCIALRATES' => [
                    'title'    => 'Enable Commercial Rates',
                    'value'    => 'Yes',
                    'desc'     => 'Do you want to enable Commercial Rates with USPS? If yes, the commercial rate will be used if it is returned from your account.',
                    'set_func' => "$select_set_func(['No', 'Yes',], ",
                ],
                $this->config_key_base . 'ADDONS'          => [
                    'title'    => 'Services add-ons to Offer (if available for shipping method):',
                    'value'    => '100;101;125;178;180;',
                    'desc'     => 'Select the services add-ons to offer.',
                    'use_func' => 'zip_get_multioption_usps_addon_xml',
                    'set_func' => "zip_usps_addon_cfg_select_multioption(['100','101','103','104','105','106','107','108','109','112','119','120','125','155','156','160','161','170','171','172','173','174','175','176','177','178','179','180','181','182','190'], ",
                ],
                $this->config_key_base . 'CONTAINER'       => [
                    'title'    => 'Container for Shipping',
                    'value'    => '--none--',
                    'desc'     => 'Use to specify container attributes that may affect postage; otherwise, leave --none--.',
                    'set_func' => "$select_set_func(['--none--', 'VARIABLE', 'FLAT RATE ENVELOPE', 'PADDED FLAT RATE ENVELOPE', 'LEGAL FLAT RATE ENVELOPE', 'SM FLAT RATE ENVELOPE', 'SM FLAT RATE BOX', 'MD FLAT RATE BOX', 'LG FLAT RATE BO', 'REGIONALRATEBOXA', 'REGIONALRATEBOXB'], ",
                ],
                $this->config_key_base . 'HANDLING_TYPE'   => [
                    'title'    => 'Handling Type',
                    'value'    => 'Flat Fee',
                    'desc'     => 'Handling type for this shipping method.',
                    'set_func' => "$select_set_func(['Flat Fee', 'Percentage'], ",
                ],
                $this->config_key_base . 'HANDLING'        => [
                    'title' => 'Handling Fee',
                    'value' => '0',
                    'desc'  => 'Handling fee for this shipping method.',
                ],
                $this->config_key_base . 'TAX_CLASS'       => [
                    'title'    => 'Tax Class',
                    'value'    => '0',
                    'desc'     => 'Use the following tax class on the shipping fee.',
                    'use_func' => "$select_tax_class_title",
                    'set_func' => "$select_cfg_pull_down_tax_classes(",
                ],
                $this->config_key_base . 'ZONE'            => [
                    'title'    => 'Shipping Zone',
                    'value'    => '0',
                    'desc'     => 'If a zone is selected, only enable this shipping method for that zone.',
                    'use_func' => "$select_zone_class_title",
                    'set_func' => "$select_geo_set_func(",
                ],
                $this->config_key_base . 'EMAIL_ERRORS'    => [
                    'title'    => 'Email USPS errors',
                    'value'    => 'Yes',
                    'desc'     => 'Do you want to receive USPS errors by email?',
                    'set_func' => "$select_set_func(['Yes', 'No'], ",
                ],
                $this->config_key_base . 'SCREEN_ERRORS'   => [
                    'title'    => 'Show USPS errors (<span class="text-danger">make sure to turn this off once done testing</span>)',
                    'value'    => 'Yes',
                    'desc'     => 'Do you want to show USPS errors on screen?',
                    'set_func' => "$select_set_func(['Yes', 'No'], ",
                ],
                $this->config_key_base . 'SCREEN_API'   => [
                    'title'    => 'Output API detail (<span class="text-danger">only turn on for debugging</span>)',
                    'value'    => 'No',
                    'desc'     => 'Write details of API calls to screen? Useful if you\'re not getting anything output.',
                    'set_func' => "$select_set_func(['Yes', 'No'], ",
                ],
                $this->config_key_base . 'TURNAROUNDTIME'  => [
                    'title' => 'Enter Turn Around Time',
                    'value' => '1',
                    'desc'  => 'Turn Around Time (days).',
                ],
                $this->config_key_base . 'SORT_ORDER'      => [
                    'title' => 'Sort Order of display',
                    'value' => '20',
                    'desc'  => 'Sort order of display. Lowest is displayed first.',
                ],
            ];
        }

    }

    //Additional functions

    /**
     * @param $values
     *
     * @return string
     */
    function zip_get_multioption_usps_xml( $values ) {

        if ( ! empty( $values ) ) {
            $values_array = explode( ';', $values );

            foreach ( $values_array as $key => $_method ) {

                $method                  = zip_get_usps_service_code( $_method );
                $readable_values_array[] = '<br/>' . $method;

            }

            return implode( '', $readable_values_array );
        } else {
            return '--none--';
        }
    }

    /**
     * @param $key
     *
     * @return string
     */
    function zip_get_usps_service_code( $key ) {

        $realservice = '';

        $services = [
//            '0'   => 'First-Class Mail',//REMOVED 2023
            '1'   => 'Priority Mail',
            '3'   => 'Priority Mail Express',
//            '4'   => 'Standard Post',//REMOVED 2023
            '6'   => 'Media Mail',
            '7'   => 'Library Mail',
            '13'  => 'Priority Mail Express; Flat Rate Envelope',
//            '15'  => 'First-Class Mail; Large Postcards',//REMOVED 2023
            '16'  => 'Priority Mail; Flat Rate Envelope',
            '17'  => 'Priority Mail; Medium Flat Rate Box',
            '22'  => 'Priority Mail; Large Flat Rate Box',
            '23'  => 'Priority Mail Express; Sunday/Holiday Delivery',
            '25'  => 'Priority Mail Express; Sunday/Holiday Delivery Flat Rate Envelope',
            '28'  => 'Priority Mail; Small Flat Rate Box',
            '29'  => 'Priority Mail; Padded Flat Rate Envelope',
            '30'  => 'Priority Mail Express; Legal Flat Rate Envelope',
            '32'  => 'Priority Mail Express; Sunday/Holiday Delivery Legal Flat Rate Envelope',
            '42'  => 'Priority Mail; Small Flat Rate Envelope',
            '44'  => 'Priority Mail; Legal Flat Rate Envelope',
            '47'  => 'Priority Mail; Regional Rate Box A',
            '49'  => 'Priority Mail; Regional Rate Box B',
            '55'  => 'Priority Mail Express; Flat Rate Boxes',
            '57'  => 'Priority Mail Express; Sunday/Holiday Delivery Flat Rate Boxes',
            '58'  => 'Priority Mail; Regional Rate Box C',
//            '61'  => 'First-Class; Package Service',//REMOVED 2023
            '62'  => 'Priority Mail Express; Padded Flat Rate Envelope',
            '64'  => 'Priority Mail Express; Sunday/Holiday Delivery Padded Flat Rate Envelope',
            '1058'  => 'Ground Advantage',
            'I1'  => 'Priority Mail Express International',
            'I2'  => 'Priority Mail International',
            'I4'  => 'Global Express Guaranteed (GXG)',
            'I5'  => 'Global Express Guaranteed; Document',
            'I8'  => 'Priority Mail International; Flat Rate Envelope',
            'I9'  => 'Priority Mail International; Medium Flat Rate Box',
            'I10' => 'Priority Mail Express International; Flat Rate Envelope',
            'I11' => 'Priority Mail International; Large Flat Rate Box',
            'I13' => 'First-Class Mail; International Letter',
            'I14' => 'First-Class Mail; International Large Envelope',
            'I15' => 'First-Class Package International Service',
            'I16' => 'Priority Mail International; Small Flat Rate Box',
            'I23' => 'Priority Mail International; Padded Flat Rate Envelope',
            'I27' => 'Priority Mail Express International; Padded Flat Rate Envelope',
        ];

        if ( ! empty( $services[ $key ] ) ) {
            $realservice = $services[ $key ];
        }

        return $realservice;
    }

    function zip_usps_cfg_select_country($key_value, $key = '' ){

        if (class_exists('zipusps')) {

            $zipuspsx = new zipusps();


            $string = '<select name="configuration[' . $key . ']]">';

            $query      = $zipuspsx->db_query( "select countries_iso_code_2, countries_name from countries ORDER BY countries_iso_code_2" );
            while ($row = $zipuspsx->fetch_array( $query )){
                $string .= '<option value="' . $row['countries_iso_code_2'] . '"' . ($key_value == $row['countries_iso_code_2'] ? ' SELECTED' : '') . '>' . $row['countries_iso_code_2'] . ' - ' . $row['countries_name'] . '</option>';
            }


            $string .= '</select>';
        } else {
            $string = 'Error';
        }

//        for ( $i = 0; $i < sizeof( $select_array ); $i ++ ) {
//            $name       = ( ( $key ) ? 'configuration[' . $key . '][]' : 'configuration_value' );
//            $string     .= '<br /><input type="checkbox" name="' . $name . '" value="' . $select_array[ $i ] . '"';
//            $key_values = explode( ";", $key_value );
//            if ( in_array( $select_array[ $i ], $key_values ) ) {
//                $string .= ' checked="checked"';
//            }
//
//            $string .= '> <small>[' . $select_array[ $i ] . '] ' . zip_get_usps_service_code( $select_array[ $i ] ) . '</small>';
//        }
//        $string .= '<input type="hidden" name="' . $name . '" value="--none--" />';

        return $string;

    }

    /**
     * @param        $select_array
     * @param        $key_value
     * @param string $key
     *
     * @return string
     */
    function zip_usps_cfg_select_multioption( $select_array, $key_value, $key = '' ) {

        $string = '';
        for ( $i = 0; $i < sizeof( $select_array ); $i ++ ) {
            $name       = ( ( $key ) ? 'configuration[' . $key . '][]' : 'configuration_value' );
            $string     .= '<br /><input type="checkbox" name="' . $name . '" value="' . $select_array[ $i ] . '"';
            $key_values = explode( ";", $key_value );
            if ( in_array( $select_array[ $i ], $key_values ) ) {
                $string .= ' checked="checked"';
            }

            $string .= '> <small>[' . $select_array[ $i ] . '] ' . zip_get_usps_service_code( $select_array[ $i ] ) . '</small>';
        }
        $string .= '<input type="hidden" name="' . $name . '" value="--none--" />';

        return $string;
    }

    /**
     * @param $values
     *
     * @return string
     */
    function zip_get_multioption_usps_addon_xml( $values ) {

        if ( ! empty( $values ) ) {
            $values_array = explode( ';', $values );

            foreach ( $values_array as $key => $_method ) {

                $method                  = zip_get_usps_addon_service_code( $_method );
                $readable_values_array[] = '<br/>' . $method;

            }

            return implode( '', $readable_values_array );
        } else {
            return '--none--';
        }
    }

    /**
     * @param $key
     *
     * @return string
     */
    function zip_get_usps_addon_service_code( $key ) {

        $addon = '';
        if ( $key == '100' ) {
            $addon = 'Insurance';
        } else if ( $key == '101' ) {
            $addon = 'Insurance -Priority Mail Express';
        } else if ( $key == '103' ) {
            $addon = 'Collect on Delivery';
        } else if ( $key == '104' ) {
            $addon = 'Certificate of Mailing (Form 3665)';
        } else if ( $key == '105' ) {
            $addon = 'Certified Mail';
        } else if ( $key == '106' ) {
            $addon = 'USPS Tracking';
        } else if ( $key == '107' ) {
            $addon = 'International Insurance';
        } else if ( $key == '108' ) {
            $addon = 'Signature Confirmation';
        } else if ( $key == '109' ) {
            $addon = 'Registered Mail';
        } else if ( $key == '112' ) {
            $addon = 'Registered mail COD collection Charge';
        } else if ( $key == '119' ) {
            $addon = 'Adult Signature Required';
        } else if ( $key == '120' ) {
            $addon = 'Adult Signature Restricted Delivery';
        } else if ( $key == '125' ) {
            $addon = 'Insurance -Priority Mail';
        } else if ( $key == '155' ) {
            $addon = 'USPS Tracking Electronic';
        } else if ( $key == '156' ) {
            $addon = 'Signature Confirmation Electronic';
        } else if ( $key == '160' ) {
            $addon = 'Certificate of Mailing (Form 3817)';
        } else if ( $key == '161' ) {
            $addon = 'Priority Mail Express 1030 AM Delivery';
        } else if ( $key == '170' ) {
            $addon = 'Certified Mail Restricted Delivery';
        } else if ( $key == '171' ) {
            $addon = 'Certified Mail Adult Signature Required';
        } else if ( $key == '172' ) {
            $addon = 'Certified Mail Adult Signature Restricted Delivery';
        } else if ( $key == '173' ) {
            $addon = 'Signature Confirm. Restrict. Delivery';
        } else if ( $key == '174' ) {
            $addon = 'Signature Confirmation Electronic Restricted Delivery';
        } else if ( $key == '175' ) {
            $addon = 'Collect on Delivery Restricted Delivery';
        } else if ( $key == '176' ) {
            $addon = 'Registered Mail Restricted Delivery';
        } else if ( $key == '177' ) {
            $addon = 'Insurance Restricted Delivery';
        } else if ( $key == '178' ) {
            $addon = 'Insurance Restrict. Delivery -Priority Mail Express';
        } else if ( $key == '179' ) {
            $addon = 'Insurance Restrict.  Delivery -Priority Mail';
        } else if ( $key == '180' ) {
            $addon = 'Scan Retention';
        } else if ( $key == '181' ) {
            $addon = 'Insurance Restrict. Delivery (Bulk Only)';
        } else if ( $key == '182' ) {
            $addon = 'Scan + Signature Retention';
        } else if ( $key == '190' ) {
            $addon = 'Special Handling -Fragile';
        }

        return $addon;
    }

    /**
     * @param        $select_array
     * @param        $key_value
     * @param string $key
     *
     * @return string
     */
    function zip_usps_addon_cfg_select_multioption( $select_array, $key_value, $key = '' ) {

        $string = '';
        for ( $i = 0; $i < sizeof( $select_array ); $i ++ ) {
            $name       = ( ( $key ) ? 'configuration[' . $key . '][]' : 'configuration_value' );
            $string     .= '<br /><input type="checkbox" name="' . $name . '" value="' . $select_array[ $i ] . '"';
            $key_values = explode( ";", $key_value );
            if ( in_array( $select_array[ $i ], $key_values ) ) {
                $string .= ' checked="checked"';
            }

            $string .= '> <small>' . zip_get_usps_addon_service_code( $select_array[ $i ] ) . '</small>';
        }
        $string .= '<input type="hidden" name="' . $name . '" value="--none--" />';

        return $string;
    }