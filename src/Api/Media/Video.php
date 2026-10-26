<?php

namespace Fabrement\Api\Media;

use Fabrement\Api\Support\ReadResult;

defined( 'ABSPATH' ) || exit;

/** A video from the media library or an {id, url, …} array; its poster is the video's own featured image. */
final class Video extends Attachment {

    private ?Image $poster = null;

    public function poster( ...$options ): ?Image {
        return ReadResult::read( 'Video::poster()', $options, [], fn() => $this->poster_image(), Image::none() );
    }

    protected function array_of( string $size, $asked = null ): array {
        return parent::array_of( $size, $asked ) + [ 'poster' => (string) $this->poster_image()->url( size: is_string( $asked ) ? $asked : null ) ];
    }

    private function poster_id(): int {
        if ( $this->id <= 0 ) {
            return 0;
        }
        return (int) $this->asked( fn() => get_post_thumbnail_id( $this->id ) );
    }

    private function poster_image(): Image {
        if ( $this->poster === null ) {
            $thumb        = $this->poster_id();
            // The video's own _thumbnail_id, as WordPress reads it: WPML keeps it per language.
            $this->poster = $thumb > 0 ? Image::from( $thumb, '', $this->lang, false ) : Image::none();
        }
        return $this->poster;
    }
}
