<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Capabilities;
use Fabrement\Modules\Translation\Translation;

/**
 * Base for Fabrement abilities: default permission + no output schema.
 */
abstract class AbstractAbility implements AbilityInterface {

    /**
     * Does this tool belong on THIS site at all?
     *
     * Tools are described per site and per request, so one that cannot do anything here is
     * better absent than present-and-refusing: an agent reads every description it is given, and
     * a tool about languages on a site with one language is a question it has to answer before
     * it can ignore it. Default true — most tools apply everywhere.
     */
    public function is_available(): bool {
        return true;
    }

    /**
     * Second permission rubicon (the first gates the whole server transport).
     * Override only for tools with a different capability profile.
     *
     * @return bool|\WP_Error
     */
    public function check_permission() {
        return current_user_can( Capabilities::ADMIN );
    }

    /**
     * The skill doc (get-skill-doc id) that holds this tool's rules — the flow it belongs to, the
     * order of calls, the gotchas. The description() of a tool says WHAT it does; everything an
     * agent must know before the first call lives in the contract, which it fetches once per
     * session. Registrar appends a uniform "Rules:" line naming it, so an agent that never saw the
     * server instructions still finds the way there. Null = the tool needs no reading first.
     */
    public function contract(): ?string {
        return null;
    }


    /**
     * The doc for what this tool does on a MULTILINGUAL site — named in the description only
     * where the site has languages, so a single-language site never hears of it.
     */
    public function languages_contract(): ?string {
        return null;
    }

    public function output_schema(): array {
        return [];
    }

    /**
     * Schema entries (properties, required names) that exist only on a multilingual site — a
     * single-language site never sees a field about languages.
     */
    protected static function multilingual( array $entries ): array {
        return Translation::is_multilingual() ? $entries : [];
    }

    /** A description fragment that exists only on a multilingual site. */
    protected static function multilingual_text( string $text ): string {
        return Translation::is_multilingual() ? $text : '';
    }

    public function annotations(): array {
        return [];
    }
}
