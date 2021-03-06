<?php

/**
 * SearchPress Sync Manager
 *
 * Controls the data sync from WordPress to elasticsearch
 *
 * Reminders and considerations while building this:
 * @todo Trigger massive reindex (potentially) when indexed usermeta is edited
 * @todo Trigger massive reindex when term data is edited
 * @todo Changing permalinks should trigger full reindex?
 *
 * @author Matthew Boynes
 */

if ( !class_exists( 'SP_Sync_Manager' ) ) :

class SP_Sync_Manager {

	private static $instance;

	public $users = array();

	public $sync_meta;

	public $published_posts = false;
	public $total_pages = 1;
	public $batch_pages = 1;

	/**
	 * @codeCoverageIgnore
	 */
	private function __construct() {
		/* Don't do anything, needs to be initialized via instance() method */
	}

	/**
	 * @codeCoverageIgnore
	 */
	public function __clone() { wp_die( "Please don't __clone SP_Sync_Manager" ); }

	/**
	 * @codeCoverageIgnore
	 */
	public function __wakeup() { wp_die( "Please don't __wakeup SP_Sync_Manager" ); }

	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new SP_Sync_Manager;
		}
		return self::$instance;
	}

	/**
	 * Sync a single post (on creation or update)
	 *
	 * @todo if post should not be added, it's deleted (to account for unpublishing, etc). Make that more elegant.
	 *
	 * @param int $post_id
	 * @return void
	 */
	public function sync_post( $post_id ) {
		$post = new SP_Post( get_post( $post_id ) );
		if ( $post->should_be_indexed() ) {
			$response = SP_API()->index_post( $post );
			if ( ! $this->parse_error( $response, array( 200, 201 ) ) ) {
				do_action( 'sp_debug', '[SP_Sync_Manager] Indexed Post', $response );
			} else {
				do_action( 'sp_debug', '[SP_Sync_Manager] Error Indexing Post', $response );
			}
		} else {
			# This is excessive, figure out a better way around it
			$this->delete_post( $post_id );
		}
	}

	/**
	 * Delete a post from the ES index.
	 *
	 * @param  int $post_id
	 */
	public function delete_post( $post_id ) {
		$response = SP_API()->delete_post( $post_id );

		# We're OK with 404 responses here because a post might not be in the index
		if ( ! $this->parse_error( $response, array( 200, 404 ) ) ) {
			do_action( 'sp_debug', '[SP_Sync_Manager] Deleted Post', $response );
		} else {
			do_action( 'sp_debug', '[SP_Sync_Manager] Error Deleting Post', $response );
		}
	}

	/**
	 * Parse any errors found in a single-post ES response.
	 *
	 * @param  object $response SP_API response
	 * @param  array  $allowed_codes Allowed HTTP status codes. Default is array( 200 )
	 * @return bool   True if errors are found, false if successful.
	 */
	protected function parse_error( $response, $allowed_codes = array( 200 ) ) {
		if ( ! empty( $response->error ) ) {
			SP_Sync_Meta()->log( new WP_Error( 'error', date( '[Y-m-d H:i:s] ' ) . $response->error->message, $response->error->data ) );
		} elseif ( ! in_array( SP_API()->last_request['response_code'], $allowed_codes ) ) {
			SP_Sync_Meta()->log( new WP_Error( 'error', sprintf( __( '[%s] Elasticsearch response failed! Status code %d; %s', 'searchpress' ), date( 'Y-m-d H:i:s' ), SP_API()->last_request['response_code'], json_encode( SP_API()->last_request ) ) ) );
		} elseif ( ! is_object( $response ) ) {
			SP_Sync_Meta()->log( new WP_Error( 'error', sprintf( __( '[%s] Unexpected response from Elasticsearch: %s', 'searchpress' ), date( 'Y-m-d H:i:s' ), json_encode( $response ) ) ) );
		} else {
			return false;
		}
		return true;
	}

	/**
	 * Get all the posts in a given range
	 *
	 * @param int $start
	 * @param int $limit
	 * @return string JSON array
	 */
	public function get_range( $start, $limit ) {
		return $this->get_posts( array(
			'offset'         => $start,
			'posts_per_page' => $limit
		) );
	}

	/**
	 * Get posts to loop through
	 *
	 * @param array $args arguments passed to get_posts
	 * @return array
	 */
	public function get_posts( $args = array() ) {
		$args = wp_parse_args( $args, array(
			'post_status'         => 'publish',
			'post_type'           => null,
			'orderby'             => 'ID',
			'order'               => 'ASC',
			'suppress_filters'    => true,
			'ignore_sticky_posts' => true,
		) );

		if ( empty( $args['post_type'] ) ) {
			$args['post_type'] = sp_searchable_post_types();
		}

		$args = apply_filters( 'searchpress_index_loop_args', $args );

		$query = new WP_Query;
		$posts = $query->query( $args );

		do_action( 'sp_debug', '[SP_Sync_Manager] Queried Posts', $args );

		$this->published_posts = $query->found_posts;
		$this->batch_pages = $query->max_num_pages;

		$indexed_posts = array();
		foreach ( $posts as $post ) {
			$indexed_posts[ $post->ID ] = new SP_Post( $post );
		}
		do_action( 'sp_debug', '[SP_Sync_Manager] Converted Posts' );

		return $indexed_posts;
	}

	/**
	 * Do an indexing loop. This is the meat of the process.
	 *
	 * @return bool
	 */
	public function do_index_loop() {
		$sync_meta = SP_Sync_Meta();

		$start = $sync_meta->page * $sync_meta->bulk;
		do_action( 'sp_debug', '[SP_Sync_Manager] Getting Range' );
		$posts = $this->get_range( $start, $sync_meta->bulk );
		// Reload the sync meta to ensure it hasn't been canceled while we were getting those posts
		$sync_meta->reload();

		if ( !$posts || is_wp_error( $posts ) || ! $sync_meta->running ) {
			return false;
		}

		$response = SP_API()->index_posts( $posts );
		do_action( 'sp_debug', sprintf( '[SP_Sync_Manager] Indexed %d Posts', count( $posts ) ), $response );

		$sync_meta->reload();
		if ( ! $sync_meta->running ) {
			return false;
		}

		$sync_meta->processed += count( $posts );

		if ( '200' != SP_API()->last_request['response_code'] ) {
			# Should probably throw an error here or something
			$sync_meta->log( new WP_Error( 'error', __( 'ES response failed', 'searchpress' ), SP_API()->last_request ) );
			$sync_meta->save();
			$this->cancel_reindex();
			return false;
		} elseif ( ! is_object( $response ) || ! isset( $response->items ) || ! is_array( $response->items ) ) {
			$sync_meta->log( new WP_Error( 'error', __( 'Error indexing data', 'searchpress' ), $response ) );
			$sync_meta->save();
			$this->cancel_reindex();
			return false;
		} else {
			foreach ( $response->items as $post ) {
				// Status should be 200 or 201, depending on if we're updating or creating respectively
				if ( ! isset( $post->index->status ) ) {
					$sync_meta->log( new WP_Error( 'warning', __( "Error indexing post {$post->index->_id}; Response: " . json_encode( $post ), 'searchpress' ), $post ) );
				} elseif ( ! in_array( $post->index->status, array( 200, 201 ) ) ) {
					$sync_meta->log( new WP_Error( 'warning', __( "Error indexing post {$post->index->_id}; HTTP response code: {$post->index->status}", 'searchpress' ), $post ) );
				} else {
					$sync_meta->success++;
				}
			}
		}
		$this->total_pages = ceil( $this->published_posts / $sync_meta->bulk );
		$sync_meta->page++;

		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			if ( $sync_meta->processed >= $sync_meta->total || $sync_meta->page > $this->total_pages ) {
				SP_Config()->update_settings( array( 'active' => true ) );
				$this->cancel_reindex();
			} else {
				$sync_meta->save();
			}
		}
		return true;
	}

	/**
	 * Initialize a cron reindexing.
	 */
	public function do_cron_reindex() {
		SP_Sync_Meta()->start();
		SP_Sync_Meta()->total = $this->count_posts();
		SP_Sync_Meta()->save();
		SP_Cron()->schedule_reindex();
	}

	/**
	 * Cancel reindexing.
	 */
	public function cancel_reindex() {
		SP_Cron()->cancel_reindex();
	}

	/**
	 * Count the posts to index.
	 *
	 * @param  array  $args WP_Query args used for counting.
	 * @return int Total number of posts to index.
	 */
	public function count_posts( $args = array() ) {
		if ( false === $this->published_posts ) {
			$args = wp_parse_args( $args, array(
				'post_type' => null,
				'post_status' => 'publish',
				'posts_per_page' => 1
			) );
			if ( empty( $args['post_type'] ) ) {
				$args['post_type'] = sp_searchable_post_types();
			}

			$args = apply_filters( 'searchpress_index_count_args', $args );

			$query = new WP_Query( $args );
			$this->published_posts = $query->found_posts;
		}
		return $this->published_posts;
	}
}

function SP_Sync_Manager() {
	return SP_Sync_Manager::instance();
}


/**
 * SP_Sync_Manager only gets instantiated when necessary, so we register these hooks outside of the class
 */
if ( SP_Config()->active() ) {
	add_action( 'save_post',                  array( SP_Sync_Manager(), 'sync_post' ) );
	// add_action( 'added_term_relationship',    array( SP_Sync_Manager(), 'sync_post' ) );
	// add_action( 'deleted_term_relationships', array( SP_Sync_Manager(), 'sync_post' ) );
	add_action( 'deleted_post',               array( SP_Sync_Manager(), 'delete_post' ) );
	add_action( 'trashed_post',               array( SP_Sync_Manager(), 'delete_post' ) );
}

endif;