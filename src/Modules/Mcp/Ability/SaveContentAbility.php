<?php

namespace Fabrement\Modules\Mcp\Ability;

use Fabrement\Modules\Mcp\Support\BlockEntry;
use Fabrement\Modules\Mcp\Support\ContentEdits;
use Fabrement\Modules\Mcp\Support\ContentParent;
use Fabrement\Modules\Mcp\Support\McpAbilityException;
use Fabrement\Modules\Seo\Seo;
use Fabrement\Modules\Storage\TextEdits;
use Fabrement\Modules\Translation\Translation;

/**
 * Writes a CONTENT RECORD — a page, a post, or any public custom type — whose body is an
 * ORDERED list of blocks (by registered name, or literal core markup), each with optional attribute
 * overrides, rendered into Gutenberg block-comment markup. Without `contentId` it creates the
 * record as a draft; with one it updates that record (add / replace, plus the title when given)
 * and leaves its status alone. Publishing is set-content-status.
 *
 * Attribute overrides are shallow-merged over the block's declared defaults by
 * Gutenberg itself; anything not passed keeps its default. Media attributes take
 * the {id, url} pair from upload-media / list-media.
 */
final class SaveContentAbility extends AbstractAbility {


	/**
	 * Commerce records this tool refuses, on create AND on update: they carry pricing, stock and
	 * tax data that lives outside post_content, and WooCommerce's own screens own them.
	 */
	private const COMMERCE_TYPES = [ 'product', 'product_variation' ];

	public function name(): string {
		return 'fabrement/save-content';
	}

	public function description(): string {
		return 'Create a page/post/custom-type record from installed blocks, or UPDATE one by contentId. '
			. 'There is no `status` argument: a new record is always a DRAFT, an update keeps its status, and publishing is set-content-status, after the user agrees. '
			. '`mode` is REQUIRED and has no default: create (new record, no contentId) · add (blocks are ADDED '
			. 'after the body already there) · replace (the body becomes exactly `blocks`) · edit (change part of it '
			. 'through `edits`). Title applies when given. '
			. 'blocks[] entries are {name, attributes} for a block written by its attributes (ACF, server-rendered)'
			. ' or {markup} for a block saved as HTML. list-content BEFORE creating; '
			. 'get-content BEFORE changing a record. To change part of a body use mode "edit" with `edits` '
			. '(update/text/insert/replace/remove/move by index + name) and expectedHash — never re-send '
			. 'every block for a small change. '
			. '`parent` nests a record under another of a hierarchical type; `menuOrder` orders siblings.'
			. self::multilingual_text( ' Another language of an existing record is made with translate-content, never here.' );
	}

	public function contract(): ?string {
		return 'content-contract';
	}

	/**
	 * The language fields, or nothing at all when the site has one language.
	 *
	 * @return array<string,array<string,string>>
	 */
	private static function language_properties(): array {
		if ( ! Translation::is_multilingual() ) {
			return [];
		}

		return [
			'language'      => [
				'type'        => 'string',
				'description' => 'Create the record in this language instead of the site default — a code from list-languages.',
			],
			'newOriginal' => [
				'type'        => 'boolean',
				'description' => 'Required with a non-default `language`: your statement, after list-content, that no page like this exists in ANY language. One that does gets a VERSION via translate-content instead.',
			],
		];
	}

	public function input_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'blocks'  => [
					'type'        => 'array',
					'minItems'    => 0,
					'description' => 'Ordered list of blocks to insert. EMPTY creates an empty record — what the posts-index page needs, since WordPress replaces its content with the loop.',
					'items'       => [
						'type'                 => 'object',
						'properties'           => [
							'name'       => [ 'type' => 'string', 'description' => 'The REGISTERED block name, exactly as list-blocks returns it (e.g. "acf/hero").' ],
							'attributes' => [ 'type' => 'object', 'description' => 'Attribute overrides; media fields as {id, url} from upload-media / list-media.' ],
							'markup'     => [
								'type'        => 'string',
								'description' => 'INSTEAD of `name`: literal Gutenberg markup for a block saved as HTML, exactly as the editor saves it — "<!-- wp:paragraph --><p>text</p><!-- /wp:paragraph -->".',
							],
						],
						'additionalProperties' => false,
					],
				],
				'contentId' => [ 'type' => 'integer', 'description' => 'Existing record id (list-content) to UPDATE. Omit to create a new one.' ],
				'excerpt'  => [
					'type'        => 'string',
					'description' => 'Short plain-text summary for cards, archives and search — a body of server-rendered blocks may yield no automatic excerpt, so set it whenever the record will be listed. On update: given = replaced, "" = cleared, absent = kept.',
				],
				'featuredImage' => [
					'type'        => 'integer',
					'description' => 'Attachment id (upload-media / list-media) for the featured image — the thumbnail cards and archives show. Must be an image; on update absent = kept.',
				],
				'title'    => [ 'type' => 'string', 'description' => 'Title for the new record; on update (contentId set) renames it when given.' ],
				'slug'     => [
					'type'        => 'string',
					'maxLength'   => 200,
					'description' => 'URL slug. Omitted: derived from the title on create, kept on update. Give it when the '
						. 'address matters.'
						. self::multilingual_text( ' For a new translation without a slug, WordPress derives it from the translated title when published. Once stored, a title-only edit keeps that slug; send slug explicitly to change the address.' )
						. ' Also when the title is not Latin: a Cyrillic title '
						. 'becomes a percent-encoded URL. Changing it on a published record moves that page.',
				],
				'mode'     => [
					'type'        => 'string',
					'enum'        => [ 'create', 'add', 'replace', 'edit' ],
					'description' => 'Required — there is no default. create: a NEW record; contentId must be absent. '
						. 'The other three act on an existing contentId. add: `blocks` are ADDED AFTER the body '
						. 'already there — this is how a record ends up holding two copies of itself when replace was '
						. 'meant. replace: the body becomes exactly `blocks`. edit: change part of the body through '
						. '`edits`, without re-sending every block.',
				],
				'edits'    => ContentEdits::schema(),
				'expectedHash' => [ 'type' => 'string', 'description' => 'mode "edit": `hash` from get-content. If the body changed since, nothing is saved.' ],
				'postType' => [ 'type' => 'string', 'description' => 'Type for the NEW record — any public type from list-post-types; default "page".' ],
				'parent'   => [
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => 'Parent record id — hierarchical types only (list-post-types `hierarchical`: pages, and custom types made hierarchical). '
						. 'The record then lives at /parent-slug/its-slug/. Same type, not in the trash, not the record itself or one of its children. '
						. '0 = top level. Create: default 0. Update: absent = kept.'
						. self::multilingual_text( ' A parent in another language is replaced by its version in this record\'s language; refused when that version does not exist.' ),
				],
				'menuOrder' => [
					'type'        => 'integer',
					'description' => 'Place among records with the same parent (WordPress "Order"): lower first, ties by title. Page lists and list-content {orderby:"menuOrder"} follow it. Create: default 0. Update: absent = kept.',
				],
			]
			// Only where they can do something. A single-language site is most sites, and two
			// fields about languages there are two things an agent has to read and rule out on
			// every call — the schema is in its context whether or not it ever uses them.
			+ self::language_properties(),
			// `blocks` is NOT required: a page whose body WordPress generates (the posts index)
			// still has to exist as a record, and demanding a block for it forced callers to
			// insert something the visitor would never see.
			//
			// `mode` IS required, and has no default. It used to default to "append", which reads
			// as the harmless choice and is not: a caller that meant to rewrite a page and sent
			// its whole body got that body added a SECOND time, and the page rendered twice over.
			// Nothing in the response says so — the block list comes back looking correct. The
			// same silence covered a missing contentId, which quietly created a duplicate record
			// instead of updating the intended one; "create" is now a mode a caller has to name.
			'required'             => [ 'mode' ],
			'additionalProperties' => false,
		];
	}

	public function output_schema(): array {
		return [
			'type'       => 'object',
			'required'   => [ 'contentId', 'status', 'url', 'editUrl', 'blocks', 'note' ],
			'properties' => [
				'contentId' => [ 'type' => 'integer', 'description' => 'New on create, the same one back on update. Feeds set-post-terms and update-site-settings.' ],
				'status'  => [ 'type' => 'string', 'description' => 'Status AFTER the call, read back from WordPress: "draft" for a new record, unchanged on update.' ],
				'url'     => [ 'type' => 'string', 'description' => 'Publicly reachable only when status is "publish".' ],
				'slug'    => [ 'type' => 'string', 'description' => 'The slug as stored — sanitised, and suffixed when the address was taken. Empty on a draft that was given none (see slugWhenPublished).' ],
				'slugWhenPublished' => [ 'type' => 'string', 'description' => 'Only while `slug` is empty — a draft that was given none: the slug WordPress will make from its title when it is published. A forecast, not a fact: it follows the title, and another record may take it first. Send `slug` to fix the address.' ],
				'parent'    => [ 'type' => 'integer', 'description' => 'Parent id AS STORED; 0 = top level.' ],
				'menuOrder' => [ 'type' => 'integer' ],
				'editUrl' => [ 'type' => 'string' ],
				'blocks'  => [
					'type'        => 'array',
					'description' => 'What THIS CALL wrote, in order — with mode "add" only the added blocks, not the whole body (get-content shows that).',
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'block'   => [ 'type' => 'string', 'description' => 'The registered name written into the markup.' ],
							'title'   => [ 'type' => 'string' ],
							// A `markup` entry reports the block names it turned out to contain.
							'markup'  => [ 'type' => 'boolean', 'description' => 'True = this entry came from literal markup.' ],
							'blocks'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Block names found in that markup, nesting included.' ],
						],
					],
				],
				'excerpt'       => [ 'type' => 'string', 'description' => 'The summary as stored AFTER the call — empty means this record still has none.' ],
				'featuredImage' => [
					'type'        => 'object',
					'description' => 'The featured image AFTER the call; id 0 = none is set.',
					'properties'  => [
						'id'  => [ 'type' => 'integer' ],
						'url' => [ 'type' => 'string' ],
					],
				],
				'note'    => [ 'type' => 'string' ],
			] + self::multilingual( [
				// About THIS RECORD, not about the site: absent means the translation plugin does
				// not track this record (an untranslatable type). "Does this site have languages"
				// is list-languages' question — and on a single-language site the key is not
				// even in the schema, so there is nothing to misread.
				'language'      => [ 'type' => 'string', 'description' => 'Which language this record landed in. Absent when the translation plugin does not translate this post type — ask list-languages about the site itself.' ],
			] ),
		];
	}

	public function label(): string {
		return 'Save content';
	}

	private static string $image_refused = '';

	public function annotations(): array {
		return [ 'destructiveHint' => false ];
	}

	public function execute( array $input ) {
		self::$image_refused = '';
		try {
			return $this->write( $input );
		} catch ( McpAbilityException $e ) {
			return $e->to_error();
		}
	}

	/**
	 * `mode` says what this call does to the record. The input schema already requires it and
	 * limits it to create / add / replace / edit; what it cannot say is how the mode pairs with
	 * `contentId`, so that is checked here — and the refusal names which mode fits instead.
	 *
	 * @throws McpAbilityException When the mode disagrees with contentId.
	 */
	private static function assert_mode( array $args ): void {
		$mode       = (string) $args['mode'];
		$content_id = isset( $args['contentId'] ) ? (int) $args['contentId'] : 0;

		if ( $mode === 'create' && $content_id > 0 ) {
			throw new McpAbilityException(
				sprintf( 'mode "create" makes a NEW record, but contentId %d was passed. To change that record send "replace", "add" or "edit"; to create one, drop contentId.', (int) $content_id )
			);
		}

		if ( $mode !== 'create' && $content_id <= 0 ) {
			throw new McpAbilityException(
				sprintf( 'mode "%s" changes an existing record — pass its contentId (list-content). A new record is mode "create".', esc_html( $mode ) )
			);
		}
	}

	private function write( array $args ): array {
		self::assert_mode( $args );

		// mode "edit": the body is the stored one with `edits` applied, and from here on it is
		// written exactly like a replace. Resolved first, so a failing op writes nothing — the
		// record checks below still run before the write.
		$is_edit = ( $args['mode'] ?? '' ) === 'edit';
		if ( $is_edit ) {
			$entries = self::edited_entries( $args );
		} elseif ( ! in_array( $args['edits'] ?? null, [ null, [], '', '[]' ], true ) || ( $args['expectedHash'] ?? '' ) !== '' ) {
			// Empty ones are a stale client's filler, not a request.
			throw new McpAbilityException( '`edits` and `expectedHash` belong to mode "edit" — add mode: "edit", or send `blocks`.' );
		} else {
			$entries = isset( $args['blocks'] ) && is_array( $args['blocks'] ) ? $args['blocks'] : [];
		}
		$body = self::serialize_body( $entries, $is_edit );

		$content_id = isset( $args['contentId'] ) ? (int) $args['contentId'] : 0;
		$language   = self::asked_language( $args, $content_id );

		$content_id = $content_id > 0
			? $this->update( $content_id, $args, $body['markup'] )
			: $this->create( $args, $body['markup'], $language );

		return self::answer( $content_id, $body['inserted'] );
	}

	/**
	 * The entries → block markup, plus what the answer reports for each.
	 *
	 * An EMPTY list is legitimate, and there is one case that needs it: the page assigned as
	 * the posts index. WordPress renders the loop there and never shows that page's own
	 * content, so demanding a block for it meant inserting something no visitor could ever
	 * see. On an UPDATE with mode "add" an empty list is a no-op (nothing to add); with
	 * "replace" it clears the record, which is the only way to empty one.
	 *
	 * @param array<int|string,mixed> $entries
	 * @return array{markup:string, inserted:list<array<string,mixed>>}
	 */
	private static function serialize_body( array $entries, bool $is_edit ): array {
		$pieces   = [];
		$inserted = [];
		foreach ( $entries as $i => $entry ) {
			if ( ! is_array( $entry ) ) {
				throw new McpAbilityException( sprintf( 'blocks[%d] must be an object.', (int) $i ) );
			}
			// One vocabulary for a block entry — {name, attributes?} or {markup} — shared with
			// the site-template frame, so the two tools cannot drift. See {@see BlockEntry}.
			// In an edit the untouched blocks come from the stored body, which may hold a Fabrement
			// block carried as markup (an admin wrapped content into it) — writing it back as it
			// was is not the shortcut the refusal is there to stop.
			// …and its attribute names were already checked op by op, for what the caller SENT;
			// re-checking every stored block here would make one stale attribute on a page block
			// every edit of its neighbours.
			$piece      = BlockEntry::serialize( $entry, (int) $i, 'blocks', $is_edit, ! $is_edit );
			$pieces[]   = $piece['markup'];
			$inserted[] = isset( $entry['markup'] )
				? [ 'markup' => true, 'blocks' => $piece['blocks'] ]
				: [ 'block' => $piece['blocks'][0], 'title' => $piece['title'] ?? $piece['blocks'][0] ];
		}

		return [ 'markup' => implode( "\n\n", $pieces ), 'inserted' => $inserted ];
	}

	/**
	 * The `language` a CREATE asked for, checked; '' when none was asked for.
	 *
	 * A record off the site's default language is, far more often than not, a translation of
	 * one that exists — and the usual way a second "About" appears is a caller that never
	 * looked. So off the default language the caller has to SAY so: newOriginal is a statement
	 * that nothing like it exists in any language. Nothing is inferred; it changes nothing but
	 * the asking. Versions are never made here — translate-content copies the original.
	 */
	private static function asked_language( array $args, int $content_id ): string {
		$bridge   = Translation::bridge();
		$language = isset( $args['language'] ) ? trim( (string) $args['language'] ) : '';
		if ( $language === '' ) {
			return '';
		}

		if ( $content_id === 0 && empty( $args['newOriginal'] )
			&& $language !== $bridge->default_language() && $bridge->languages() !== []
		) {
			throw new McpAbilityException(
				sprintf(
					'Creating %s content on a site whose default language is %s. Check list-content first: if a page like it exists in any language, it is a VERSION — translate-content {contentId: its originalId, language: "%s"}, then edit the copy. If none does, pass newOriginal: true.',
					esc_html( $language ),
					esc_html( $bridge->default_language() ),
					esc_html( $language )
				)
			);
		}
		if ( ! in_array( $language, array_column( $bridge->languages(), 'code' ), true ) ) {
			throw new McpAbilityException( sprintf( '"%s" is not a language on this site — see list-languages.', esc_html( $language ) ) );
		}
		if ( $content_id > 0 ) {
			throw new McpAbilityException(
				sprintf( 'Content %d is already in a language: `language` is for CREATE only. Drop it to edit this record; another language of it is made with translate-content {contentId: its originalId, language}.', (int) $content_id )
			);
		}

		return $language;
	}

	/**
	 * UPDATE an existing page/post: add to or replace the content; the title is also updated
	 * when the caller passed it. The status is never touched here — publishing is its own
	 * tool, set-content-status, marked destructive so a client asks before a record goes public.
	 *
	 * Every check runs before the write, so a refusal leaves the record as it was.
	 */
	private function update( int $content_id, array $args, string $markup ): int {
		$bridge = Translation::bridge();
		$record = get_post( $content_id );
		// Any PUBLIC type may be edited — a site whose content model an agent built is mostly
		// custom types, and refusing them here made the whole model read-only. Non-public
		// types (revisions, menu items, our own records) stay out: they are plumbing, and
		// rewriting one by id would corrupt something the user never asked about.
		if ( ! $record instanceof \WP_Post || ! is_post_type_viewable( $record->post_type ) ) {
			throw new McpAbilityException( sprintf( 'No editable content with id %d — see list-content.', (int) $content_id ) );
		}
		$type = (string) $record->post_type;
		self::assert_writable( $type );
		// The per-POST check, which the per-type one does not cover: another author's record,
		// or one locked by a role plugin. WordPress would refuse the write anyway — this just
		// says so before the markup is built.
		if ( ! current_user_can( 'edit_post', $content_id ) ) {
			throw new McpAbilityException( sprintf( 'You cannot edit content %d on this site.', (int) $content_id ) );
		}

		// Only for a record that IS a translation. The question "does the translation
		// plugin's editor own this text" is about translations; asked of an ORIGINAL, WPML
		// answers from its site-wide editor setting and says yes — which would refuse every
		// ordinary edit of every ordinary page, with a message about translations that do
		// not exist. list-content now hands agents the sibling ids, so editing a translation
		// directly is a path they will take, and that is the one worth guarding.
		if ( ! $bridge->is_original( $content_id, $type ) && ! $bridge->writes_translations_manually( $content_id ) ) {
			throw new McpAbilityException(
				sprintf(
					'Content %d is one language’s version of another record, and the translation plugin’s editor owns its text — anything written here is overwritten by its next completed job. Edit it there, or switch that record to the WordPress editor first (Language → Translate this Document → WordPress Editor).',
					(int) $content_id
				)
			);
		}

		// Resolved BEFORE anything is written, then applied after. The thumbnail needs an
		// existing record, but its checks do not — and validating late meant a refused image
		// left a created record behind while the call reported failure.
		$featured = array_key_exists( 'featuredImage', $args ) ? self::validated_featured_image( $args['featuredImage'], $type ) : null;

		// Absent = where it is now. The parent it already has is not re-judged: a call that
		// changes nothing about the tree must not fail because that parent was trashed since.
		$parent = (int) $record->post_parent;
		if ( array_key_exists( 'parent', $args ) && (string) $args['parent'] !== (string) $parent ) {
			$own    = Translation::live_language_of( $content_id, $type );
			$parent = ContentParent::resolve( $args['parent'], $type, $content_id, $own );
		}

		// assert_mode() has already ruled out everything else; "edit" arrives here as a body
		// that was resolved from the stored one, so it is written exactly like a replace.
		$replace = in_array( (string) ( $args['mode'] ?? '' ), [ 'replace', 'edit' ], true );
		$content = $replace
			? $markup
			// Adding NOTHING leaves the record untouched — a call that only meant to change
			// a title or a status must not push two blank lines onto the content each time.
			: ( $markup === '' ? (string) $record->post_content : rtrim( (string) $record->post_content ) . "\n\n" . $markup );

		// wp_slash(): BlockEntry puts wp_json_encode()'d attributes inside the block
		// delimiter, and JSON escapes a quote as \" — wp_update_post() then wp_unslash()es
		// that backslash away, leaving {"label":"say "hi""}, which no longer parses and
		// takes the block's whole attribute set with it. The appended existing content is
		// unslashed too (get_post() returns it that way), so slash the joined string.
		$update = [ 'ID' => $content_id, 'post_content' => wp_slash( $content ) ];
		if ( $parent !== (int) $record->post_parent ) {
			$update['post_parent'] = $parent;
		}
		if ( array_key_exists( 'menuOrder', $args ) ) {
			$update['menu_order'] = ContentParent::menu_order( $args['menuOrder'] );
		}
		if ( isset( $args['title'] ) && trim( (string) $args['title'] ) !== '' ) {
			$update['post_title'] = trim( (string) $args['title'] );
		}
		$slug = self::updated_slug( $record, $args, $parent );
		if ( $slug !== null ) {
			$update['post_name'] = $slug;
		}
		// Absent = keep. An empty string IS a value here ("clear the summary"), which is why
		// this tests for the key and not for truthiness.
		if ( array_key_exists( 'excerpt', $args ) ) {
			$update['post_excerpt'] = wp_slash( self::clean_excerpt( $args['excerpt'], $type ) );
		}

		if ( $featured !== null ) {
			$update['_thumbnail_id'] = $featured === 0 ? -1 : $featured;
		}

		$result = wp_update_post( $update, true );
		if ( is_wp_error( $result ) ) {
			throw new McpAbilityException( 'Could not update the record: ' . esc_html( $result->get_error_message() ) );
		}
		self::assert_featured_image( $content_id, $featured );
		if ( $featured !== null ) {
			self::reindex_versions( $content_id, $type );
		}

		return $content_id;
	}

	private static function reindex_versions( int $content_id, string $type ): void {
		$bridge = Translation::bridge();
		if ( ! $bridge->is_original( $content_id, $type ) ) {
			return;
		}
		foreach ( $bridge->visible_versions( $content_id, $type ) as $version ) {
			if ( (int) $version !== $content_id ) {
				Seo::bridge()->reindex_post( (int) $version );
			}
		}
	}

	/**
	 * The slug to store on an update, or null to leave it alone.
	 *
	 * Resolved HERE, not left to WordPress, whenever the address changes — a new slug, OR a new
	 * parent: a slug is unique among its siblings, so a record moved beside one that already
	 * has its slug needs another. wp_update_post() does that for a published record only; a
	 * draft kept the clashing slug, reported it as stored, and was renamed "-2" at publish.
	 *
	 * sanitize_title() rather than the raw string: WordPress sanitises it on save anyway, and the
	 * caller should be told the slug it GOT. An empty result is dropped rather than written — a
	 * record with no slug gets one generated from its id, which is a URL nobody meant.
	 */
	private static function updated_slug( \WP_Post $record, array $args, int $parent ): ?string {
		$current = (string) $record->post_name;
		$wanted  = array_key_exists( 'slug', $args ) ? sanitize_title( (string) $args['slug'] ) : '';
		$moved   = $parent !== (int) $record->post_parent;

		$slug = $wanted !== '' ? $wanted : $current;
		if ( $slug === '' || ( $slug === $current && ! $moved ) ) {
			return null;
		}

		// 'publish', not the record's status: see create().
		return wp_unique_post_slug( $slug, (int) $record->ID, 'publish', (string) $record->post_type, $parent );
	}

	/** CREATE a new page/post — always a draft. Publishing is set-content-status (see update()). */
	private function create( array $args, string $markup, string $language ): int {
		$bridge    = Translation::bridge();
		$title     = isset( $args['title'] ) && trim( (string) $args['title'] ) !== '' ? trim( (string) $args['title'] ) : 'Untitled';
		$post_type = isset( $args['postType'] ) ? sanitize_key( (string) $args['postType'] ) : 'page';
		if ( $post_type === '' ) {
			$post_type = 'page';
		}
		self::assert_writable( $post_type );
		// Checked before the insert, like everything below: a refused image or parent must not
		// leave a created record behind while the call reports failure.
		$featured = array_key_exists( 'featuredImage', $args ) ? self::validated_featured_image( $args['featuredImage'], $post_type ) : null;
		// Judged in the language the new record will land in: the one asked for, or the site's own.
		$parent = array_key_exists( 'parent', $args )
			? ContentParent::resolve( $args['parent'], $post_type, 0, $language !== '' ? $language : $bridge->default_language() )
			: 0;

		$insert = [
			'post_type'    => $post_type,
			'post_title'   => $title,
			'post_status'  => 'draft',
			'post_content' => wp_slash( $markup ), // see update()
			'post_parent'  => $parent,
			'menu_order'   => array_key_exists( 'menuOrder', $args ) ? ContentParent::menu_order( $args['menuOrder'] ) : 0,
		];
		if ( array_key_exists( 'excerpt', $args ) ) {
			$insert['post_excerpt'] = wp_slash( self::clean_excerpt( $args['excerpt'], $post_type ) );
		}
		// Given = the address is a decision; omitted = WordPress derives it from the title.
		// Worth giving whenever the title is not Latin: sanitize_title() keeps Cyrillic and
		// diacritics, and the URL then reaches the reader percent-encoded.
		if ( array_key_exists( 'slug', $args ) ) {
			$wanted = sanitize_title( (string) $args['slug'] );
			if ( $wanted !== '' ) {
				// Resolved HERE, not left to WordPress. wp_unique_post_slug() returns a draft's
				// slug untouched, and draft is this tool's default status — so a colliding slug
				// was stored verbatim, reported as stored, and only renamed to "-2" later, at
				// publish. Every URL the caller derived from the answer, linked from a menu or
				// wrote into an SEO record, pointed at somebody else's page.
				// 'publish', not the real status. wp_unique_post_slug() RETURNS THE SLUG UNTOUCHED
				// for draft, pending and auto-draft — so passing the real status made this line the
				// very no-op it was added to prevent. Asking as if publishing resolves the collision
				// now, which is when the caller is reading the address off the answer. Among the
				// PARENT's children: siblings share an address space, cousins do not.
				$insert['post_name'] = wp_unique_post_slug( $wanted, 0, 'publish', $post_type, $parent );
			}
		}

		if ( $featured !== null && $featured > 0 ) {
			$insert['_thumbnail_id'] = $featured;
		}

		$result = wp_insert_post( $insert, true );
		if ( is_wp_error( $result ) ) {
			throw new McpAbilityException( 'Could not create the record: ' . esc_html( $result->get_error_message() ) );
		}
		$content_id = (int) $result;
		self::assert_featured_image( $content_id, $featured );

		if ( $language !== '' ) {
			// A new original in a language of its own — a Polish landing page that is nobody's
			// translation. Without this it would be filed under the site's default language
			// and show up in the wrong place. Versions are not made here: translate-content.
			$bridge->set_language( $content_id, $language, $post_type );
		}

		return $content_id;
	}

	/**
	 * What the caller is told — every field READ BACK from WordPress, never echoed from the
	 * input: an excerpt WordPress rejected, a slug it suffixed or a thumbnail that never landed
	 * must not come back looking like it did.
	 *
	 * @param list<array<string,mixed>> $inserted
	 * @return array<string,mixed>
	 */
	private static function answer( int $content_id, array $inserted ): array {
		$bridge   = Translation::bridge();
		$status   = (string) get_post_status( $content_id );
		$thumb_id = (int) get_post_thumbnail_id( $content_id );

		// Says where the record actually LANDED, which is the site's default language when none
		// was asked for. '' on a single-language site, where the key is then left out of the
		// answer entirely rather than reported as an empty one.
		$language_of = Translation::live_language_of( $content_id );

		$out = [
			'contentId' => $content_id,
			'status'    => $status,
			'url'       => $bridge->permalink( $content_id, $language_of ),
			// The slug as STORED: WordPress sanitises what was asked for, and appends -2 when the
			// address was already taken. A caller that assumed its own string would report a URL
			// that does not exist.
			'slug'      => (string) get_post_field( 'post_name', $content_id ),
		] + self::slug_when_published( $content_id ) + [
			'parent'    => (int) get_post_field( 'post_parent', $content_id ),
			'menuOrder' => (int) get_post_field( 'menu_order', $content_id ),
			'editUrl'   => (string) get_edit_post_link( $content_id, 'raw' ),
			'blocks'    => $inserted,
			'excerpt'       => (string) get_post_field( 'post_excerpt', $content_id ),
			'featuredImage' => [
				'id'  => $thumb_id,
				'url' => $thumb_id > 0 ? (string) wp_get_attachment_image_url( $thumb_id, 'large' ) : '',
			],
			'note'      => $status === 'publish'
				? 'Published — open `url` in your browser tools and verify it.'
				: 'A draft — its URL is not publicly reachable. Check it with preview {id}; make it public with set-content-status once the user agrees.',
		];
		$out['note'] .= self::$image_refused;

		if ( $language_of !== '' ) {
			$out['language'] = $language_of;
		}

		return $out;
	}

	/**
	 * mode "edit" → the body after the ops, as entries the normal write path serialises.
	 *
	 * Only for an existing record, never together with `blocks` (two sources for one body), and
	 * pinned to the hash get-content reported when the caller passes one.
	 *
	 * @return list<array<string,mixed>>
	 */
	private static function edited_entries( array $args ): array {
		$content_id = isset( $args['contentId'] ) ? (int) $args['contentId'] : 0;
		if ( $content_id <= 0 ) {
			throw new McpAbilityException( 'mode "edit" changes an existing record — pass its contentId (list-content).' );
		}
		if ( array_key_exists( 'blocks', $args ) ) {
			throw new McpAbilityException( 'mode "edit" takes `edits`, not `blocks` — send blocks with mode "replace" instead.' );
		}
		$record = get_post( $content_id );
		if ( ! $record instanceof \WP_Post || ! is_post_type_viewable( $record->post_type ) ) {
			throw new McpAbilityException( sprintf( 'No editable content with id %d — see list-content.', (int) $content_id ) );
		}
		// Every record gate BEFORE the body is read, so a record this tool would refuse never
		// gets as far as reporting its hash.
		self::assert_writable( $record->post_type );
		if ( ! current_user_can( 'edit_post', $content_id ) ) {
			throw new McpAbilityException( sprintf( 'You cannot edit content %d on this site.', (int) $content_id ) );
		}

		$current = ContentEdits::current( $content_id );
		if ( isset( $args['expectedHash'] ) && (string) $args['expectedHash'] !== TextEdits::hash( $current ) ) {
			throw new McpAbilityException(
				sprintf( 'Content %d changed since you read it (current hash %s). Nothing was saved — get-content again and redo the edits on what is there now.', (int) $content_id, esc_html( TextEdits::hash( $current ) ) ),
				[],
				'content_changed'
			);
		}

		return ContentEdits::apply( $current, $args['edits'] ?? null );
	}

	/**
	 * Can this site hold block content of that type, and may this user write it?
	 *
	 * Three separate questions, because each fails differently and only the first is obvious:
	 *   - REGISTERED. A typo, or a type someone deleted.
	 *   - PUBLIC. Non-public types are plumbing (revisions, menu items, our own records), and
	 *     writing block markup into one corrupts something the user never asked about.
	 *   - SUPPORTS THE EDITOR. This is the silent one: a type registered without 'editor' has
	 *     no post_content anyone can see or edit, so the blocks would be stored, never
	 *     rendered, and never explained.
	 *   - PERMITTED. The capability for creating this type — a role plugin may withhold it even
	 *     from an administrator, and WordPress would then refuse the insert with a bare 0.
	 *   - NOT A COMMERCE RECORD. See {@see COMMERCE_TYPES}.
	 *
	 * @throws McpAbilityException
	 */
	private static function assert_writable( string $post_type ): void {
		// A WooCommerce product is a public, editor-supporting type, so every check below would
		// wave it through — and the result would be a product with a body and no price, stock,
		// SKU or tax class, i.e. a broken shop row that looks like a finished page. WooCommerce owns
		// those fields; this refusal routes a caller there instead of letting them build half a
		// product here.
		if ( in_array( $post_type, self::COMMERCE_TYPES, true ) ) {
			throw new McpAbilityException(
				sprintf(
					'"%s" is a WooCommerce record and is not written here — it needs price, stock and tax data this tool does not set. Edit it in WooCommerce.',
					esc_html( $post_type )
				),
				[ 'postType' => $post_type ], // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- error data array, not output.
				'commerce_type_refused'
			);
		}

		$object = get_post_type_object( $post_type );
		if ( $object === null ) {
			throw new McpAbilityException(
				sprintf( 'Post type "%s" is not registered on this site — see list-post-types.', esc_html( $post_type ) )
			);
		}
		if ( ! is_post_type_viewable( $post_type ) ) {
			throw new McpAbilityException(
				sprintf( 'Post type "%s" is not public, so its records have no front end to build — pick a public type (list-post-types).', esc_html( $post_type ) )
			);
		}
		if ( ! post_type_supports( $post_type, 'editor' ) ) {
			throw new McpAbilityException(
				sprintf(
					'Post type "%s" does not support the editor, so it has no content area for blocks — use a type that has it (list-post-types).',
					esc_html( $post_type )
				)
			);
		}
		$create = $object->cap->create_posts ?? 'edit_posts';
		if ( ! current_user_can( $create ) ) {
			throw new McpAbilityException( sprintf( 'You are not allowed to create "%s" content on this site.', esc_html( $post_type ) ) );
		}
	}

	/**
	 * An excerpt is PLAIN TEXT, not a small post body: WordPress prints it in card grids,
	 * archives, search results and feeds, where markup would leak into someone else's layout.
	 * So tags come out and entities/whitespace are normalised — and an empty result stays
	 * empty, because clearing the summary is a legitimate instruction.
	 *
	 * REFUSED when the post type does not declare `excerpt` support, because then the editor
	 * shows no Excerpt panel: the summary would be readable by code (theme templates, feeds,
	 * search) and editable by nobody. A field the site owner cannot see is a field they cannot
	 * correct, so writing one is worse than not having it — the honest answer is to say the
	 * type has no excerpt and let the caller drop the idea.
	 * Core `page` is the type this actually catches: it supports thumbnails but not excerpts.
	 *
	 * @param mixed $raw
	 */
	private static function clean_excerpt( $raw, string $post_type ): string {
		if ( ! is_string( $raw ) ) {
			throw new McpAbilityException( 'excerpt must be a string ("" clears it).' );
		}
		if ( ! post_type_supports( $post_type, 'excerpt' ) ) {
			throw new McpAbilityException(
				sprintf(
					'Post type "%s" does not support excerpts, so the editor would show no field for one and nobody could edit it. Leave the summary out.',
					esc_html( $post_type )
				),
				[ 'postType' => $post_type ], // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- error data array, not output.
				'excerpt_unsupported'
			);
		}

		return trim( sanitize_textarea_field( $raw ) );
	}

	/**
	 * Everything about a featured image that can be judged WITHOUT the record existing yet —
	 * run before the write, so a refusal never leaves a created record behind.
	 *
	 * The type's `thumbnail` support is checked here because without it the meta would be
	 * stored and read by nothing: no editor panel, no theme output. A field nobody can see is
	 * one nobody can correct.
	 *
	 * The MIME check is ours on purpose. WordPress generates JPEG previews for PDFs, so a PDF
	 * passes core's own image test — and a "featured image" that is really a document renders
	 * as a broken card in every listing that trusts it.
	 *
	 * `0` is accepted as "remove the featured image", which is the only way to undo one here.
	 *
	 * @param mixed $raw
	 * @return int Attachment id, or 0 meaning "remove".
	 */
	private static function validated_featured_image( $raw, string $post_type ): int {
		if ( ! is_int( $raw ) || $raw < 0 ) {
			throw new McpAbilityException( 'featuredImage must be an attachment id (0 removes it) — see upload-media / list-media.' );
		}
		$attachment_id = $raw;

		if ( ! post_type_supports( $post_type, 'thumbnail' ) ) {
			throw new McpAbilityException(
				sprintf(
					'Post type "%s" does not support featured images, so setting one would store meta nothing reads. Leave it out.',
					esc_html( $post_type )
				),
				[ 'postType' => $post_type ], // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- error data array, not output.
				'thumbnail_unsupported'
			);
		}

		if ( ! current_theme_supports( 'post-thumbnails', $post_type ) ) {
			throw new McpAbilityException(
				sprintf(
					'The active theme does not support featured images for "%s", so WordPress would not store one — the theme needs add_theme_support( "post-thumbnails" ).',
					esc_html( $post_type )
				),
				[ 'postType' => $post_type ], // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- error data array, not output.
				'thumbnail_unsupported_by_theme'
			);
		}

		if ( $attachment_id === 0 ) {
			return 0;
		}

		$attachment = get_post( $attachment_id );
		if ( ! $attachment instanceof \WP_Post || $attachment->post_type !== 'attachment' ) {
			throw new McpAbilityException(
				sprintf( 'featuredImage %d is not a media item on this site — upload it with upload-media or find it with list-media.', (int) $attachment_id )
			);
		}
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			throw new McpAbilityException(
				sprintf( 'featuredImage %d is a %s, not an image — a featured image must be one.', (int) $attachment_id, esc_html( (string) get_post_mime_type( $attachment_id ) ) )
			);
		}

		return $attachment_id;
	}

	private static function assert_featured_image( int $content_id, ?int $featured ): void {
		if ( $featured !== null && $featured > 0 && (int) get_post_thumbnail_id( $content_id ) !== $featured ) {
			self::$image_refused = sprintf( ' WordPress did not set image %d as the featured image (WordPress could not get an address for that image), so the record was saved without it — featuredImage shows what it has now.', (int) $featured );
		}
	}

	/**
	 * The slug a record without one would get if it were published now — what the editor shows as
	 * a draft's URL. WordPress gives a draft no slug until then, so without this a new draft has
	 * no address to report. Nothing is stored.
	 *
	 * @return array{slugWhenPublished?:string} Empty for a record that has its slug.
	 */
	public static function slug_when_published( int $content_id ): array {
		$post = get_post( $content_id );
		if ( ! $post instanceof \WP_Post || (string) $post->post_name !== '' || ! is_post_type_viewable( $post->post_type ) ) {
			return [];
		}
		if ( ! function_exists( 'get_sample_permalink' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}
		$slug = (string) ( get_sample_permalink( $content_id )[1] ?? '' );
		return $slug === '' ? [] : [ 'slugWhenPublished' => $slug ];
	}
}
