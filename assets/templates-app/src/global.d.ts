/// <reference types="vite/client" />
export {};

declare global {
  /** Core script `wp-api-fetch`. */
  type WordPressApiFetch = (
    options: {
      path?: string;
      url?: string;
      method?: string;
      data?: unknown;
      /** When `false`, the raw `Response` is returned. */
      parse?: boolean;
    } & RequestInit,
  ) => Promise<unknown>;

  // Written out as wp.i18n.__ / wp.i18n._n by the build (vite.config.js `define`).
  function __(text: string, domain: string): string;
  function _n(single: string, plural: string, count: number, domain: string): string;
  /** Core script `wp-i18n`. */
  const wp: { i18n: { sprintf: (format: string, ...args: unknown[]) => string } };

  interface Window {
    wp?: { apiFetch?: WordPressApiFetch };
    /** Printed by TemplatesPage: what the screen needs to call this site's REST API. */
    fabrementTemplatesApp?: { restNonce?: string };
  }
}
