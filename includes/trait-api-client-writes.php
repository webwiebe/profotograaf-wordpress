<?php
/**
 * The write calls of the API client.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Gallery creation, embedding, file upload and photo metadata updates, kept apart from
 * Api_Client to keep that file short. All of them need the galleries:write
 * scope and turn a 403 into the reconnect state with Api_Errors::guard_write().
 * They use the connection and the request pipeline of Api_Client.
 */
trait Api_Client_Writes {

	/**
	 * Creates a gallery.
	 *
	 * Calls `POST /api/v1/embed/galleries` with the title. Needs the
	 * galleries:write scope. The platform creates the gallery private, so a
	 * caller that wants it on other sites follows up with mark_embeddable().
	 *
	 * @param string $title Gallery title.
	 * @return array{id:string,slug:string,title:string,url:string}|WP_Error
	 */
	public function create_gallery( string $title ) {
		$title = trim( $title );
		if ( '' === $title ) {
			return Api_Errors::make( 'profotograaf_invalid', __( 'A gallery needs a title.', 'profotograaf' ), 0, false );
		}
		$result = Api_Errors::guard_write( $this->request( 'POST', '/api/v1/embed/galleries', array( 'title' => $title ) ), $this->connection );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$result = is_array( $result ) ? $result : array();
		if ( empty( $result['id'] ) ) {
			return Api_Errors::make( 'profotograaf_http', __( 'Profotograaf did not return the new gallery.', 'profotograaf' ), 0, false );
		}
		return array(
			'id'    => (string) $result['id'],
			'slug'  => (string) ( $result['slug'] ?? '' ),
			'title' => (string) ( $result['title'] ?? $title ),
			'url'   => (string) ( $result['url'] ?? '' ),
		);
	}

	/**
	 * Uploads one file to a gallery.
	 *
	 * Calls `POST /api/v1/embed/galleries/{id}/photos` with a multipart body
	 * and one `file` part. Needs the galleries:write scope. The file is read
	 * into memory, so callers keep to the sizes the platform accepts.
	 *
	 * A 413 means the account is at its storage cap: the result is a
	 * profotograaf_storage_full error that is not retryable. Other failures
	 * follow the usual error shape; retry only when `retryable` is true.
	 *
	 * @param string $gallery_id Gallery id.
	 * @param string $path       Absolute path of the local file.
	 * @param string $filename   File name to send. The basename of $path when empty.
	 * @param string $mime_type  Media type of the file, application/octet-stream when empty.
	 * @return array{id:string,gallery_id:string,filename:string}|WP_Error
	 */
	public function upload_photo( string $gallery_id, string $path, string $filename = '', string $mime_type = '' ) {
		if ( '' === trim( $gallery_id ) ) {
			return Api_Errors::make( 'profotograaf_invalid', __( 'A gallery id is required.', 'profotograaf' ), 0, false );
		}
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return Api_Errors::make( 'profotograaf_invalid', __( 'The file could not be read.', 'profotograaf' ), 0, false );
		}
		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		if ( false === $contents ) {
			return Api_Errors::make( 'profotograaf_invalid', __( 'The file could not be read.', 'profotograaf' ), 0, false );
		}
		$filename  = '' === $filename ? basename( $path ) : $filename;
		$mime_type = '' === $mime_type ? 'application/octet-stream' : $mime_type;
		// Quotes and line breaks would end the header value early.
		$safe_name = str_replace( array( '"', "\r", "\n", '\\' ), '_', $filename );
		$boundary  = 'profotograaf' . bin2hex( random_bytes( 12 ) );

		$raw = array(
			'body'         => '--' . $boundary . "\r\n"
				. 'Content-Disposition: form-data; name="file"; filename="' . $safe_name . '"' . "\r\n"
				. 'Content-Type: ' . preg_replace( '/[^\w.+\/-]/', '', $mime_type ) . "\r\n\r\n"
				. $contents . "\r\n"
				. '--' . $boundary . "--\r\n",
			'content_type' => 'multipart/form-data; boundary=' . $boundary,
			'timeout'      => self::UPLOAD_TIMEOUT,
		);

		$result = Api_Errors::guard_write(
			$this->perform( 'POST', '/api/v1/embed/galleries/' . rawurlencode( $gallery_id ) . '/photos', null, $raw ),
			$this->connection
		);
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			if ( 'profotograaf_http' === $result->get_error_code() && is_array( $data ) && 413 === ( $data['status'] ?? 0 ) ) {
				return Api_Errors::make(
					'profotograaf_storage_full',
					__( 'Your Profotograaf storage is full. Free up space or upgrade your plan to send more files.', 'profotograaf' ),
					413,
					false
				);
			}
			return $result;
		}
		$result = is_array( $result ) ? $result : array();
		if ( empty( $result['id'] ) ) {
			return Api_Errors::make( 'profotograaf_http', __( 'Profotograaf did not return the new photo.', 'profotograaf' ), 0, false );
		}
		return array(
			'id'         => (string) $result['id'],
			'gallery_id' => (string) ( $result['gallery_id'] ?? $gallery_id ),
			'filename'   => (string) ( $result['filename'] ?? $filename ),
		);
	}

	/**
	 * Updates the title, caption or alt text of a photo.
	 *
	 * Calls `PATCH /api/v1/embed/photos/{id}`. Needs the galleries:write
	 * scope. Only the keys present in $fields are sent, and at least one of
	 * title, caption and alt is required (the platform answers an empty body
	 * with 400, so it is refused here without a request).
	 *
	 * @param string               $photo_id Photo id.
	 * @param array<string,string> $fields   Any of title, caption and alt.
	 * @return array<string,mixed>|WP_Error The platform's answer.
	 */
	public function update_photo( string $photo_id, array $fields ) {
		if ( '' === trim( $photo_id ) ) {
			return Api_Errors::make( 'profotograaf_invalid', __( 'A photo id is required.', 'profotograaf' ), 0, false );
		}
		$body = array();
		foreach ( array( 'title', 'caption', 'alt' ) as $key ) {
			if ( isset( $fields[ $key ] ) ) {
				$body[ $key ] = (string) $fields[ $key ];
			}
		}
		if ( array() === $body ) {
			return Api_Errors::make( 'profotograaf_invalid', __( 'Nothing to update: give a title, caption or alt text.', 'profotograaf' ), 0, false );
		}
		$result = Api_Errors::guard_write( $this->request( 'PATCH', '/api/v1/embed/photos/' . rawurlencode( $photo_id ), $body ), $this->connection );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return is_array( $result ) ? $result : array();
	}

	/**
	 * Marks a gallery as embeddable on other sites.
	 *
	 * Calls `PUT /api/v1/embed/galleries/{id}/embeddable`, which needs the
	 * galleries:embed scope. The answer is `id`, `embeddable` and `available`.
	 * `available` is false when a password, an expiry date, proofing mode or a
	 * client-only setting keeps the gallery off other sites even though the
	 * setting is on; callers tell the photographer.
	 *
	 * A 403 means the token was paired before the scope existed. The result is
	 * then a profotograaf_reconnect error and the connection remembers it, so
	 * the settings page asks the photographer to connect again.
	 *
	 * @param string $gallery_id Gallery id.
	 * @return array{id:string,embeddable:bool,available:bool}|WP_Error
	 */
	public function mark_embeddable( string $gallery_id ) {
		if ( '' === trim( $gallery_id ) ) {
			return Api_Errors::make( 'profotograaf_invalid', __( 'A gallery id is required.', 'profotograaf' ), 0, false );
		}

		$request = array(
			'method' => 'PUT',
			'path'   => '/api/v1/embed/galleries/' . rawurlencode( $gallery_id ) . '/embeddable',
			'body'   => array( 'embeddable' => true ),
		);

		/**
		 * Overrides the request that marks a gallery embeddable.
		 *
		 * @param array{method:string,path:string,body:array<string,mixed>} $request    The default request.
		 * @param string                                                    $gallery_id Gallery id.
		 */
		$request = apply_filters( 'profotograaf_mark_embeddable_request', $request, $gallery_id );
		if ( ! is_array( $request ) || empty( $request['method'] ) || empty( $request['path'] ) ) {
			return Api_Errors::make( 'profotograaf_invalid', __( 'The request to switch embedding on is not valid.', 'profotograaf' ), 0, false );
		}

		$result = $this->request( (string) $request['method'], (string) $request['path'], isset( $request['body'] ) && is_array( $request['body'] ) ? $request['body'] : null );
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			if ( 'profotograaf_http' === $result->get_error_code() && is_array( $data ) && 403 === ( $data['status'] ?? 0 ) ) {
				$this->connection->flag_embed_denied();
				return Api_Errors::make(
					'profotograaf_reconnect',
					__( 'This connection may not switch galleries on yet. Connect this site again under Settings > Profotograaf to grant the new permission.', 'profotograaf' ),
					403,
					false
				);
			}
			return $result;
		}
		$result = is_array( $result ) ? $result : array();
		return array(
			'id'         => (string) ( $result['id'] ?? $gallery_id ),
			'embeddable' => ! empty( $result['embeddable'] ),
			'available'  => ! empty( $result['available'] ),
		);
	}
}
