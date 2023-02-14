<?php
namespace FreyYardi;

use \FreyYardi\Psbp;

defined('ABSPATH') || die();

class Link extends Psbp{

	/**
	 * Prefix
	 */
	public $prefix = '_link';

	/**
	 * Client id
	 */
	public $client_id = 'Linklogistics';

	/**
	 * Client secret
	 */
	public $client_secret = 'f3fd04ca63b16369d8f97f37364614b7';

    /**
     * Access token key
     */
    public $accessTokenKey = '_link_access_token_key';


	/**
	 * Constructor
	 */
	public function __construct() {

		parent::__construct();

	}

}