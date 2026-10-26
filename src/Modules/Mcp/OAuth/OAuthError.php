<?php

namespace Fabrement\Modules\Mcp\OAuth;

final class OAuthError extends \RuntimeException {
    public string $error;
    public int $status;

    public function __construct( string $error, string $message, int $status = 400 ) {
        parent::__construct( $message );
        $this->error  = $error;
        $this->status = $status;
    }
}
