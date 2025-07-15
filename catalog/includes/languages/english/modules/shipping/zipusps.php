<?php

    

/*
* $Id: zipusps.php
* $Loc: /includes/languages/english/modules/shipping/
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





    //API Info https://www.usps.com/business/web-tools-apis/

    //API Docs https://www.usps.com/business/web-tools-apis/rate-calculator-api.pdf

    define( 'MODULE_SHIPPING_ZIP_USPS_TEXT_TITLE', 'Zipur - USPS Service' );
    define( 'MODULE_SHIPPING_ZIP_USPS_LANG_TEXT_TITLE', 'USPS' );
    define( 'MODULE_SHIPPING_ZIP_USPS_LANG_PACKAGE', 'Package' );
    define( 'MODULE_SHIPPING_ZIP_USPS_LANG_PACKAGES', 'Packages' );
    define( 'MODULE_SHIPPING_ZIP_USPS_LANG_TOTAL', 'Total' );

    define( 'MODULE_SHIPPING_ZIP_USPS_TEXT_DESCRIPTION', 'USPS Price Calc API (XML) (Zipur\'s Version)<br/><a href="https://phoenixcart.org/forum/app.php/addons/free_addon/usps_shipping_module" target="_blank">https://phoenixcart.org/forum/app.php/addons/free_addon/usps_shipping_module</a><hr />You will need to have API USERID AND PASSWORD<br /><br />details please visit <a href="https://www.usps.com/business/web-tools-apis/">www.usps.com/business/web-tools-apis/</a> <br/> THIS MOD ALSO REQUIRES PHP-XML TO BE INSTALLED ON SERVER' );

