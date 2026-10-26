/**
 * wp-admin URLs the Templates screen opens in an iframe. Relative, as WordPress writes its own
 * admin links: the screen is a wp-admin page, so the browser resolves them against whatever
 * address the admin is open at (subdirectory, renamed admin, another host).
 */

/** Gutenberg editor for one post: a template, or one of its language versions. */
export function postEditorUrl(postId: number): string {
  return `post.php?post=${postId}&action=edit`;
}
