<?php
namespace FreyYardi;

defined('ABSPATH') || die();

class Admin {

	/**
	 * Instance
	 */
	private static $_instance;

	/**
	 * Initiate Instance
	 */
	public static  function instance () {

		if ( is_null( self::$_instance ) ) {

			self::$_instance = new self();

		}

		return self::$_instance;

	}

	/**
	 * Constructor
	 *
	 * @return void
	 */
	public function __construct() {

		// Register backend page
		add_action( 'admin_menu', [$this, 'registerMenu'] );

		// Register scripts
		add_action( 'admin_enqueue_scripts', [$this, 'registerScripts'] );

		// Footer Content
		add_action( 'admin_footer', [$this, 'footerContent'] );

        // Process AJAX requests
        add_action( 'wp_ajax_fyResetData', [$this, 'processAjaxRequests'] );

        // Process Admin Ajax data
        add_action( 'wp_ajax_frey_admin_ajax', [$this, 'processAjaxData'] );

	}


	/**
	 * Register menu
	 *
	 * @return void
	 */
	public function registerMenu( ) {

		add_menu_page(
			__('Yardi Settings', FreyYardi::TEXT_DOMAIN),
			__('Yardi Settings', FreyYardi::TEXT_DOMAIN),
			'manage_options',
			'yardi-settings',
			[$this, 'showYardiDashboard'] );

	}

	/**
	 * Register JS & CSS scripts
	 */
	public function registerScripts() {

		wp_enqueue_script( 'fy-admin', FY_PLUGIN_URL . 'assets/js/fy-admin.js', array('jquery'), rand(1,1000000), false );

	}

	/**
	 * Save admin data
     *
     * @return void
     *
     * @since 1.0
     * @access public
	 */
    public function processAjaxData(){

        $action = $_REQUEST['ajax_action'];

        if ( !wp_verify_nonce($_REQUEST['_wpnonce'], FreyYardi::TEXT_DOMAIN) ){
            die(json_encode(
                array(
                    'status'    => 0,
                    'data'      => 'Security check fails'
                )
            ));
        }

        if ( ! method_exists($this, $action) ) {
	        die( json_enccode( array(
		        'status'    => 0,
		        'data'      => 'Method not found',
	        )) );
        }

        die(json_encode($this->$action()));

    }

	/**
	 * Save settings data
     * @return array
     *
     * @since 1.0
     * @access public
	 */
    public function saveSettingsData(){

        $data = $_REQUEST;
        unset($data['action']);
        unset($data['ajax_action']);
        unset($data['_wpnonce']);
        unset($data['_wp_http_referer']);
        update_option( '_fy_settings_data', $data, 'no' );

        return array(
            'status'    => '1',
            'data'      => 'Updated Successfully',
        );

    }



	/**
	 * Show Yardi dashboard default page
	 *
	 * @return void
	 */
	public function showYardiDashboard() {

		$_wpnonce = wp_create_nonce(FreyYardi::TEXT_DOMAIN);

		$args = array(
			'post_type'         => 'availabilities',
			'posts_per_page'    => -1,
			'post_status'       => 'publish',
            'meta_query'        => array(
	            array(
		            'key'       => '_source',
		            'value'     => '_psbp',
		            'compare'   => '='
	            )
            )
		);


		$qry = new \WP_Query($args);
        $count_psbp = $qry->found_posts;

		$args = array(
			'post_type'         => 'availabilities',
			'posts_per_page'    => -1,
			'post_status'       => 'publish',
			'meta_query'        => array(
				array(
					'key'       => '_source',
					'value'     => '_link',
					'compare'   => '='
				)
			)
		);

		$qry = new \WP_Query($args);
		$count_link = $qry->found_posts;


        $settings_data = maybe_unserialize(get_option('_fy_settings_data'));
        $ignored_ids = $settings_data['ignored_ids'];
		$ignored_business_park_ids = $settings_data['ignored_business_park_ids'];


		ob_start();
		?>
		<br><br><br>
		<a href="#" data-wpnonce="<?php echo $_wpnonce; ?>" data-target="psbp" class="button resetData">Reset PSBP Data (<?php echo $count_psbp; ?> posts)</a>
		&nbsp;&nbsp;
		<a href="#" data-wpnonce="<?php echo $_wpnonce; ?>" data-target="link" class="button resetData">Reset Link Data (<?php echo $count_link; ?> posts) </a>
		&nbsp;&nbsp;
		<a href="#" data-wpnonce="<?php echo $_wpnonce; ?>" data-target="all" class="button resetData">Reset All Data (<?php echo $count_link + $count_psbp; ?> posts) </a>

        <!-- Show results -->
        <div class="adminShowResults"></div>

        <style type="text/css">
            .adminShowResults{
                display: none;
                width: 95%;
                padding: 15px;
                box-sizing: border-box;
                border: 5px solid #aaa;
                margin: 15px 0;
                background: #fff;
                box-shadow: 0 0 6px rgba(0,0,0,0.15);
                border-radius: 10px;
            }
        </style>

        <h2>Sync Status</h2>
        <table class="wp-list-table widefat fixed striped table-view-list">
            <tr>
                <th width="20">#</th>
                <th>Step</th>
                <th>PSBP</th>
                <th>Link</th>
            </tr>
            <?php
                $psbp = new Psbp();
                $link = new Link();

                $steps = array(
                    1 => 'Fetch properties from source',
                    'Compare properties with existing data',
                    'Insert new properties',
                    'Delete expired properties',
                    'Update existing properties',
                    'Modify duplicate titles',
                    'Put URL back to Yardi'
                );
                for( $i= 1; $i <= 7; $i++ ){
                    $psbp_status = $psbp->getStepStatus($i) == 'processing' ? 'Pending' : 'Completed';
	                $link_status = $link->getStepStatus($i) == 'processing' ? 'Pending' : 'Completed';
                    ?>
                    <tr>
                        <td><?php echo $i; ?></td>
                        <td><?php echo $steps[$i]; ?></td>
                        <td><?php echo $psbp_status; ?></td>
                        <td><?php echo $link_status; ?></td>
                    </tr>
                    <?php
                }
            ?>
        </table>

        <h2>Admin Settings</h2>
        <form action="#" class="freyAdminSettings">
            <fieldset>
                <label for="ignored_ids">Ignored IDs (seperated by comma ,)</label>
                <textarea name="ignored_ids" id="ignored_ids" cols="30" rows="10"><?php echo $ignored_ids; ?></textarea>
            </fieldset>
            <fieldset>
                <label for="ignored_business_park_ids">Ignored Business Park IDs (seperated by comma ,)</label>
                <textarea name="ignored_business_park_ids" id="ignored_business_park_ids" cols="30" rows="10"><?php echo $ignored_business_park_ids; ?></textarea>
            </fieldset>
            <fieldset>
                <input type="hidden" name="action" value="frey_admin_ajax">
                <input type="hidden" name="ajax_action" value="saveSettingsData">
                <?php wp_nonce_field(FreyYardi::TEXT_DOMAIN); ?>
                <input type="submit" value="Save" class="button">
            </fieldset>
        </form>

        <style type="text/css">
            .freyAdminSettings{
                width: 100%;
                height: auto;
                display: inline-block;
                padding-right: 20px;
                box-sizing: border-box;
            }
            .freyAdminSettings textarea{
                width: 100%;
                height: 100px;
            }
        </style>

		<?php
		echo ob_get_clean();

	}

	/**
	 * Show footer content on admin pages
	 * @return void
	 *
	 * @since 0.1
	 * @access public
	 */
	public function footerContent(){
		ob_start();
		?>
		<script>
			var _fyAdminUrl = '<?php echo admin_url('admin-ajax.php'); ?>';
		</script>
        <style>
            .adminLoading{
                width: 100%;
                height: 100%;
                display: inline-block;
                position: fixed;
                background: rgba(0,0,0,0.15);
                z-index: 9999;
                left: 0;
                top: 0;
            }
            .adminLoading span {
                font-size: 30px;
                color: rgba(255, 255, 255, 0.75);
                position: absolute;
                top: 50%;
                left: 50%;
                padding: 10px;
                display: inline-block;
            }
        </style>

		<?php
		echo ob_get_clean();

	}

    /**
     * Process ajax request
     *
     * @return string
     *
     * @since 0.1
     * @access public
     */
    public function processAjaxRequests(){

        if ( !wp_verify_nonce($_REQUEST['_wpnonce'], FreyYardi::TEXT_DOMAIN) ) {

            die ( json_encode( array(
                'status'    => 0,
                'data'  => 'Security check fails.',
            ) ) );

        }

        $target = esc_sql(strtolower(trim($_REQUEST['target'])));

        $result = '';

        switch($target) {

            case 'psbp':

                $psbp = new Psbp();
                $count = $psbp->resetEverything();
                $result = "All <strong>PSBP</strong> data ({$count} posts) deleted and reset everything.";

                break;

            case 'link':

                $link = new Link();
                $count = $link->resetEverything();
	            $result = "All <strong>Link</strong> data ({$count} posts) deleted and reset everything.";

                break;
            case 'all':

                $psbp = new Psbp();
                $pcount = $psbp->resetEverything( false );

                $link = new Link();
                $lcount = $link->resetEverything( false );

                $result = "All <strong>PSBP</strong> data ({$pcount} posts) and <strong>LINK</strong> data ({$lcount} posts) are deleted and reset everything.";

                break;

        }

        die(json_encode(array(
            'status'    => 1,
            'data'  => $result,
        )));

    }


}

Admin::instance();