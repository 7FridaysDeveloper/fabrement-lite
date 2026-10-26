import type { ZodType } from 'zod';

/** Validate parsed JSON (or any value) with a Zod schema; throw on a malformed or unexpected payload. */
export function parseWithSchema<T>(
  schema: ZodType<T>,
  data: unknown,
  context?: string,
): T {
  const result = schema.safeParse(data);
  if (!result.success) {
    const prefix = context ? `${context}: ` : '';
    throw new Error(`${prefix}${result.error.message}`);
  }
  return result.data;
}
