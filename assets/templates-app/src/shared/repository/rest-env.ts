/** Where this site's REST API lives. */
export class RestEnv {
  /** WordPress REST root without trailing slash: the admin page's own origin + `/wp-json`. */
  static get wpRestBaseUrl(): string {
    return `${window.location.origin}/wp-json`;
  }
}
