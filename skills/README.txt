Fabrement Lite local MCP instructions

These files are the runtime source of truth for get-skill-doc. They ship with
the plugin ZIP and are read as text: nothing is executed or downloaded.

index.json lists each allowed document ID and its available variants.
base/ contains 12 contracts for Lite's content and data tools. translation/
contains the two WPML contracts, available only on multilingual sites. The plugin
selects the variant when the site is multilingual. Do not replace a missing
translation file with a base file silently.

There are 14 document IDs. The XML files here are editable source, not a
build output.

blocks/ holds hand-written notes for single blocks, one file per block named
like Gutenberg's fixtures (core/navigation -> core__navigation.xml). They are
not get-skill-doc documents: get-block-source returns a block's file as its
"guide" field. Check each example in the WordPress editor before adding it.
