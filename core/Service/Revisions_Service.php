<?php

namespace Carbon_Fields\Service;

use Carbon_Fields\Carbon_Fields;

class Revisions_Service extends Service {
	const CHANGE_KEY = '_carbon_changed';

	protected function enabled() {
		add_filter( 'carbon_get_post_meta_post_id', [ $this, 'update_post_id_on_preview' ], 10, 3 );
		add_action( 'carbon_fields_post_meta_container_saved', [ $this, 'maybe_copy_meta_to_revision' ], 10, 2 );
		add_filter('_wp_post_revision_fields', [ $this, 'maybe_save_revision' ], 10, 2 );
		add_filter('_wp_post_revision_fields', [ $this, 'add_fields_to_revision' ], 10, 2 );
		add_action( 'wp_restore_post_revision', [ $this, 'restore_post_revision' ], 10, 2 );
		add_filter( 'wp_save_post_revision_check_for_changes', [ $this, 'check_for_changes' ], 10, 3 );
	}

	protected function disabled() {
		remove_filter( 'carbon_get_post_meta_post_id', [ $this, 'update_post_id_on_preview' ], 10, 3 );
		remove_action( 'carbon_fields_post_meta_container_saved', [ $this, 'maybe_copy_meta_to_revision' ], 10, 2 );
		remove_filter('_wp_post_revision_fields', [ $this, 'maybe_save_revision' ], 10, 2 );
		remove_filter('_wp_post_revision_fields', [ $this, 'add_fields_to_revision' ], 10, 2 );
		remove_action( 'wp_restore_post_revision', [ $this, 'restore_post_revision' ], 10, 2 );
		remove_filter( 'wp_save_post_revision_check_for_changes', [ $this, 'check_for_changes' ], 10, 3 );
	}

	public function check_for_changes( $return, $last_revision, $post ) {
		if ( empty( $_POST[ self::CHANGE_KEY ] ) ) {
			return $return;
		}

		return false;
	}

	public function update_post_id_on_preview( $id, $name, $container_id ) {
		if ( empty( $_GET['preview'] ) ) {
			return $id;
		}

		$post_type = get_post_type( $id );
		if ( $post_type === 'revision' ) {
			return $id;
		}

		$preview_id = $this->get_preview_id();
		if ( ! $preview_id || $preview_id !== intval( $id ) ) {
			return $id;
		}

		$nonce = ( ! empty( $_GET['preview_nonce'] ) ) ? $_GET['preview_nonce'] : '';
		if ( ! wp_verify_nonce( $nonce, 'post_preview_' . $preview_id ) ) {
			return $id;
		}

		$revision = $this->get_latest_post_revision( $id );
		if ( empty( $revision ) ) {
			return $id;
		}

		return $revision->ID;
	}

	public function maybe_copy_meta_to_revision( $post_id, $container ) {
		$revision = $this->get_latest_post_revision( $post_id );
		if ( empty( $revision ) ) {
			return;
		}

		$this->copy_meta( $post_id, $revision->ID );
	}

	public function maybe_save_revision( $fields, $post = null ) {
		$wp_preview = ( ! empty( $_POST['wp-preview'] ) ) ? $_POST['wp-preview'] : '';
		$in_preview = $wp_preview === 'dopreview';
		if ( ! $in_preview ) {
			return $fields;
		}

		if ( ! empty( $_POST[ self::CHANGE_KEY ] ) ) {
			$fields[ self::CHANGE_KEY ] = 2;
		}

		return $fields;
	}

	public function add_fields_to_revision( $fields, $post = null ) {
		$current_screen = get_current_screen();

		$is_revision_screen = $current_screen && $current_screen->id === 'revision';
		$is_doing_ajax = defined( 'DOING_AJAX' ) && DOING_AJAX;
		$is_revision_action = ! empty( $_POST['action'] ) && $_POST['action'] === 'get-revision-diffs';
		$is_revision_diff_ajax = $is_doing_ajax && $is_revision_action;
		$is_restoring = ! empty( $_GET['action'] ) && $_GET['action'] === 'restore';

		if ( ! $is_revision_screen && ! $is_revision_diff_ajax ) {
			return $fields;
		}

		if ( $is_restoring ) {
			return $fields;
		}

		if ( $post && ! empty( $post['ID'] ) ) {
			$post_id = $post['ID'];
		} else {
			global $post;
			$post_id = $post->ID;
		}

		// get all revisioned fields for post and append them to $fields
		$revisioned_fields = $this->get_revisioned_fields( $post_id );
		$fields = array_merge( $fields, $revisioned_fields );
		// hook into _wp_post_revision_field_{$name} and return values for rendering
		foreach ( $revisioned_fields as $name => $label ) {
			add_filter( "_wp_post_revision_field_{$name}", [ $this, 'update_revision_field_value' ], 10, 4 );
		}

		return $fields;
	}

	public function update_revision_field_value( $value, $field_name, $post = null, $direction = false ) {
		if ( empty( $post ) ) {
			return $value;
		}

		$value = carbon_get_post_meta( $post->ID, substr( $field_name, 1 ) );
		if ( is_array( $value ) ) {
			$value = count( $value );
		}
		return $value;
	}

	public function restore_post_revision( $post_id, $revision_id ) {
		$this->copy_meta( $revision_id, $post_id );

		$revision = $this->get_latest_post_revision( $post_id );
		if ( $revision ) {
			$this->copy_meta( $revision_id, $revision->ID );
		}
	}

	protected function get_revisioned_fields( $post_id ) {
		$repository = Carbon_Fields::resolve( 'container_repository' );
		$containers = $repository->get_containers( 'post_meta' );
		$containers = array_filter( $containers, function( $container ) {
			return !$container->get_revisions_disabled();
		} );
		$fields = [];
		foreach ( $containers as $container ) {
			foreach ( $container->get_fields() as $field ) {
				$fields[ $field->get_name() ] = $field->get_label();
			}
		}

		return $fields;
	}

	protected function get_latest_post_revision( $post_id ) {
		$revisions = wp_get_post_revisions( $post_id );
		$revision = array_shift($revisions);

		return $revision;
	}

	protected function get_preview_id() {
		if( isset( $_GET['preview_id'] ) ) {
			return intval( $_GET['preview_id'] );
		}

		if( isset( $_GET['p'] ) ) {
			return intval( $_GET['p'] );
		}

		if( isset( $_GET['page_id'] ) ) {
			return intval( $_GET['page_id'] );
		}

		return 0;
	}

	protected function copy_meta( $from_id, $to_id ) {
	    $repository = Carbon_Fields::resolve( 'container_repository' );
	    $containers = $repository->get_containers( 'post_meta' );
	    $containers = array_filter( $containers, function( $container ) {
	        return !$container->get_revisions_disabled();
	    } );

	    $field_keys = [];
	    foreach ( $containers as $container ) {
	        foreach ( $container->get_fields() as $field ) {
	            $field_keys[] = $field->get_name();
	        }
	    }

	    $meta = get_post_meta( $from_id );
	    $meta_to_copy = $this->filter_meta_by_keys( $meta, $field_keys );

	    if ( ! $meta_to_copy ) {
	    	return;
	    }

	    $this->delete_old_meta( $to_id, $meta_to_copy );
	    $this->insert_new_meta( $to_id, $meta_to_copy );
	}

	protected function meta_key_matches_names( $meta_key, $names ) {
		foreach ( $names as $name ) {
			if ( strpos( $meta_key, $name ) === 0 ) {
				return true;
			}
		}

		return false;
	}

	protected function filter_meta_by_keys( $meta, $field_keys ) {
		$filtered_meta = [];
		foreach ( $meta as $meta_key => $meta_value ) {
			if ( ! $this->meta_key_matches_names( $meta_key, $field_keys ) ) {
				continue;
			}

			$filtered_meta[ $meta_key ] = $meta_value;
		}

		return $filtered_meta;
	}

	protected function delete_old_meta( $to_id, $meta_to_copy ) {
		global $wpdb;

		if ( ! $to_id ) {
			return;
		}

		$keys = $this->get_keys_for_mysql( $meta_to_copy );
		$delete_query = $wpdb->prepare(
			"DELETE FROM `{$wpdb->postmeta}` WHERE `meta_key` IN ({$keys}) AND `post_id` = %d",
			$to_id
		);
		$wpdb->query( $delete_query );
	}

	protected function insert_new_meta( $to_id, $meta_to_copy ) {
		global $wpdb;

		if ( ! $to_id ) {
			return;
		}

		$values = [];
		foreach ( $meta_to_copy as $meta_key => $meta_value ) {
			$meta_value_string = ( is_array( $meta_value ) ) ? $meta_value[0] : $meta_value;
			$value = '(';
			$value .= intval( $to_id );
			$value .= ", '" . esc_sql( $meta_key ) . "'";
			$value .= ", '" . esc_sql( $meta_value_string ) . "'";
			$value .= ')';

			$values[] = $value;
		}

		$values_string = implode( ',', $values );

		$sql = "INSERT INTO `{$wpdb->postmeta}`(post_id, meta_key, meta_value) VALUES " . $values_string;

	    $wpdb->query( $sql );
	}

	protected function get_keys_for_mysql( $meta_to_copy ) {
		$keys = array_keys( $meta_to_copy );
		$keys = array_map(
			function( $item ) {
				$item = "'" . esc_sql( $item ) . "'";
				return $item;
			},
			$keys
		);

		return implode( ',', $keys );
	}
}
