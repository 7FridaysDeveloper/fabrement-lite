<?php

namespace Fabrement\Modules\Mcp;

use Fabrement\Modules\Mcp\Ability\AbilityInterface;
use Fabrement\Modules\Mcp\Ability\AbstractAbility;
use Fabrement\Modules\Mcp\Support\ContentSafety;
use Fabrement\Modules\Mcp\Support\McpAbilityException;
use Fabrement\Modules\Translation\Translation;

/**
 * Registers the Fabrement ability category and every Roster ability with the
 * WP Abilities API. Together with {@see ServerDefinition} this is the ONLY
 * class that talks to the Abilities/mcp-adapter registration API — when the
 * adapter's 0.x API shifts, the churn is contained here.
 */
final class Registrar {

    public const CATEGORY = 'fabrement';

    /** @var AbilityInterface[] */
    private array $abilities;

    /**
     * @param AbilityInterface[] $abilities Instances, in Roster order.
     */
    public function __construct( array $abilities ) {
        $this->abilities = $abilities;
    }

    /**
     * Runs on `wp_abilities_api_categories_init`. Must happen before any
     * ability registration: an ability with an unregistered category is
     * silently dropped by core (only a _doing_it_wrong notice under WP_DEBUG).
     */
    public function register_category(): void {
        wp_register_ability_category(
            self::CATEGORY,
            [
                'label'       => 'Fabrement',
                'description' => 'WordPress content tools.',
            ]
        );
    }

    /**
     * Runs on `wp_abilities_api_init`.
     *
     * Note: no `meta.mcp.public` flag on purpose — our abilities must be
     * reachable ONLY through the Fabrement custom server (explicit tool
     * list), never leak through an adapter default server discovery.
     */
    /** A writing tool judges the text it was sent before it does anything; a reading tool stores nothing. */
    private static function guarded( AbilityInterface $ability ): callable {
        if ( ! empty( $ability->annotations()['readOnlyHint'] ) ) {
            return [ $ability, 'execute' ];
        }
        return static function ( $input = [] ) use ( $ability ) {
            try {
                ContentSafety::input( is_array( $input ) ? $input : [], $ability->name() );
            } catch ( McpAbilityException $refused ) {
                return $refused->to_error();
            }
            return $ability->execute( is_array( $input ) ? $input : [] );
        };
    }

    public function register_abilities(): void {
        foreach ( $this->abilities as $ability ) {
            $args = [
                'label'               => $ability->label(),
                'description'         => $ability->description() . self::rules_tail( $ability ),
                'category'            => self::CATEGORY,
                'input_schema'        => $ability->input_schema(),
                'permission_callback' => [ $ability, 'check_permission' ],
                'execute_callback'    => self::guarded( $ability ),
            ];

            $output_schema = $ability->output_schema();
            if ( [] !== $output_schema ) {
                $args['output_schema'] = $output_schema;
            }

            $annotations = $ability->annotations();
            if ( [] !== $annotations ) {
                $args['meta'] = [ 'annotations' => $annotations ];
            }

            wp_register_ability( $ability->name(), $args );
        }
    }


    /**
     * The one line every tool with rules ends on, worded the same everywhere so an agent learns
     * it once: the contract to read before the first call, and — only on a site with languages —
     * the doc for the multilingual side of the tool.
     */
    private static function rules_tail( AbstractAbility $ability ): string {
        $tail = '';
        if ( $ability->contract() !== null ) {
            $tail .= "
Rules: get-skill-doc {id:\"" . $ability->contract() . '"} — read it before the first call.';
        }
        if ( $ability->languages_contract() !== null && Translation::is_multilingual() ) {
            $tail .= "
Languages: get-skill-doc {id:\"" . $ability->languages_contract() . '"}.';
        }

        return $tail;
    }

    /**
     * Registration names for the server tool list, in Roster order.
     *
     * @return list<string>
     */
    public function ability_names(): array {
        return array_map(
            static fn( AbilityInterface $ability ): string => $ability->name(),
            $this->abilities
        );
    }
}
