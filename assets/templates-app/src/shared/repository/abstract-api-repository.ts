import { RestEnv } from './rest-env';
import { WP_FABREMENT_REST_NAMESPACE } from './wp-fabrement-rest-paths';
import { WpApiFetch } from './wp-api-fetch';

/**
 * Base repository: use {@link wp} for HTTP (e.g. `this.wp.post`).
 * `wp` uses the plugin REST namespace {@link WP_FABREMENT_REST_NAMESPACE}; pass route segments like `site-templates/pages`.
 */
export abstract class AbstractApiRepository {
  protected readonly wp: WpApiFetch;

  protected constructor() {
    this.wp = new WpApiFetch(RestEnv.wpRestBaseUrl, WP_FABREMENT_REST_NAMESPACE);
  }
}
