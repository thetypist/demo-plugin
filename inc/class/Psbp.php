<?php

namespace FreyYardi;

defined( 'ABSPATH' ) || die();

class Psbp extends FreyYardi {
	
	/**
	 * Preix
	 */
	public $prefix = '_psbp';
	
	/**
	 * Client id
	 *
	 * @since 1.0
	 */
	public $client_id = 'psbusinessparks';
	
	/**
	 * Client secret
	 *
	 * @since 1.0
	 */
	public $client_secret = '5f1476fee2064a5b52525a71ac17fac2';

    /**
     * Access token key
     */
    public $accessTokenKey = '_fpy_access_token_key';
	
	/**
	 * Token Endpoint
	 *
	 * @since 1.0
	 */
	const TOKEN_ENDPOINT = 'https://listingsapi.commercialcafe.com/identity/connect/token';
	
	/**
	 * Properties endpoint
	 *
	 * @since 1.0
	 */
	const PROPERTIES_ENDPOINT = 'https://listingsapi.commercialcafe.com/syndication/getallproperties';
	
	/**
	 * Details endpoint
	 *
	 * @since 1.0
	 */
	const PROPERTY_DETAILS_ENDPOINT = 'https://listingsapi.commercialcafe.com/syndication/getdetailsbyid';
	
	/**
	 * Add website link
	 *
	 * @since 1.0
	 */
	const ADD_WEBSITE_LINK = 'https://listingsapi.commercialcafe.com/syndication/addWebsiteLink';
	
	/**
	 * Constructor
	 *
	 * @since 1.0
	 *
	 */
	public function __construct() {
		
		parent::__construct();
		
	}
	
	/**
	 * Get properties
	 *
	 * @since  1.0
	 *
	 * @access public
	 * @static
	 *
	 */
	public function getAllProperties( $update = false ) {
		
		$field = $this->prefix . '_fpy_all_props';
		$today = current_time( 'Y-m-d' );
		$data  = maybe_unserialize( get_option( $field, array() ) );
		if ( !$update ) {
			if ( isset( $data['date'] ) && $data['date'] == $today && !empty( $data['data'] ) ) {
				return $data['data'];
			}
		}
		$headers  = array(
			'Content-Type'  => 'application/json',
			'Authorization' => 'Bearer ' . $this->getAccessToken(),
		);
		$response = wp_remote_get(
			self::PROPERTIES_ENDPOINT,
			array(
				'method'  => 'GET',
				'headers' => $headers,
			)
		);
		if ( $response['response']['code'] == 200 ) {
			$props = json_decode( $response['body'], true );
			$data  = array(
				'date'       => $today,
				'data'       => $props,
				'status'     => 'completed',
				'updated_at' => current_time( 'mysql' ),
			
			);
			update_option( $field, $data, 'no' );
			
			return $props;
			
		} else {
			
			return $response['body'];
			
		}
		
	}
	
	/**
	 *
	 * Get property details by id
	 *
	 * @since  1.0
	 * @access public
	 * @static
	 */
	public function getPropertyDetailsById( $id = 0 ):array {
		
		$headers = array(
			'Content-Type'  => 'application/json',
			'Authorization' => 'Bearer ' . $this->getAccessToken(),
		);
		
		$response = wp_remote_post(
			self::PROPERTY_DETAILS_ENDPOINT . '?id=' . $id,
			array(
				'method'  => 'GET',
				'headers' => $headers,
				'body'    => '',
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'code' => $response->get_error_code(),
				'data' => null,
			);
		}
		if ( $response['response']['code'] == 200 ) {
			return array(
				'code' => $response['response']['code'],
				'data' => json_decode( $response['body'], 1 ),
			);
		}
		
		return array(
			'code' => $response['response']['code'],
			'data' => null,
		);
	}
	
	/**
	 * Get batch properties to store in wp_options table
	 *
	 * @step   1
	 *
	 * @since  1.0
	 * @access public
	 */
	public function fetchBatchProps( $limit = 25 ) {
		
		$field = $this->getStepFieldName( 1 );
		$value = maybe_unserialize( get_option( $field, array() ) );

		$today     = self::getDate( 'today' );
		$yesterday = self::getDate( 'yesterday' );
		
		if ( isset( $value[ $today ]['status'] ) && $value[ $today ]['status'] == 'completed' ) {
			return $value[ $today ]['data'] ?? null;
		}
		
		// unset values that are not of today and yesterday
		if ( !empty( $value ) ) {
			foreach ( $value as $k => $v ) {
				if ( !in_array( $k, array( $today, $yesterday ) ) ) {
					unset( $value[ $k ] );
				}
			}
		}
		
		// Get already processed properties ids
		$processed_props = isset( $value[ $today ]['data'] ) ? array_keys( $value[ $today ]['data'] ) : array();
		
		$count_limit = 0;
		
		// Run batch properties
		$all_props = $this->getAllProperties( true );

        // if no properties found, skip the step
        if( empty($all_props)){

            $skip_value = array();

            $skip_value[$today] = array(
                'data'  => array(),
                'status' => 'completed'
            );

            update_option( $field, $skip_value, 'no');
            $this->updateStepStatus( 1, 'completed', array() );

        }
		
		if ( !empty( $all_props ) ) {
			foreach ( $all_props as $k => $v ) {
				
				if ( count( array_diff( $all_props, $processed_props ) ) == 0 ) {
					$this->updateStepStatus( 1, 'completed', $processed_props, 'today' );
				}
				
				if ( in_array( $v, $processed_props ) ) {
					continue;
				}
				if ( $count_limit > $limit ) {
					break;
				}
				
				$prop = self::getPropertyDetailsById( $v );
				
				if ( $prop['code'] == 200 ) {
					
					$spaceListings = $prop['data']['SpaceListings'] ?? array();
					
					// Insert spaces if exist
					if ( !empty( $spaceListings ) ) {
						foreach ( $spaceListings as $spaceListing ) {
							$value[ $today ]['data'][ $v ][] = $spaceListing['Id'];
						}
					} else {
						// Spaces found
						$value[ $today ]['data'][ $v ] = array();
					}
					$processed_props[] = $v;
					$count_limit ++;
					
				} else if ( $prop['code'] == 500 ) {
					
					$value[ $today ]['data'][ $v ] = array();
					$processed_props[]             = $v;
					$count_limit ++;
					
				} else {
					// Do nothing
					// continue;
					echo $prop['code'];
				}
				
				// Update data
				update_option( $field, $value, 'no' );

				// Check if current batch crosses limit or not
				if ( $count_limit >= $limit ) {
					
					$value[ $today ]['status'] = 'processing';
					update_option( $field, $value, 'no' );
					
					return $value[ $today ]['data'];
					
				}
				
				// Checking if all props are found in processed props
				if ( count( array_diff( $all_props, $processed_props ) ) == 0 ) {
					
					$value[ $today ]['status'] = 'completed';
					update_option( $field, $value, 'no' );
					
					return $value[ $today ]['data'];
					
				}
			}
		}
	}
	
	/**
	 * Get step field key
	 *
	 * @param int $step
	 *
	 * @return string
	 * @since  1.0
	 * @access public
	 * @static
	 */
	public function getStepFieldName( int $step = 1 ):string {
		
		return $this->prefix . '_fpy_field_step_' . $step;
		
	}
	
	/**
	 * Get step status
	 * step_1: fetch properties data
	 * step_2: compare data
	 * step_3: insert new spaces
	 * step_4: delete unpublished spaces
	 * step_5: update existing spaces
	 * step_6: update duplicate titles
	 *
	 * @param int $step
	 *
	 * @return string
	 *
	 * @since  1.0
	 * @access public
	 * @static
	 */
	public function getStepStatus( int $step ):?string {
		
		$step   = intval( trim( $step ) );
		$status = 'processing';
		
		$field = $this->getStepFieldName( $step );
		$value = maybe_unserialize( get_option( $field ) );
		
		$today = self::getDate( 'today' );
		
		switch ( $step ) {
			case 1:
				// Fetch Properties
				$status = $value[ $today ]['status'] ?? 'processing';
				break;
			case 2:
			case 3:
			case 4:
			case 5:
			case 6:
			case 7:
				$status = isset( $value['date'] ) && $value['date'] == self::getDate( 'today' ) ? $value['status'] : 'processing';
				break;
			default:
				break;
		}
		
		return $status;
	}
	
	/**
	 * Step 2, compare data
	 * It will work only if step 1 is complete
	 *
	 * @step   2
	 *
	 * @return void
	 * @access public
	 * @since  1.1
	 */
	public function compareProps( $update = false ) {
		
		if ( $this->getStepStatus( 1 ) != 'completed' ) {
			return 'Step 1 is still processing';
		}
		
		$field = $this->getStepFieldName( 2 );
		
		if ( !$update ) {
			
			$value = maybe_unserialize( get_option( $field ) );
			if ( isset( $value[ self::getDate( 'today' ) ] ) ) {
				return $value[ self::getDate( 'today' ) ];
			}
			
		}
		
		$new_props = $this->getAllPropertiesWithSpaces( 'today' );
		$old_props = $this->getAllPropertiesWithSpaces( 'yesterday' );
		
		
		// If new props are, we need to fetch batch properties
		if ( !is_array( $new_props ) ) {
			
			$this->fetchBatchProps();
			
		}
		
		if ( !is_array( $old_props ) ) {
			
			$old_props = array();
			
		}
		
		// Old props are empty, check prop exists in posts
		//		if(!is_array($old_props) || empty($old_props)){
		//
		//			$old_props = array();
		//			$post_props = $this->getPropIdsFromPosts();
		//			if(!empty($post_props)){
		//				foreach($post_props as $pk){
		//					$old_props[$pk] = array();
		//				}
		//			}
		//
		//		}

		$new_props_keys = !empty($new_props) ? array_keys( $new_props ) : array();
		$old_props_keys = !empty($old_props) ? array_keys( $old_props ) : array();
		
		$existing_props    = array_intersect( $new_props_keys, $old_props_keys );
		$unpublished_props = array_diff( $new_props_keys, $old_props_keys );
		$expired_props     = array_diff( $old_props_keys, $new_props_keys );
		
//		var_dump( $existing_props );
//		echo "<br><br>";
//		var_dump( $unpublished_props );
//		echo "<br><br>";
//		var_dump( $expired_props );
//		echo "<br><br>";
		
		$step_data = array(
			'existing'    => $existing_props,
			'unpublished' => $unpublished_props,
			'expired'     => $expired_props,
		);
		
		$this->updateStepStatus( 2, 'completed', $step_data );
		
	}
	
	
	/**
	 * Get All Properties With Spaces
	 *
	 * @param string $day today|yesterday
	 *
	 * @since  1.0
	 * @access public
	 * @static
	 */
	public function getAllPropertiesWithSpaces( string $day = 'today' ) {
		
		$date  = self::getDate( $day );
		$value = maybe_unserialize( get_option( $this->getStepFieldName( 1 ) ) );
		
		return $value[ $date ]['data'] ?? null;
		
	}
	
	/**
	 * Get all spaces of a property
	 *
	 * @param int $propertyId
	 *
	 * @since  1.0
	 * @access public
	 *
	 */
	public function getSpaces( $propertyId, $date = 'today' ) {
		
		$field = $this->getStepFieldName( 1 );
		$value = maybe_unserialize( get_option( $field ) );
		if ( empty( $date ) ) {
			$date = self::getDate( $date );
		}
		
		return $value[ $date ]['data'][ $propertyId ] ?? null;
		
	}
	
	/**
	 * Process unpublished props
	 *
	 * @step   3
	 *
	 * @since  1.0
	 * @access public
	 */
	public function insertUnpublishedProps( $limit = 5, $update = false ) {
		
		if ( $this->getStepStatus( 2 ) != 'completed' ) {
			return "Step 2 is not completed";
		}
		
		$value = maybe_unserialize( get_option( $this->getStepFieldName( 3 ), array() ) );
		$value = is_array( $value ) ? $value : array();
		
		$unpublished_props = self::getUnpublishedProps();
		if ( empty( $unpublished_props ) ) {
			
			$this->updateStepStatus( 3, 'completed', array() );
			
			return 'No unpublished props left to process.';
			
		}

		// Getting data for ignored ids
		$settings_data = maybe_unserialize(get_option('_fy_settings_data'));
		$ignored_ids = explode(',', $settings_data['ignored_ids']);
		$ignored_ids = array_map('intval', $ignored_ids);

		$ignored_business_park_ids = explode(',', $settings_data['ignored_business_park_ids'] );
		$ignored_business_park_ids = array_map('intval', $ignored_business_park_ids);

		$processed_props = array();
		
		if ( isset( $value['date'] ) && $value['date'] == self::getDate( 'today' ) ) {

			$processed_props = $value['data'];

		}

		// Inserting ignored ids into processed props so that they are not processed further
		if ( !empty($ignored_ids) ){

			foreach($ignored_ids as $v){

				$processed_props[$v] = array();

			}

		}

		// Tracking prop to check limit
		$count_limit = 0;
		
		foreach ( $unpublished_props as $k ) {
			
			// if all unpublished props process, update status
			if ( count( array_diff( $unpublished_props, array_keys( $processed_props ) ) ) == 0 ) {
				
				$this->updateStepStatus( 3, 'completed', $processed_props );
				
				return 'all props are processed';
				
			}
			
			// Check if the prop is already processed
			if ( array_key_exists( $k, $processed_props ) ) {
				continue;
			}
			
			// Check if the prop has spaces or not
			// If there is no space, we'll store it to processed_props
			$prop = self::getPropertyDetailsById( $k );
			
			$spaces = $prop['data']['SpaceListings'] ?? null;
			
			// Prop has no spaces, so skip
			if ( empty( $spaces ) ) {
				
				$processed_props[ $k ] = array();
				$this->updateStepStatus( 3, 'processing', $processed_props );
				continue;
				
			}
			
			// Storing prop data in an parentData array
			$parentData = array();
			foreach ( $prop['data'] as $pk => $pv ) {
				if ( $pk == 'SpaceListings' ) {
					continue;
				}
				$parentData[ $pk ] = $pv;
			}

			// Check if BusinessParkId is in ignored list
			// If found in ignored list, we'll consider it as processed props
			// and continue to the next
			if ( !empty($parentData['BusinessParkId']) ){

				if ( in_array( intval( $parentData['BusinessParkId'] ), $ignored_business_park_ids ) ) {

					$processed_props[$k] = array();
					continue;

				}

			}

			// Check limit
			if ( $count_limit > $limit ) {
				break;
			}
			
			// Storing all data as spaceData
			foreach ( $spaces as $space ) {
				$spaceData = array();
				foreach ( $space as $sk => $sv ) {
					$spaceData[ $sk ] = $sv;
				}
				
				// Inserting Space into WP
				$post_id = $this->processSpace( $spaceData, $parentData, 'insert' );
				
				echo "Inserting prop_id {$k}  | Space {$space['Id']} | post id {$post_id} } <br>";
				
				// Storing space id into wp table
				$processed_props[ $k ][] = $space['Id'];
				
				$this->updateStepStatus( 3, 'processing', $processed_props );
				
			}
			
			$count_limit ++;
			
		}
		
	}
	
	/**
	 * Delete expired properties
	 *
	 * @step   4
	 *
	 * @param int  $limit
	 * @param bool $update
	 *
	 * @return string|void
	 * @since  1.0
	 * @access public
	 */
	public function deleteExpiredProps( int $limit = 25, bool $update = false ) {
		
		if ( $this->getStepStatus( 3 ) != 'completed' ) {
			return "Step 3 is not completed";
		}
		
		if ( !$update && $this->getStepStatus( 4 ) == 'completed' ) {
			return 'completed';
		}
		
		$field = $this->getStepFieldName( 4 );
		$value = maybe_unserialize( get_option( $field, array() ) );
		
		$expiredProps = self::getExpiredProps();
		
		if ( empty( $expiredProps ) ) {
			$this->updateStepStatus( 4, 'completed', array() );
			
			return 'completed';
		}
		
		$processedProps = isset( $value['date'] ) && $value['date'] == self::getDate( 'today' ) && isset( $value['data'] ) ? $value['data'] : array();
		
		foreach ( $expiredProps as $k ) {
			
			// Check if all props are processed or not
			// If processed, update the status and return
			if ( count( array_diff( $expiredProps, array_keys( $processedProps ) ) ) == 0 ) {
				$this->updateStepStatus( 4, 'completed', $processedProps );
				
				return 'completed';
			}
			
			
			if ( array_key_exists( $k, $processedProps ) ) {
				continue;
			}
			
			// Get all spaces of the prop
			$spaces = self::getSpacesByPropertyId( $k );
			
			// No spaces found, skip
			if ( empty( $spaces ) ) {
				$processedProps[ $k ] = array();
				continue;
			}
			
			foreach ( $spaces as $space ) {
				$post_id = self::getPostIdByMetaKey( 'Id', $space );
				$delete  = wp_delete_post( $post_id );
				if ( !is_wp_error( $delete ) ) {
					$processedProps[ $k ][] = $space;
				}
			}
			
		}
		
		// Updating status after all spaces deleted
		$this->updateStepStatus( 4, 'completed', $processedProps );
	}
	
	/**
	 * Step 5
	 * Update existing props
	 *
	 * @param int $limit
	 *
	 * @since  1.0
	 * @access public
	 */
	public function updateExistingProps( $limit = 10 ) {

		$prev_status = $this->getStepStatus( 4 );
		if ( $prev_status != 'completed' ) {

			return "Step 4 is not completed yet";

		}
		
		$field = $this->getStepFieldName( 5 );
		$value = maybe_unserialize( get_option( $field, array() ) );
		
		$props = $this->getExistingProps();
		
		$processedProps = isset($value['date']) && $value['date'] == current_time( 'Y-m-d' ) ? $value['data'] : array();
		
		if ( empty( $props ) ) {
			
			$this->updateStepStatus( 5, 'completed', array() );
			
		}
		
		if ( !is_array( $props ) ) {
			
			$props = array();
			
		}

		// Getting data for ignored ids
		$settings_data = maybe_unserialize(get_option('_fy_settings_data'));
		$ignored_ids = explode(',', $settings_data['ignored_ids']);
		$ignored_ids = array_map('intval', $ignored_ids);

		// Ignored business park ids
		$ignored_business_park_ids = explode(',', $settings_data['ignored_business_park_ids'] );
		$ignored_business_park_ids = array_map('intval', $ignored_business_park_ids);

		// Inserting ignored ids into processed props so that they are not processed further
		if ( !empty($ignored_ids) ){

			foreach($ignored_ids as $v){

				$processedProps[$v] = array();

			}

		}


		$count_limit = 0;
		foreach ( $props as $propId ) {
			
			// Check if all props are processed or not
			// If processed, update status
			if ( count( array_diff( $props, array_keys( $processedProps ) ) ) == 0 ) {
				$this->updateStepStatus( 5, 'completed', $processedProps );
				
				return 'completed';
			}
			
			// Check if propertyId already processed or not
			if ( array_key_exists( $propId, $processedProps ) ) {
				continue;
			}
			
			// Skip if limit crossed
			if ( $count_limit >= $limit ) {
				$this->updateStepStatus( 5, 'processing', $processedProps );
				break;
			}
			
			// Get property details
			$prop_qry = self::getPropertyDetailsById( $propId );
			
			if ( $prop_qry['code'] == 200 ) {
				$prop_data = $prop_qry['data'];
				
				$parent_data = array();
				foreach ( $prop_data as $k => $v ) {
					if ( $k == 'SpaceListings' ) {
						continue;
					}
					$parent_data[ $k ] = $v;
				}

				// Check if BusinessParkId is in ignored list
				// If found in ignored list, we'll consider it as processed props
				// and continue to the next
				if ( !empty($parent_data['BusinessParkId']) ){

					if ( in_array( intval( $parent_data['BusinessParkId'] ), $ignored_business_park_ids ) ) {

						$processedProps[$propId] = array();
						$this->updateStepStatus( 5, 'processing', $processedProps );
						continue;

					}

				}
				
				$spaces = $prop_data['SpaceListings'] ?? null;
				
				// No spaces found
				if ( empty( $spaces ) ) {
					$processedProps[ $propId ] = array();
					$this->updateStepStatus( 5, 'processing', $processedProps );
				}
				
				// Get all space Ids
				$new_space_ids = array_column( $spaces, 'Id' );
//				self::debugArray( $new_space_ids, 'New spaces' );
				if ( !empty( $space_ids ) ) {
					$new_space_ids = array_map( 'intval', $space_ids );
				}
				
				$published_space_ids = self::getSpacesByPropertyId( $propId );
//				self::debugArray( $published_space_ids, 'Published spaces' );
				if ( !empty( $published_space_ids ) ) {
					$published_space_ids = array_map( 'intval', $published_space_ids );
				}
				
				// Check if we have any space that exist in our database but not in source database
				$expired_spaces = array_diff( $published_space_ids, $new_space_ids );
				
//				self::debugArray( $expired_spaces, 'Expired spaces' );
				
				// Expired spaces found, we'll delete them now
				if ( count( $expired_spaces ) > 0 ) {
					foreach ( $expired_spaces as $sk ) {
						// Delete post
						$post_id = self::getPostIdByMetaKey( 'id', $sk );
//						echo "Deleting propId {$propId} post ID {$post_id}<br>";
						if ( $post_id ) {
							wp_delete_post( $post_id );
						}
					}
				}
				
				foreach ( $spaces as $space ) {
					$space_data = array();
					foreach ( $space as $sk => $sv ) {
						$space_data[ $sk ] = $sv;
					}
					
					$update = $this->processSpace( $space_data, $parent_data );
					if ( $update ) {
						$processedProps[ $propId ][] = $space['Id'];
						$this->updateStepStatus( 5, 'processing', $processedProps );
					}
				}
			}
			$count_limit ++;
		}
	}


	
	/**
	 * Get prop ids from published posts
	 *
	 * @return array|null
	 *
	 * @since  1.0
	 * @access public
	 * @static
	 *
	 */
	public static function getPropIdsFromPosts() {
		
		global $wpdb;
		
		$qry = "SELECT * FROM {$wpdb->prefix}postmeta WHERE meta_key = '%s' AND meta_value != '' ";
		
		$results = $wpdb->get_results( $wpdb->prepare( $qry, 'parent_id' ) );
		
		if ( empty( $results ) ) {
			return null;
		}
		
		$ids = array();
		
		foreach ( $results as $result ) {
			
			$ids[] = (int) $result->meta_value;
			
		}
		
		return array_unique( $ids );
		
	}
	
	
	/**
	 * Process space data
	 *
	 * @param array  $spaceData
	 * @param array  $parentData
	 * @param string $action insert | update
	 *
	 * @return int
	 *
	 * @return int|string|WP_Error
	 * @since  1.0
	 * @access public
	 * @static
	 */
	public function processSpace( array $spaceData = array(), array $parentData = array(), string $action = 'insert' ) {

		// Check if space already exists
		$space_post_id = self::getPostIdByMetaKey( 'id', $spaceData['Id'] );
		if ( $space_post_id ) {
			
			$post_id = $space_post_id;
			
		} else if ( $action == 'insert' ) {
			
			// Insert new availability
			$args = array(
				'post_type'   => 'availabilities',
				'post_status' => 'publish',
				'post_title'  => !empty( trim( $spaceData['Address'] ) ) ? $spaceData['Address'] : $parentData['Address'],
			);
			
			$post_id = wp_insert_post( $args );
			
			if ( is_wp_error( $post_id ) ) {
				return $post_id->get_error_code();
			}
			
			update_post_meta( $post_id, '_source', $this->prefix );
			
		}

		// Inserting Yoast Meta Desc
		$post_title = get_the_title($post_id);
		$desc = "{$spaceData['SpaceAvailableSqft']} SF of {$spaceData['SpaceType']} Space Available at {$post_title} in {$spaceData['City']}, {$spaceData['State']}.";
		update_post_meta( $post_id, '_yoast_wpseo_metadesc', $desc );

		
		// Insert Parent Data
		$parentFields = self::getParentFields();
		foreach ( $parentFields as $k => $v ) {
			if ( isset( $parentData[ $k ] ) && !empty( $parentData[ $k ] ) ) {
				
				$val = $parentData[ $k ] ?? null;
				
				if ( empty( $val ) ) {
					continue;
				}
				
				// If field key is Market, get proper market name
				if ( $k == 'Market' ) {
					$val = self::getMarketName( $val );
				}
				
				// Checking if json data or not
				if ( gettype( $val ) == 'string' && json_decode( $val, true ) !== null ) {
					$val = json_decode( $val, true );
				}
				
				// Converting into string if the data type is array
				if ( is_array( $val ) ) {
					$val = implode( '\n', $val );
				}
				
				if ( !empty( $val ) ) {
					update_post_meta( $post_id, $v, $val );
				}
			}
		}
		
		
		// Insert Space data
		$spaceFields = self::getSpaceFields();
		foreach ( $spaceFields as $k => $v ) {
			
			$val = $spaceData[ $k ] ?? null;
			
			if ( empty( $val ) ) {
				continue;
			}
			
			// Strip all html tags form SpaceDescription
			if ( $k == 'SpaceDescription' ) {
				$val = wp_strip_all_tags( $val );
			}
			
			// Check if value is json or not
			if ( gettype( $val ) == 'string' && json_decode( $val ) != null ) {
				$val = json_decode( $val, true );
			}
			
			// Getting first value if $val is array
			if ( is_array( $val ) ) {
				$val = reset( $val );
			}
			
			if ( !empty( $val ) ) {
				update_post_meta( $post_id, $v, $val );
			}
			
		}
		
		// Insert data from parent fields to put in data-search field
		$business_park_id = get_post_meta( $post_id, 'business_park_id', true );
		$park_id_compare  = get_post_meta( $post_id, 'park_id', true );
		
		if ( $business_park_id > 0 ) {
			$park_id_compare = $business_park_id;
		}
		
		
		// Getting parent park data
		global $wpdb;
		$park_post_id = (int) $wpdb->get_var( "SELECT post_id FROM {$wpdb->prefix}postmeta WHERE meta_key = 'park_id' AND meta_value = {$park_id_compare} " );
		if ( $park_post_id ) {
			
			$additional_cities  = get_post_meta( $park_post_id, 'additional_cities', true );
			$market_name        = get_post_meta( $park_post_id, 'market_name', true );
			$submarket_name     = get_post_meta( $park_post_id, 'submarket_name', true );
			$park_city          = get_post_meta( $park_post_id, 'park_city', true );
			$park_state         = get_post_meta( $park_post_id, 'park_state', true );
			$parent_market_name = get_post_meta( $park_post_id, 'market_name', true );
			update_post_meta( $post_id, 'parent_market_name', $parent_market_name );
			
			$space_ad_cities = "{$additional_cities} {$market_name} {$submarket_name} {$park_city} {$park_state}";
			
			update_post_meta( $post_id, 'additional_cities', trim( $space_ad_cities ) );
		}
		
		
		// Brokers
		$brokers = $spaceData['Brokers'] ?? null;
		if ( !empty( $brokers ) ) {
			$b       = 1;
			$b_limit = 4;
			
			// First deleting existing data to avoid duplicates
			for ( $i = 1; $i <= 4; $i ++ ) {
				
				delete_post_meta( $post_id, 'broker_first_name_' . $i );
				delete_post_meta( $post_id, 'broker_last_name_' . $i );
				delete_post_meta( $post_id, 'broker_email_' . $i );
				
			}
			
			// Inserting new meta data
			foreach ( $brokers as $broker ) {
				if ( $b > $b_limit ) {
					break;
				}
				update_post_meta( $post_id, 'broker_first_name_' . $b, $broker['FirstName'] );
				update_post_meta( $post_id, 'broker_last_name_' . $b, $broker['LastName'] );
				update_post_meta( $post_id, 'broker_email_' . $b, $broker['Email'] );
				$b ++;
			}
		}
		
		// Photos
		$photos = $spaceData['Photos'] ?? null;
		if ( !empty( $photos ) ) {
			$p = 1;
			foreach ( $photos as $photo ) {
				if ( empty( $photo['PhotoUrl'] ) ) {
					continue;
				}
				update_post_meta( $post_id, 'availability_photo_' . $p, $photo['PhotoUrl'] );
				update_post_meta( $post_id, 'availability_photo_alt_' . $p, $photo['AltText'] );
				$p ++;
			}
		}
		
		// Space video URL
		$videoUrl = $space['Video']['RegularUrl'] ?? null;
		if ( !empty( $videoUrl ) ) {
			update_post_meta( $post_id, 'property_video', $videoUrl );
		}
		
		// Industrial Extra Info
		$industrialInfo = $spaceData['IndustrialExtraInfo'] ?? null;
		if ( !empty( $industrialInfo ) ) {
			$industrialFields = self::getIndustrialExtraFields();
			foreach ( $industrialFields as $k => $v ) {
				if ( isset( $industrialInfo[ $k ] ) && !empty( $industrialInfo[ $k ] ) ) {
					update_post_meta( $post_id, $v, $industrialInfo[ $k ] );
				}
			}
		}
		
		// User Defined Fields
		$userFields = $spaceData['UserDefinedFields'] ?? null;
		if ( !empty( $userFields ) ) {
			foreach ( $userFields as $uk ) {
				if ( $uk['Key'] == 'Listing Status' ) {
					$listing_status_val = is_null( $uk['Value'] ) ? 'null' : $uk['Value'];
					update_post_meta( $post_id, 'listing_status', $listing_status_val );
				}
			}
		}
		
		return $post_id;
	}
	
	/**
	 * Get post id by meta key
	 *
	 * @param string $key
	 * @param string $value
	 *
	 * @return int
	 *
	 * @since  1.0
	 * @access public
	 * @static
	 *
	 */
	public static function getPostIdByMetaKey( $key, $value, $post_type = 'availabilities' ) {
		$args = array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'meta_query'     => array(
				array(
					'key'     => $key,
					'value'   => $value,
					'compare' => '=',
				),
			),
		);
		$qry  = new \WP_Query( $args );
		if ( !$qry->have_posts() ) {
			wp_reset_query();
			wp_reset_postdata();
			
			return null;
		}
		$post_id = 0;
		while ( $qry->have_posts() ) {
			$qry->the_post();
			if ( $post_id === 0 ) {
				$post_id = $qry->post->ID;
				break;
			}
		}
		
		wp_reset_query();
		wp_reset_postdata();
		
		return $post_id;
		
	}
	
	/**
	 * Get parent park's fields
	 *
	 * @since  1.0
	 * @access public
	 * @static
	 *
	 */
	public static function getParentFields():array {
		
		return array(
			'IsBusinessPark'       => 'is_business_park',
			'BusinessParkId'       => 'business_park_id',
			'Id'                   => 'park_id',
			'Name'                 => 'park_name',
			'Type'                 => 'park_status',
			'PropertyType'         => 'park_type',
			'Address'              => 'park_address',
			'City'                 => 'park_city',
			'State'                => 'park_state',
			'Zip'                  => 'park_zip',
			'Latitude'             => 'park_latitude',
			'Longitude'            => 'park_longitude',
			'PropertySubType'      => 'park_sub_type',
			'PropertyTenancy'      => 'park_tenancy',
			'Market'               => 'market_name',
			'CustomMarket'         => 'custom_market_name',
			'DockLoadingDoors'     => 'dock_doors',
			'GradeLevelDoors'      => 'grade_doors',
			'ClearHeightMinInches' => 'clear_height_min',
			'ClearHeightMaxInches' => 'clear_height_max',
			'Amenities'            => 'features',
			'VirtualTour'          => 'tour_360',
			'Video'                => 'property_video',
			'Locations'            => 'location',
			'MinDivisible'         => 'min_divisible',
			'MaxContiguous'        => 'max_contiguous',
		);
		
	}
	
	/**
	 *
	 * Get market name
	 *
	 * @param $name string
	 *
	 * @since  1.0
	 * @access public
	 * @static
	 *
	 */
	public static function getMarketName( $name ) {
		
		switch ( trim( strtolower( $name ) ) ) {
		
//			case 'los angeles county':
//				$name = 'Los Angeles';
//				break;
//
//			case 'maryland':
//				$name = 'Suburban Maryland';
//				break;
//
//			case 'miami':
//			case 'palm beach':
//				$name = 'South Florida';
//				break;
//
//			case 'san diego county':
//				$name = 'San Diego';
//				break;
//
//			case 'san mateo/peninsula':
//				$name = 'San Francisco Peninsula';
//				break;
//
//			case 'silicon valley/santa clara':
//				$name = 'Silicon Valley';
//				break;
			
			// New Market Structure 2022/08
			// ============================
			
			case 'suburban maryland':
			case 'northern virgina':
			case 'maryland':
				$name = 'Washington Metro';
				break;
			
			case 'miami':
			case 'palm beach':
				$name = 'South Florida';
				break;
				
			case 'los angeles':
			case 'orange county':
			case 'san diego':
			case 'san diego county':
			case 'los angeles county':
				$name = 'Southern California';
				break;
			
			case 'silicon valley':
			case 'san francisco east bay':
			case 'san francisco peninsula':
			case 'san mateo/peninsula':
			case 'silicon valley/santa clara':
				$name = 'Northern California';
				break;
				
				
			
			default:
				// Do nothing
				break;
		}
		
		return $name;
	}
	
	/**
	 * Get space fields
	 *
	 * @since  1.0
	 * @access public
	 * @static
	 *
	 */
	public static function getSpaceFields() {
		
		return array(
			'Id'                   => 'id',
			'PropertyId'           => 'parent_id',
			'Name'                 => 'name',
			'SpaceAvailableSqft'   => 'available_space',
			'SpaceDescription'     => 'overview',
			'PossesionDate'        => 'date_available',
			'OfficeBuildOut'       => 'office_sf',
			'DockLoadingDoors'     => 'dock_doors',
			'SpaceAvailable'       => 'unit_size',
			'GradeLevelDoors'      => 'grade_doors',
			'SpaceType'            => 'property_type',
			'ParkingRatio'         => 'parking_ratio',
			'ClearHeightMinInches' => 'clear_height_min',
			'ClearHeightMaxInches' => 'clear_height_max',
			'VirtualTour'          => 'tour_360',
			'Floorplans'           => 'floorplans',
			'Attachments'          => 'attachments',
			'PrimarySpaceSubType'  => 'primary_space_sub_type',
			'Address'              => 'availability_address',
			'City'                 => 'availability_city',
			'State'                => 'availability_state',
			'Zip'                  => 'availability_zip',
			'Latitude'             => 'availability_latitude',
			'Longitude'            => 'availability_longitude',
			'Amenities'            => 'features',
			'MinDivisible'         => 'availability_min_divisible',
			'MaxContiguous'        => 'availability_max_contiguous',
		);
		
	}
	
	/**
	 * Get industrial extra fields
	 *
	 * @since  1.0
	 * @access public
	 * @static
	 *
	 */
	public static function getIndustrialExtraFields() {
		
		return array(
			'ClearHeightMinInches' => 'clear_height_min',
			'ClearHeightMaxInches' => 'clear_height_max',
			'OfficeArea'           => 'office_sf',
			'DockHighDoors'        => 'dock_doors',
			'GradeLevelDoors'      => 'grade_doors',
			'RampDoors'            => 'ramps',
			'ElectricityService'   => 'power',
			'TruckPositions'       => 'truck_positions',
		);
		
	}
	
	/**
	 * Get all unpublished props
	 *
	 * @since  1.0
	 * @access public
	 *
	 */
	public function getUnpublishedProps( $date = 'today' ) {
		
		// Check if step 2 (compareProperties) is complete or not
		// If not complete, returns string
		if ( $this->getStepStatus( 2 ) != 'completed' ) {
			return "Step 2 is not completed";
		}
		
		$field = $this->getStepFieldName( 2 );
		$value = maybe_unserialize( get_option( $field ) );
		if ( isset( $value['date'] ) && $value['date'] == self::getDate( $date ) ) {
			return $value['data']['unpublished'];
		}
		
		$this->compareProps();
		
		$value = maybe_unserialize( get_option( $field ) );
		if ( isset( $value['date'] ) && $value['date'] == self::getDate( $date ) ) {
			return $value['data']['unpublished'];
		}
		
		return array();
	}
	
	/**
	 * Get all expired props
	 *
	 * @since  1.0
	 * @access public
	 */
	public function getExpiredProps( $date = 'today' ) {
		
		// Check if step 2 (compareProps) is complete or not
		// If not complete, returns string
		if ( $this->getStepStatus( 2 ) != 'completed' ) {
			return "Step 2 is not completed";
		}
		
		$field = $this->getStepFieldName( 2 );
		$value = maybe_unserialize( get_option( $field ) );
		
		if ( isset( $value['date'] ) && $value['date'] == self::getDate( $date ) ) {
			return $value['data']['expired'];
		}
		
		$this->compareProps();
		
		$value = maybe_unserialize( get_option( $field ) );
		if ( isset( $value['date'] ) && $value['date'] == self::getDate( $date ) ) {
			return $value['data']['unpublished'];
		}
		
		return null;
	}
	
	/**
	 * Get all existing props
	 *
	 * @since  1.0
	 * @access public
	 *
	 */
	public function getExistingProps( $date = 'today' ) {
		
		// Check if step 2 (comparePropertis) is complete or not
		// If not complete, returns string
		if ( $this->getStepStatus( 2 ) != 'completed' ) {
			return "Step 2 is not completed";
		}
		
		$field = $this->getStepFieldName( 2 );
		$value = maybe_unserialize( get_option( $field, array() ) );
		
		if ( isset( $value['date'] ) && $value['date'] == self::getDate( $date ) ) {
			return $value['data']['existing'];
		}
		
		$this->compareProps();
		
		$value = maybe_unserialize( get_option( $field ) );
		if ( isset( $value['date'] ) && $value['date'] == self::getDate( $date ) ) {
			return $value['data']['unpublished'];
		}
		
		return null;
	}
	
	/**
	 * Delete all posts
	 *
	 * @param string post_type
	 *
	 * @since  1.0
	 * @access public
	 *
	 */
	public function deletePosts( string $post_type = 'availabilities', $check_source = false ):?int {
		
		$args = array(
			'post_type'      => $post_type,
			'posts_per_page' => - 1,
			'post_status'    => 'publish',
		);
		
		if ( $check_source ) {
			
			$args['meta_query'] = array(
				array(
					'key'     => '_source',
					'value'   => $this->prefix,
					'compare' => '=',
				),
			);
			
		}
		
		$qry = new \WP_Query( $args );
		
		if ( $qry->have_posts() ) {
			
			while ( $qry->have_posts() ) {
				
				$qry->the_post();
				wp_delete_post( $qry->post->ID );
				
			}
			
			return (int) $qry->found_posts;
			
		}
		
		return 0;
		
	}
	
	
	/**
	 * For debug purpose, reset yesterday's step 2 data
	 *
	 * @param string $day
	 *
	 * @since  1.0
	 * @access public
	 */
	public function resetStep( $step, $day = null ) {
		
		$field = $this->getStepFieldName( $step );
		$value = maybe_unserialize( get_option( $field, array() ) );
		
		if ( $day == 'today' ) {
			
			$date = self::getDate( 'today' );
			
		} else if ( $day == 'yesterday' ) {
			
			$date = self::getDate( 'yesterday' );
			
		} else {
			
			$date = $day;
			
		}
		
		switch ( intval( $step ) ) {
			
			case 1:
				$value[ $date ]['data']   = array();
				$value[ $date ]['status'] = 'processing';
				update_option( $field, $value, 'no' );
				echo "Step 1 reset";
				break;
			
			case 2:
				$value = array(
					'date'   => $date,
					'data'   => array(
						'unpublished' => array(),
						'existing'    => array(),
						'expired'     => array(),
					),
					'status' => 'processing',
				);
				update_option( $field, $value, 'no' );
				echo "Step 2 reset";
				break;
			
			case 3:
			case 4:
			case 5:
			case 6:
				update_option( $this->getStepFieldName( $step ), '', 'no' );
				echo "Step {$step} reset";
				break;
			
			case 7:
				delete_option( $this->getStepFieldName( $step ) );
				break;
			
			default:
				break;
		}
		
	}
	
	/**
	 * Get spaces by propertyId
	 *
	 * @param int    $propertyId
	 * @param string $field
	 *
	 * @return array | null
	 *
	 * @since  1.0
	 * @access public
	 * @static
	 *
	 */
	public static function getSpacesByPropertyId( $propertyId, $field = 'id' ) {
		$args = array(
			'post_type'      => 'availabilities',
			'post_status'    => 'publish',
			'posts_per_page' => - 1,
			'meta_query'     => array(
				array(
					'key'     => 'parent_id',
					'value'   => intval( $propertyId ),
					'compare' => '=',
					'type'    => 'NUMERIC',
				),
			),
		);
		$qry  = new \WP_Query( $args );
		
		// No spaces found, return empty array
		if ( !$qry->have_posts() ) {
			wp_reset_query();
			wp_reset_postdata();
			
			return array();
		}
		
		$spaces = array();
		while ( $qry->have_posts() ) {
			
			$qry->the_post();
			
			if ( $field == 'id' ) {
				echo $qry->post->ID;
				echo "<br>";
				$md       = get_metadata( 'post', $qry->post->ID );
				$spaces[] = (int) $md['id'][0];
			} else {
				$spaces[] = $qry->post;
			}
			
		}
		
		wp_reset_query();
		wp_reset_postdata();
		
		return $spaces;
	}
	
	/**
	 * Get all published posts
	 *
	 * @param string $post_type
	 *
	 * @return array
	 *
	 * @since  1.0
	 * @access private
	 * @static
	 *
	 */
	private function getAllPosts( string $post_type = 'availabilities' ) {
		
		$args = array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => - 1,
		);
		
		$qry = new \WP_Query( $args );
		
		if ( !$qry->have_posts() ) {
			
			wp_reset_query();
			
			return __( 'No posts found', 'fpy' );
			
		}
		
		$posts = array();
		
		while ( $qry->have_posts() ) {
			$qry->the_post();
			$post_id            = $qry->post->ID;
			$park_id            = get_post_meta( $post_id, 'parent_id', true );
			$space_id           = get_post_meta( $post_id, 'id', true );
			$posts[ $space_id ] = $park_id;
		}
		ksort( $posts );
		
		return $posts;
		
	}
	
	
	/**
	 * Update step status
	 *
	 * @param int step
	 * @param string status
	 *
	 * @return null
	 *
	 * @since  1.0
	 * @access public
	 */
	public function updateStepStatus( $step, $status = 'completed', $data = null, $day = 'today' ):?bool {
		
		$field = $this->getStepFieldName( $step );
		$value = maybe_unserialize( get_option( $field, array() ) );
		if ( !is_array( $value ) || $step == 6 ) {
			$value = array();
		}
		$date = self::getDate( $day );
		
		switch ( intval( $step ) ) {
			
			case 1:
				
				$value[ $date ]['status']    = $status;
				$value[ $date ]['update_at'] = current_time( 'mysql' );
				update_option( $field, $value, 'no' );
				
				break;
			
			case 2:
				
				$value['date']       = $date;
				$value['status']     = $status;
				$value['updated_at'] = current_time( 'mysql' );
				if ( is_array( $data ) && count( $data ) == 0 ) {
					$value['data'] = array(
						'existing'    => array(),
						'unpublished' => array(),
						'expired'     => array(),
					);
				}
				$value['data'] = $data;
				update_option( $field, $value, 'no' );
				
				break;
			
			case 3:
			case 4:
			case 5:
			case 6:
			case 7:
				$value['date']   = $date;
				$value['status'] = $status;
				if ( !is_null( $data ) ) {
					$value['data'] = $data;
				}
				$value['updated_at'] = current_time( 'mysql' );
				update_option( $field, $value, 'no' );
				break;
			
			default:
				// Do nothing
				break;
		}
		
		return true;
	}
	
	/**
	 *
	 * Step 6
	 *
	 * Check duplicate titles and add numerical addition
	 *
	 * @return null
	 *
	 * @since  1.0
	 * @access public
	 */
	public function processDuplicateTitles( $limit = 5, $day = 'today' ) {
		
		$prev_status = $this->getStepStatus( 5 );
		if ( $prev_status != 'completed' ) {
			return "Step 5 is not completed yet";
		}
		
		$field = $this->getStepFieldName( 6 );
		$value = maybe_unserialize( get_option( $field, array() ) );
		
		$dup_titles = self::getDuplicateTitles();
		
		if ( empty( $dup_titles ) ) {
			
			$this->updateStepStatus( 6, 'completed', array() );
			
			return 'completed';
			
		}
		
		$processed = $value['date'] == self::getDate( $day ) && isset( $value['data'] ) ? $value['data'] : array();
		
		// Count limit
		$count_limit = 0;
		
		foreach ( $dup_titles as $title ) {
			
			// Check if all titles are processed or not
			if ( count( array_diff( $dup_titles, $processed ) ) == 0 ) {
				$this->updateStepStatus( 6, 'completed', $processed );
				
				return 'completed';
			}
			
			// Continue if already processed
			if ( in_array( $title, $processed ) ) {
				continue;
			}
			
			// Skip if reached limit
			if ( $count_limit >= $limit ) {
				break;
			}
			
			// Qry for all posts
			$dup_posts = self::getPostsByTitle( $title, 'availabilities' );
			
			
			if ( empty( $dup_posts ) ) {
				
				$processed[] = $title;
				
				// No post ids found for the post
				$this->updateStepStatus( 6, 'processing', $processed );
				continue;
				
			}
			
			// Count numerical addition
			$count_p = 1;
			foreach ( $dup_posts as $p ) {
				$args = array(
					'ID'         => $p,
					'post_title' => $title . ' ' . $count_p,
				);
				wp_update_post( $args );
				$count_p ++;
			}
			
			$processed[] = $title;
			
			$this->updateStepStatus( 6, 'processing', $processed );
			
			$count_limit ++;
			
		}
		
		$this->updateStepStatus( 6, 'processing', $processed );
		
	}
	
	
	/**
	 * Get posts by post_title
	 *
	 * @param string $title
	 * @param string $post_type
	 *
	 * @return array
	 *
	 * @since  1.0
	 * @access public
	 * @static
	 *
	 */
	public static function getPostsByTitle( string $title = '', string $post_type = 'availabilities' ) {
		
		global $wpdb;
		
		$qry     = "SELECT * FROM {$wpdb->prefix}posts WHERE post_type = '%s' AND post_title = '%s' ";
		$results = $wpdb->get_results( $wpdb->prepare( $qry, $post_type, $title ) );
		
		if ( empty( $results ) ) {
			$wpdb->flush();
			
			return null;
		}
		
		$data = array();
		foreach ( $results as $r ) {
			$data[] = $r->ID;
		}
		
		wp_reset_query();
		wp_reset_postdata();
		
		return $data;
	}
	
	/**
	 *
	 * Get duplicate titles of existing posts
	 *
	 * @param string $post_type
	 *
	 * @return array | null
	 *
	 * @since  1.0
	 * @access public
	 * @static
	 *
	 */
	public static function getDuplicateTitles( string $post_type = 'availabilities' ):?array {
		
		global $wpdb;
		$qry     = "SELECT * FROM {$wpdb->prefix}posts WHERE post_type = '%s' GROUP BY post_title HAVING COUNT(*) > 1";
		$results = $wpdb->get_results( $wpdb->prepare( $qry, $post_type ) );
		
		if ( empty( $results ) ) {
			$wpdb->flush();
			
			return null;
		}
		
		$titles = array();
		foreach ( $results as $r ) {
			$titles[] = $r->post_title;
		}
		
		$titles = array_unique( array_filter( $titles ) );
		
		$wpdb->flush();
		
		return $titles;
	}
	
	/**
	 * Reset everything
	 *
	 * @since  1.1
	 * @access public
	 *
	 */
	public function resetEverything( $check_source = true ) {
		
		if ( !current_user_can( 'manage_options' ) ) {
			
			return __( 'Only admin can do the reset.', 'fpy' );
			
		}
		
		// Deleting existing data
		$count = $this->deletePosts( 'availabilities', $check_source );
		
		// Delete all props
		delete_option( $this->prefix . '_fpy_all_props' );
		
		// Deleting all steps
		for ( $i = 0; $i <= 7; $i ++ ) {
			
			delete_option( $this->getStepFieldName( $i ) );
			
		}
		
		return intval( $count );
		
	}
	
	/**
	 * Step 7
	 * PUT data(URL) to Yardi
	 *
	 * @return void|string
	 *
	 * @since  1.0
	 * @access public
	 */
	public function putDataToYardi() {
		
		if ( $this->getStepStatus( 6 ) != 'completed' ) {
			return __( 'Previous step 6 not completed', 'fpy' );
		}
		
		if ( strpos( strtolower( trim( site_url() ) ), 'psbusinessparks.com' ) === false ) {
			
			$this->updateStepStatus( 7, 'completed' );
			
			return __( 'Only live site will PUT back.', 'fpy' );
			
		}
		
		$step_field = $this->getStepFieldName( 7 );
		$value      = maybe_unserialize( get_option( $step_field, array() ) );
		
		if ( $value['date'] != self::getDate( 'today' ) || $value['data']['availabilities'] ['status'] != 'completed' ) {
			
			return self::processPutDataToYardi( 'availabilities' );
			
		} else if ( $value['date'] != self::getDate( 'today' ) || $value['data']['parks'] ['status'] != 'completed' ) {
			
			return self::processPutDataToYardi( 'parks' );
			
		} else {
			
			$this->updateStepStatus( 7, 'completed', $value );
			
			return $value;
			
		}
		
	}
	
	/**
	 * Process data to put back to Yardi
	 *
	 * @return void|null
	 *
	 * @since  1.0
	 * @access public
	 */
	public function processPutDataToYardi( string $post_type = '' ) {
		
		$step_field = $this->getStepFieldName( 7 );
		
		$value = maybe_unserialize( get_option( $step_field, array() ) );
		
		if ( isset( $value['date'] ) && $value['date'] == self::getDate( 'today' ) && $value['status'] == 'completed' ) {
			
			return __( 'Completed', 'fpy' );
			
		}
		
		if ( !isset( $value['date'] ) || $value['date'] != self::getDate( 'today' ) ) {
			
			$value = array(
				'date' => self::getDate( 'today' ),
				'data' => array(),
			);
			
		}
		
		$last_id = $value['data'][ $post_type ]['last_id'] ?? 0;
		
		global $wpdb;
		
		$limit = 20;
		
		if ( $value['data'][ $post_type ]['status'] == 'completed' ) {
			
			return __( 'Completed', 'fpy' );
			
		}
		
		$qry = "SELECT * FROM {$wpdb->prefix}posts WHERE post_type = '%s' AND post_status = 'publish' AND ID > %d ORDER BY ID ASC LIMIT %d ";
		
		$sql = $wpdb->prepare( $qry, $post_type, $last_id, $limit );
		
		$results = $wpdb->get_results( $sql );
		
		if ( !empty( $results ) ) {
			
			foreach ( $results as $result ) {
				
				$headers = array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $this->getAccessToken(),
				);
				
				$meta_field = $post_type == 'availabilities' ? 'id' : 'park_id';
				$body       = array(
					'Listing'       => array(
						'Id'   => (int) get_post_meta( $result->ID, $meta_field, true ),
						'Type' => $post_type == 'availabilities' ? 0 : 1,
					),
					'AdditionalUrl' => get_permalink( $result->ID ),
				);
				
				$response = wp_remote_request(
					self::ADD_WEBSITE_LINK,
					array(
						'method'      => 'PUT',
						'headers'     => $headers,
						'body'        => json_encode( $body ),
						'data_format' => 'body',
						'ssl_verify'  => false,
					)
				);
				
				if ( $response['response']['code'] == 200 ) {
					
					$value['data'][ $post_type ]['last_id'] = $result->ID;
					update_option( $step_field, $value, 'no' );
					
				}
				
			}
			
			return $value;
			
		} else {
			
			$value['data'][ $post_type ]['status'] = 'completed';
			update_option( $step_field, $value, 'no' );
			
		}
		
	}
	
}

Psbp::instance();
