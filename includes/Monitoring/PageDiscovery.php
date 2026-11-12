<?php
declare(strict_types=1);

namespace SiteMonitor\Monitoring;

final class PageDiscovery {
	/** @return array{items:list<array{url:string,post_id:int,source:string}>,page:int,pages:int,total:int} */
	public function discover( array $post_types, int $page = 1, int $per_page = 50 ): array {
		$allowed = array_keys( get_post_types( array( 'public' => true ), 'names' ) );
		$allowed = array_values( array_diff( $allowed, array( 'attachment' ) ) );
		$types   = array_values( array_intersect( array_map( 'sanitize_key', $post_types ), $allowed ) );
		$types   = array() === $types ? array( 'page' ) : $types;
		$query   = new \WP_Query(
			array(
				'post_type'              => $types,
				'post_status'            => 'publish',
				'has_password'           => false,
				'posts_per_page'         => max( 1, min( 100, $per_page ) ),
				'paged'                  => max( 1, $page ),
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'fields'                 => 'ids',
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$items = array();
		foreach ( $query->posts as $post_id ) {
			$url = get_permalink( (int) $post_id );
			if ( is_string( $url ) && '' !== $url ) {
				$items[] = array( 'url' => $url, 'post_id' => (int) $post_id, 'source' => 'wordpress' );
			}
		}

		return array( 'items' => $items, 'page' => max( 1, $page ), 'pages' => max( 1, (int) $query->max_num_pages ), 'total' => (int) $query->found_posts );
	}
}
