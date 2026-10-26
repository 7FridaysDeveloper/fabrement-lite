import type { ZodType } from 'zod';
import { parseWithSchema } from './parse-with-schema';

/**
 * Read `Response` body as JSON and validate with `schema`.
 * Use after `fetch` when `response.ok` is true (or when error body is also JSON + schema).
 */
export async function parseJsonResponse<T>(
  response: Response,
  schema: ZodType<T>,
  context?: string,
): Promise<T> {
  const text = await response.text();
  let data: unknown;
  if (text.trim() === '') {
    data = undefined;
  } else {
    try {
      data = JSON.parse(text) as unknown;
    } catch {
      throw new Error(context ? `${context}: response is not valid JSON` : 'Response is not valid JSON');
    }
  }
  return parseWithSchema(schema, data, context);
}
