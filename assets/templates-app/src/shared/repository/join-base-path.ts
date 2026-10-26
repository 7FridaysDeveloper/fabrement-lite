/**
 * Concatenate API base URL and path (handles extra/missing slashes).
 */
export function joinBasePath(base: string, path: string): string {
  const normalizedBase = base.trim().replace(/\/+$/, '');
  const normalizedPath = path.trim().replace(/^\/+/, '');
  if (normalizedPath === '') {
    return normalizedBase;
  }
  return `${normalizedBase}/${normalizedPath}`;
}
