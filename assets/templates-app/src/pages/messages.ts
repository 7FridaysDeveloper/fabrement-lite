/** What a failed request says on screen: the server's sentence, without the request's name. */
export const screenMessage = (error: unknown, fallback: string): string =>
  error instanceof Error && error.message !== '' ? error.message.replace(/^(site-templates|content source)[a-z -]*:\s*/i, '') : fallback;

/** "French", "French, German": names joined with the language's own list separator. */
export const listed = (names: string[]): string =>
  /* translators: the separator between the items of a list, e.g. "French, German" */
  names.join(__( ', ', 'fabrement-lite' ));
