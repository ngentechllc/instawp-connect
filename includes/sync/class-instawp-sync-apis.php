<?php
/**
 *
 * @link       https://instawp.com/
 * @since      1.0
 *
 * @package    instawp
 * @subpackage instawp/includes
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'InstaWP_Rest_Api' ) ) {
	require_once INSTAWP_PLUGIN_DIR . '/includes/class-instawp-rest-api.php';
}

class InstaWP_Sync_Apis extends InstaWP_Rest_Api {

	private $wpdb;

	private $tables;

	private $logs = array();

	public function __construct() {
		parent::__construct();

		global $wpdb;

		$this->wpdb   = $wpdb;
		$this->tables = InstaWP_Sync_DB::$tables;

		add_action( 'rest_api_init', array( $this, 'add_api_routes' ) );
	}

	public function add_api_routes() {
		register_rest_route( $this->namespace . '/' . $this->version, '/mark-staging', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'mark_staging' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( $this->namespace . '/' . $this->version, '/sync', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'events_receiver' ),
			'permission_callback' => '__return_true',
		) );
	}

	/**
	 * Handle events receiver api
	 *
	 * @param WP_REST_Request $req
	 *
	 * @return WP_Error|WP_HTTP_Response|WP_REST_Response
	 */
	public function mark_staging( WP_REST_Request $req ) {
		$response = $this->validate_api_request( $req );
		if ( is_wp_error( $response ) ) {
			return $this->throw_error( $response );
		}

		$body    = $req->get_body();
		$request = json_decode( $body );

		if ( ! isset( $request->parent_connect_id ) ) {
			return new WP_Error( 400, esc_html__( 'Invalid connect ID', 'instawp-connect' ) );
		}

		delete_option( 'instawp_sync_parent_connect_data' );
		update_option( 'instawp_sync_connect_id', intval( $request->parent_connect_id ) );
		update_option( 'instawp_is_staging', true );
		instawp_get_source_site_detail();

		return $this->send_response( array(
			'status'  => true,
			'message' => __( 'Site has been marked as staging', 'instawp-connect' ),
		) );
	}

	/**
	 * Handle events receiver api
	 *
	 * @param WP_REST_Request $req
	 *
	 * @return WP_Error|WP_HTTP_Response|WP_REST_Response
	 * @throws Exception
	 */
	public function events_receiver( WP_REST_Request $req ) {

		$response = $this->validate_api_request( $req );
		if ( is_wp_error( $response ) ) {
			return $this->throw_error( $response );
		}

		$body    = $req->get_body();
		$bodyArr = json_decode( $body );

		if ( ! isset( $bodyArr->encrypted_contents ) ) {
			return new WP_Error( 400, esc_html__( 'Invalid data', 'instawp-connect' ) );
		}

		instawp_create_db_tables();

		$encrypted_contents = json_decode( $bodyArr->encrypted_contents );
		$sync_id            = $bodyArr->sync_id;
		$source_connect_id  = $bodyArr->source_connect_id;
		$source_url         = $bodyArr->source_url;
		$is_enabled         = false;
		$changes            = array();

		if ( get_option( 'instawp_is_event_syncing' ) ) {
			$is_enabled = true;
		}

		delete_option( 'instawp_is_event_syncing' );

		if ( ! empty( $encrypted_contents ) && is_array( $encrypted_contents ) ) {
			$sync_response   = array();
			$count           = 1;
			$total_op        = count( $encrypted_contents );
			$progress        = intval( $count / $total_op * 100 );
			$sync_message    = $bodyArr->sync_message ?? '';
			$progress_status = ( $progress > 100 ) ? 'in_progress' : 'completed';

			foreach ( $encrypted_contents as $v ) {
				$isResult = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT ID FROM " . INSTAWP_DB_TABLE_EVENT_SYNC_LOGS . " WHERE event_hash = %s ", $v->event_hash ) );

				if ( $isResult ) {
					$response_data   = InstaWP_Sync_Helpers::sync_response( $v );
					$sync_response[] = $response_data['data'];
				} else {
					if ( empty( $v->event_slug ) || empty( $v->details ) ) {
						continue;
					}

					$source_id    = ( ! empty( $v->source_id ) ) ? sanitize_text_field( $v->source_id ) : null;
					$v->source_id = $source_id;

					$response_data = apply_filters( 'INSTAWP_CONNECT/Filters/process_two_way_sync', array(), $v );
					if ( ! empty( $response_data['data'] ) ) {
						$sync_response[] = $response_data['data'];
					}

					if ( isset( $v->details->site_url ) && class_exists( '\Elementor\Utils' ) && method_exists('\Elementor\Utils', 'replace_urls' ) ) {
						try {
							\Elementor\Utils::replace_urls( $v->details->site_url, site_url() );
						} catch ( \Exception $e ) {}
					}

					if ( ! empty( $response_data['log_data'] ) ) {
						$this->logs = array_merge( $this->logs, $response_data['log_data'] );
					}

					/*
					* widget
					*/
					if ( isset( $v->event_type ) && $v->event_type == 'widget' ) {
						$widget_block = (array) $v->details->widget_block;
						$appp         = (array) $v->details;
						$dataIns      = array(
							'data' => json_encode( $appp ),
						);
						InstaWP_Sync_DB::insert( 'wp_testing', $dataIns );

						$widget_block_arr = array();
						foreach ( $widget_block as $widget_key => $widget_val ) {
							if ( $widget_key == '_multiwidget' ) {
								$widget_block_arr[ $widget_key ] = $widget_val;
							} else {
								$widget_val_arr                  = (array) $widget_val;
								$widget_block_arr[ $widget_key ] = array( 'content' => $widget_val_arr['content'] );
							}
						}
						update_option( 'widget_block', $widget_block_arr );
						#message
						$message         = 'Sync successfully.';
						$status          = 'completed';
						$sync_response[] = $this->sync_opration_response( $status, $message, $v );
						#changes

					}

					//record logs
					$this->event_sync_logs( $v, $source_url );
				}

				/*
				* Update api for cloud
				*/
				#Sync update
				$syncUpdate = array(
					'progress' => $progress,
					'status'   => $progress_status,
					'message'  => $sync_message,
					'changes'  => array(
						'changes'       => $changes,
						'sync_response' => $sync_response,
						'logs'          => $this->logs,
					),
				);
				$this->sync_update( $sync_id, $syncUpdate );
				++$count ;
			}
		}

		#Sync history save
		$this->sync_history_save( $body, $changes, 'Complete' );

		#enable is back if syncing already enabled at the destination
		if ( $is_enabled ) {
			update_option( 'instawp_is_event_syncing', 1 );
		}

		return $this->send_response( array(
			'sync_id'            => $sync_id,
			'encrypted_contents' => $encrypted_contents,
			'source_connect_id'  => $source_connect_id,
			'changes'            => array(
				'changes'       => $changes,
				'sync_response' => $sync_response,
			),
		) );
	}

	public function event_sync_logs( $data, $source_url ) {
		$data = array(
			'event_id'   => $data->id,
			'event_hash' => $data->event_hash,
			'source_url' => $source_url,
			'data'       => wp_json_encode( $data->details ),
			'logs'       => $this->logs[ $data->id ] ?? '',
			'date'       => date( 'Y-m-d H:i:s' ),
		);
		InstaWP_Sync_DB::insert( INSTAWP_DB_TABLE_EVENT_SYNC_LOGS, $data );
	}

	#Insert history
	public function sync_history_save( $body = null, $changes = null, $status = null ) {
		$dir     = 'dev-to-live';
		$date    = date( 'Y-m-d H:i:s' );
		$bodyArr = json_decode( $body );
		$message = $bodyArr->sync_message ?? '';
		$data    = array(
			'encrypted_contents' => $bodyArr->encrypted_contents,
			'changes'            => wp_json_encode( $changes ),
			'sync_response'      => '',
			'direction'          => $dir,
			'status'             => $status,
			'user_id'            => isset( $bodyArr->upload_wp_user ) ? $bodyArr->upload_wp_user : '',
			'changes_sync_id'    => isset( $bodyArr->sync_id ) ? $bodyArr->sync_id : '',
			'sync_message'       => $message,
			'source_connect_id'  => '',
			'source_url'         => isset( $bodyArr->source_url ) ? $bodyArr->source_url : '',
			'date'               => $date,
		);

		InstaWP_Sync_DB::insert( $this->tables['sh_table'], $data );
	}



	/** sync operation response
	 *
	 * @param $status
	 * @param $message
	 * @param $v
	 *
	 * @return array
	 */
	public function sync_opration_response( $status, $message, $v ): array {
		return array(
			'id'      => $v->id,
			'status'  => $status,
			'message' => $message,
		);
	}

	/** sync update
	 *
	 * @param $sync_id
	 * @param $data
	 * @param $source_connect_id
	 *
	 * @return array
	 */
	public function sync_update( $sync_id, $data ): array {
		$connect_id = instawp_get_connect_id();

		// connects/<connect_id>/syncs/<sync_id>
		return InstaWP_Curl::do_curl( "connects/{$connect_id}/syncs/{$sync_id}", $data, array(), 'patch' );
	}
}

new InstaWP_Sync_Apis();