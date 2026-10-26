import { httpExceptionForStatus } from './http-exception-for-status';
import { decodeEntities } from '../text/decode-entities';

/**
 * If `response.ok` is false, read the body (JSON `message` if present, e.g. WP REST) and throw
 * {@link BadRequestException} / {@link NotFoundException} / {@link GatewayTimeoutException} / {@link HttpException}
 * so callers can branch on error type.
 */
export async function throwIfNotOk(response: Response, context?: string): Promise<Response> {
  if (response.ok) {
    return response;
  }

  let errorBody: unknown;
  let message = response.statusText || `HTTP ${response.status}`;

  try {
    const text = await response.text();
    errorBody = text;
    if (text.trim() !== '') {
      try {
        const json = JSON.parse(text) as { message?: string };
        errorBody = json;
        if (typeof json.message === 'string' && json.message !== '') {
          message = decodeEntities(json.message);
        } else if (text.length <= 500) {
          message = text;
        }
      } catch {
        if (text.length <= 500) {
          message = text;
        }
      }
    }
  } catch {
    // leave defaults
  }

  const prefix = context ? `${context}: ` : '';
  throw httpExceptionForStatus(`${prefix}${message}`, response.status, errorBody);
}
