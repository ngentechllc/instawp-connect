<?php

defined( 'ABSPATH' ) || exit;

class InstaWP_Sync_Post {

	public array $restricted_cpts = array(
		'shop_order',
	);

    public function __construct() {
	    $this->restricted_cpts = (array) apply_filters( 'INSTAWP_CONNECT/Filters/two_way_sync_restricted_post_types', $this->restricted_cpts );

		// Post Actions.
	    add_action( 'wp_after_insert_post', array( $this, 'handle_post' ), 999, 4 );
	    add_action( 'before_delete_post', array( $this, 'delete_post' ), 10, 2 );
	    add_action( 'transition_post_status', array( $this, 'transition_post_status' ), 10, 3 );

	    // Media Actions.
	    add_action( 'add_attachment', array( $this, 'add_attachment' ) );
	    add_action( 'attachment_updated', array( $this, 'attachment_updated' ), 10, 3 );

	    // Process event
	    add_filter( 'INSTAWP_CONNECT/Filters/process_two_way_sync', array( $this, 'parse_event' ), 10, 2 );
    }

	/**
	 * Function for `wp_insert_post` action-hook.
	 *
	 * @param int   $post     Post ID.
	 * @param bool  $update   Whether this is an existing post being updated.
	 *
	 * @return void
	 */
	public function handle_post( $post_id, $post, $update, $post_before ) {
		$post = get_post( $post_id );

		if ( ! InstaWP_Sync_Helpers::can_sync( 'post' ) || in_array( $post->post_type, $this->restricted_cpts ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Check auto save or revision.
		if ( wp_is_post_autosave( $post->ID ) || wp_is_post_revision( $post->ID ) ) {
			return;
		}

		// Check post status auto draft.
		if ( in_array( $post->post_status, array( 'auto-draft', 'trash' ) ) ) {
			return;
		}

		// acf feild group check
		if ( $post->post_type == 'acf-field-group' && $post->post_content == '' ) {
			InstaWP_Sync_Helpers::set_post_reference_id( $post->ID );
			return;
		}

		// acf check for acf post type
		if ( in_array( $post->post_type, array( 'acf-post-type', 'acf-taxonomy' ) ) && $post->post_title == 'Auto Draft' ) {
			return;
		}

		$singular_name = InstaWP_Sync_Helpers::get_post_type_name( $post->post_type );
		if ( $update ) {
			$this->handle_post_events( sprintf( __('%s modified', 'instawp-connect'), $singular_name ), 'post_change', $post );
		}
	}

	/**
	 * Function for `after_delete_post` action-hook.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post   Post object.
	 *
	 * @return void
	 */
	public function delete_post( $post_id, $post ) {
		if ( ! InstaWP_Sync_Helpers::can_sync( 'post' ) || in_array( $post->post_type, $this->restricted_cpts ) ) {
			return;
		}

		if ( get_post_type( $post_id ) !== 'revision' ) {
			$event_name = sprintf( __('%s deleted', 'instawp-connect' ), InstaWP_Sync_Helpers::get_post_type_name( $post->post_type ) );
			$this->handle_post_events( $event_name, 'post_delete', $post );
		}
	}

	/**
	 * Fire a callback only when my-custom-post-type posts are transitioned to 'publish'.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       Post object.
	 */
	public function transition_post_status( $new_status, $old_status, $post ) {
		if ( ! InstaWP_Sync_Helpers::can_sync( 'post' ) || in_array( $post->post_type, $this->restricted_cpts ) ) {
			return;
		}

		if ( $new_status === 'trash' && $new_status !== $old_status && $post->post_type !== 'customize_changeset' ) {
			$event_name = sprintf( __( '%s trashed', 'instawp-connect' ), InstaWP_Sync_Helpers::get_post_type_name( $post->post_type ) );
			$this->handle_post_events( $event_name, 'post_trash', $post );
		}

		if ( $new_status === 'draft' && $old_status === 'trash' ) {
			$event_name = sprintf( __( '%s restored', 'instawp-connect' ), InstaWP_Sync_Helpers::get_post_type_name( $post->post_type ) );
			$this->handle_post_events( $event_name, 'untrashed_post', $post );
		}

		if ( $old_status === 'auto-draft' && $new_status !== $old_status ) {
			$event_name = sprintf( __( '%s created', 'instawp-connect' ), InstaWP_Sync_Helpers::get_post_type_name( $post->post_type ) );
			$this->handle_post_events( $event_name, 'post_new', $post );
		}
	}

	/**
	 * Function for `add_attachment` action-hook
	 *
	 * @param $post_id
	 * @return void
	 */
	public function add_attachment( $post_id ) {
		if ( ! InstaWP_Sync_Helpers::can_sync( 'post' ) ) {
			return;
		}

		$event_name = esc_html__( 'Media created', 'instawp-connect' );
		$this->handle_post_events( $event_name, 'post_new', $post_id );
	}

	/**
	 * Function for `attachment_updated` action-hook
	 *
	 * @param $post_id
	 * @param $post_after
	 * @param $post_before
	 * @return void
	 */
	public function attachment_updated( $post_id, $post_after, $post_before ) {
		if ( ! InstaWP_Sync_Helpers::can_sync( 'post' ) ) {
			return;
		}

		$event_name = esc_html__('Media updated', 'instawp-connect' );
		$this->handle_post_events( $event_name, 'post_change', $post_after );
	}

	public function parse_event( $response, $v ) {
		$reference_id = $v->source_id;

		// create and update
		if ( in_array( $v->event_slug, array( 'post_change', 'post_new' ), true ) ) {
			$posts          = isset( $v->details->posts ) ? ( array ) $v->details->posts : '';
			$postmeta       = isset( $v->details->postmeta ) ? ( array ) $v->details->postmeta : '';
			$featured_image = isset( $v->details->featured_image ) ? ( array ) $v->details->featured_image : '';
			$media          = isset( $v->details->media ) ? ( array ) $v->details->media : '';
			$parent_post    = isset( $v->details->parent->post ) ? ( array ) $v->details->parent->post : array();

			#check for the post parent
			if ( ! empty( $parent_post ) ) {
				$parent_post_meta    = isset( $v->details->parent->post_meta ) ? ( array ) $v->details->parent->post_meta : array();
				$parent_reference_id = $v->details->parent->reference_id ?? '';
				$destination_post    = $this->get_post_by_reference( $parent_post['post_type'], $parent_reference_id, $parent_post['post_name'] );

				if ( ! empty( $destination_post ) ) {
					$posts['post_parent'] = $destination_post->ID;
				} elseif ( in_array( $posts['post_type'], array( 'acf-field' ) ) ) {
					$posts['post_parent'] = $this->create_or_update_post( $parent_post, $parent_post_meta, $parent_reference_id );
				}
			}

			if ( $posts['post_type'] === 'attachment' ) {
				$posts['ID'] = $this->handle_attachments( $posts, $postmeta, $posts['guid'] );
				$this->process_post_meta( $postmeta, $posts['ID'] );
			} else {
				$posts['ID'] = $this->create_or_update_post( $posts, $postmeta, $reference_id );
			}

			if ( ! empty( $featured_image['media'] ) ) {
				$att_id = $this->handle_attachments( ( array ) $featured_image['media'], ( array ) $featured_image['media_meta'], $featured_image['featured_image_url'] );
				if ( ! empty( $att_id ) ) {
					set_post_thumbnail( $posts['ID'], $att_id );
				}
			}

			do_action( 'INSTAWP_CONNECT/Actions/process_two_way_sync_post', $posts, $v );

			$this->reset_post_terms( $posts['ID'] );

			$taxonomies = ( array ) $v->details->taxonomies;
			foreach ( $taxonomies as $taxonomy => $terms ) {
				$terms    = ( array ) $terms;
				$term_ids = array();
				foreach ( $terms as $term ) {
					$term = ( array ) $term;
					if ( ! term_exists( $term['slug'], $taxonomy ) ) {
						$inserted_term = wp_insert_term( $term['name'], $taxonomy, array(
							'description' => $term['description'],
							'slug'        => $term['slug'],
							'parent'      => 0,
						) );
						if ( ! is_wp_error( $inserted_term ) ) {
							$term_ids[] = $inserted_term['term_id'];
						}
					} else {
						$get_term_by = ( array ) get_term_by( 'slug', $term['slug'], $taxonomy );
						$term_ids[]  = $get_term_by['term_id'];
					}
				}
				wp_set_post_terms( $posts['ID'], $term_ids, $taxonomy );
			}

			$this->upload_content_media( $media, $posts['ID'] );

			return InstaWP_Sync_Helpers::sync_response( $v );
		}

		// trash, untrash and delete
		if ( in_array( $v->event_slug, array( 'post_trash', 'post_delete', 'untrashed_post' ), true ) ) {
			$posts        = ( array ) $v->details->posts;
			$postmeta     = ( array ) $v->details->postmeta;
			$post_name    = $posts['post_name'];
			$function     = 'wp_delete_post';
			$data         = array();
			$logs         = array();

			if ( $v->event_slug !== 'post_delete' ) {
				$post_name = ( $v->event_slug === 'untrashed_post' ) ? $posts['post_name'] . '__trashed' : str_replace( '__trashed', '', $posts['post_name'] );
				$function  = ( $v->event_slug === 'untrashed_post' ) ? 'wp_untrash_post' : 'wp_trash_post';
			}
			$post_by_reference_id = $this->get_post_by_reference( $posts['post_type'], $reference_id, $post_name );

			if ( ! empty( $post_by_reference_id ) ) {
				$post_id = $post_by_reference_id->ID;
				$post    = call_user_func( $function, $post_id );
				$status  = isset( $post->ID ) ? 'completed' : 'pending';
				$message = isset( $post->ID ) ? 'Sync successfully.' : 'Something went wrong.';

				$data = compact( 'status', 'message' );
			} else {
				$logs[ $v->id ] = sprintf( '%s not found at destination', ucfirst( str_replace( array( '-', '_' ), '', $posts['post_type'] ) ) );
			}

			return InstaWP_Sync_Helpers::sync_response( $v, $logs, $data );
		}

		return $response;
	}

	/**
	 * Function for `handle_post_events`
	 *
	 * @param $event_name
	 * @param $event_slug
	 * @param $post
	 * @return void
	 */
	private function handle_post_events( $event_name = null, $event_slug = null, $post = null ) {
		$post               = get_post( $post );
		$post_parent_id     = $post->post_parent;
		$post_content       = $post->post_content ?? '';
		$featured_image_id  = get_post_thumbnail_id( $post->ID );
		$event_type         = get_post_type( $post );
		$title              = $post->post_title ?? '';
		$taxonomies         = $this->get_taxonomies_items( $post->ID );
		$media              = InstaWP_Sync_Helpers::get_media_from_content( $post_content );
		$elementor_css      = $this->get_elementor_css( $post->ID );
		$reference_id       = InstaWP_Sync_Helpers::set_post_reference_id( $post->ID );

		$data = array(
			'content'       => $post_content,
			'posts'         => $post,
			'postmeta'      => get_post_meta( $post->ID ),
			'taxonomies'    => $taxonomies,
			'media'         => $media,
			'elementor_css' => $elementor_css,
		);

		if ( $featured_image_id ) {
			$data['featured_image'] = array(
				'featured_image_id'  => $featured_image_id,
				'featured_image_url' => wp_get_attachment_image_url( $featured_image_id, 'full' ),
				'media'              => get_post( $featured_image_id ),
				'media_meta'         => get_post_meta( $featured_image_id ),
				'reference_id'       => InstaWP_Sync_Helpers::set_post_reference_id( $featured_image_id ),
			);
		}

		if ( $post_parent_id > 0 ) {
			$post_parent = get_post( $post_parent_id );

			if ( $post_parent->post_status !== 'auto-draft' ) {
				$data = array_merge( $data, array(
					'parent' => array(
						'post'         => $post_parent,
						'post_meta'    => get_post_meta( $post_parent_id ),
						'reference_id' => InstaWP_Sync_Helpers::set_post_reference_id( $post_parent_id ),
					),
				) );
			}
		}

		$data = apply_filters( 'INSTAWP_CONNECT/Filters/two_way_sync_post_data', $data, $event_type, $post );

		$event_id = InstaWP_Sync_DB::existing_update_events(INSTAWP_DB_TABLE_EVENTS, $event_slug, $reference_id );
		InstaWP_Sync_DB::insert_update_event( $event_name, $event_slug, $event_type, $reference_id, $title, $data, $event_id );
	}

	/**
	 * Get taxonomies items
	 */
	private function get_taxonomies_items( $post_id ): array {
		$taxonomies = get_post_taxonomies( $post_id );
		$items      = array();

		if ( ! empty ( $taxonomies ) && is_array( $taxonomies ) ) {
			foreach ( $taxonomies as $taxonomy ) {
				$taxonomy_items = get_the_terms( $post_id, $taxonomy );

				if ( ! empty( $taxonomy_items ) && is_array( $taxonomy_items ) ) {
					foreach ( $taxonomy_items as $k => $item ) {
						$items[ $item->taxonomy ][ $k ] = ( array ) $item;

						if ( $item->parent > 0 ) {
							$items[ $item->taxonomy ][ $k ]['cat_parent'] = ( array ) get_term( $item->parent, $taxonomy );
						}
					}
				}
			}
		}

		return $items;
	}

	/**
	 * get post type singular name
	 *
	 * @param $post_name
	 * @param $post_type
	 */
	public function get_post_by_name( $post_name, $post_type ) {
		global $wpdb;

		$post = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_name = %s AND post_type= %s ", $post_name, $post_type ) );
		if ( $post ) {
			return get_post( $post );
		}

		return null;
	}

	protected function get_post_by_reference( $post_type, $reference_id, $post_name ) {
		$post = get_posts( array(
			'post_type'   => $post_type,
			'meta_key'    => 'instawp_event_sync_reference_id',
			'meta_value'  => $reference_id,
			'post_status' => 'any',
			'nopaging'    => true,
		) );

		return ! empty( $post ) ? reset( $post ) : $this->get_post_by_name( $post_name, $post_type );
	}

	protected function create_or_update_post( $post, $post_meta, $reference_id ) {
		$destination_post = $this->get_post_by_reference( $post['post_type'], $reference_id, $post['post_name'] );

		if ( ! empty( $destination_post ) ) {
			unset( $post['post_author'] );
			$post_id = wp_update_post( $this->parse_post_data( $post, $destination_post->ID ) );
		} else {
			$default_post_user = InstaWP_Setting::get_option( 'instawp_default_user' );
			if ( ! empty( $default_post_user ) ) {
				$post['post_author'] = $default_post_user;
			}
			$post_id = wp_insert_post( $this->parse_post_data( $post ) );
		}

		if ( $post_id && ! is_wp_error( $post_id ) ) {
			$this->process_post_meta( $post_meta, $post_id );
			return $post_id;
		}

		return 0;
	}

	private function parse_post_data( $post, $post_id = null ) {
		unset( $post['ID'] );
		unset( $post['guid'] );

		if ( $post_id ) {
			$post['ID'] = $post_id;
		}

		return $post;
	}

	public function reset_post_terms( $post_id ) {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}term_relationships WHERE object_id = %d",
				$post_id,
			)
		);
	}

	/**
	 * process_post_meta
	 *
	 * @param $meta_data
	 * @param $post_id
	 *
	 * @return void
	 */
	public function process_post_meta( $meta_data, $post_id ) {
		if ( empty( $meta_data ) || ! is_array( $meta_data ) ) {
			return;
		}

		foreach ( $meta_data as $key => $value ) {
			update_post_meta( $post_id, $key, maybe_unserialize( reset( $value ) ) );
		}

		//if _elementor_css this key not existing then it's giving a error.
		if ( array_key_exists( '_elementor_version', $meta_data ) && ! array_key_exists( '_elementor_css', $meta_data ) ) {
			/*$elementor_css = [
				'time' => time(),
				'fonts' => [],
				'icons' => [],
				'dynamic_elements_ids' => [],
				'status' => 'empty',
				'css' => ''
			];
			*/
			$elementor_css = array();
			add_post_meta( $post_id, '_elementor_css', $elementor_css );
		}
		delete_post_meta( $post_id, '_edit_lock' );
	}

	/** handle_attachments
	 *
	 * @param $attachment_post
	 * @param $attachment_post_meta
	 * @param $file
	 *
	 * @return string|void
	 */
	# import attechments form source to destination.
	public function handle_attachments( $attachment_post, $attachment_post_meta, $file ) {
		$reference_id    = $attachment_post_meta['instawp_event_sync_reference_id'][0] ?? '';
		$attachment_id   = $attachment_post['ID'];
		$attachment_post = $this->get_post_by_reference( $attachment_post['post_type'], $reference_id, $attachment_post['post_name'] );

		if ( ! $attachment_post ) {
			$filename          = basename( $file );
			$arrContextOptions = array(
				"ssl" => array(
					"verify_peer"      => false,
					"verify_peer_name" => false,
				),
			);

			$parent_post_id = 0;
			$upload_file    = wp_upload_bits( $filename, null, file_get_contents( $file, false, stream_context_create( $arrContextOptions ) ) );
			if ( ! $upload_file['error'] ) {
				$wp_filetype = wp_check_filetype( $filename, null );

				$attachment = array(
					'post_mime_type' => $wp_filetype['type'],
					'post_parent'    => $parent_post_id,
					'post_title'     => preg_replace( '/\.[^.]+$/', '', $filename ),
					'post_content'   => '',
					'post_status'    => 'inherit',
				);

				$default_post_user = InstaWP_Setting::get_option( 'instawp_default_user' );
				if ( ! empty( $default_post_user ) ) {
					$attachment['post_author'] = $default_post_user;
				}

				require_once( ABSPATH . "wp-admin" . '/includes/image.php' );
				require_once( ABSPATH . "wp-admin" . '/includes/file.php' );
				require_once( ABSPATH . "wp-admin" . '/includes/media.php' );
				$attachment_id = wp_insert_attachment( $attachment, $upload_file['file'], $parent_post_id );

				if ( ! is_wp_error( $attachment_id ) ) {
					$attachment_data = wp_generate_attachment_metadata( $attachment_id, $upload_file['file'] );
					wp_update_attachment_metadata( $attachment_id, $attachment_data );
					update_post_meta( $attachment_id, 'instawp_event_sync_reference_id', $reference_id );
				}
			}
		} else {
			$attachment_id = $attachment_post->ID;
		}

		return $attachment_id;
	}

	/**
	 * upload_content_media
	 *
	 * @param $media
	 * @param $post_id
	 *
	 * @return void
	 */
	public function upload_content_media( $media = null, $post_id = null ) {
		$media   = json_decode( reset( $media ) );
		$post    = get_post( $post_id );
		$content = $post->post_content;
		$new     = $old = array();
		if ( ! empty( $media ) ) {
			foreach ( $media as $v ) {
				$v = (array) $v;

				if ( isset( $v['attachment_id'] ) && isset( $v['attachment_url'] ) ) {
					$attachment_media = (array) $v['attachment_media'];
					$attachment_id    = $this->handle_attachments( $attachment_media, (array) $v['attachment_media_meta'], $attachment_media['guid'] );
					$new[]            = wp_get_attachment_url( $attachment_id );
					$old[]            = $v['attachment_url'];
				}
			}

			$newContent = str_replace( $old, $new, $content ); #str_replace(old,new,str)
			$arg        = array(
				'ID'           => $post_id,
				'post_content' => $newContent,
			);
			wp_update_post( $arg );
		}
	}

	# import attechments form source to destination.
	public function insert_attachment( $attachment_id = null, $file = null ) {
		$filename          = basename( $file );
		$arrContextOptions = array(
			'ssl' => array(
				'verify_peer'      => false,
				'verify_peer_name' => false,
			),
		);
		$parent_post_id    = 0;
		$upload_file       = wp_upload_bits( $filename, null, file_get_contents( $file, false, stream_context_create( $arrContextOptions ) ) );

		if ( ! $upload_file['error'] ) {
			$wp_filetype = wp_check_filetype( $filename, null );
			$attachment  = array(
				'import_id'      => $attachment_id,
				'post_mime_type' => $wp_filetype['type'],
				'post_parent'    => $parent_post_id,
				'post_title'     => preg_replace( '/\.[^.]+$/', '', $filename ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			);

			require_once( ABSPATH . "wp-admin" . '/includes/image.php' );
			require_once( ABSPATH . "wp-admin" . '/includes/file.php' );
			require_once( ABSPATH . "wp-admin" . '/includes/media.php' );

			$attachment_id = wp_insert_attachment( $attachment, $upload_file['file'], $parent_post_id );

			if ( ! is_wp_error( $attachment_id ) ) {
				$attachment_data = wp_generate_attachment_metadata( $attachment_id, $upload_file['file'] );
				wp_update_attachment_metadata( $attachment_id, $attachment_data );
			}
		}

		return $attachment_id;
	}

	/*
	 * get post css from elementor files 'post-{post_id}.css'
	 */
	private function get_elementor_css( $post_id ) {
		$upload_dir = wp_upload_dir();
		$filename   = 'post-' . $post_id . '.css';
		$filePath   = $upload_dir['basedir'] . '/elementor/css/' . $filename;
		$css        = '';

		if ( file_exists( $filePath ) ) {
			$css = file_get_contents( $filePath );
		}

		return $css;
	}
}

new InstaWP_Sync_Post();
