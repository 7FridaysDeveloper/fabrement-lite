<?php

namespace Fabrement\Api\Media;

defined( 'ABSPATH' ) || exit;

/** A document from the media library or an {id, url, …} array, with its label as the link text. */
final class File extends Attachment {

    /** Any attachment read as a file to download, whatever its kind. */
    public static function from( $value, string $lang = '' ): self {
        $any = Attachment::of( MediaValue::download( $value ), $lang );
        return $any instanceof self ? $any : new self( $any->id, $any->url, $any->lang, $any->mime, $any->filename, $any->label, $any->size );
    }

    protected function array_of( string $size, $asked = null ): array {
        return parent::array_of( $size, $asked ) + [ 'label' => $this->label, 'ext' => $this->extension() ];
    }
}
