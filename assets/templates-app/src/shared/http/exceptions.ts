/** Base class for HTTP-layer errors (status + optional parsed body). */
export class HttpException extends Error {
  readonly statusCode: number;
  readonly errorBody: unknown;

  constructor(message: string, statusCode: number, errorBody?: unknown, cause?: unknown) {
    super(message, cause instanceof Error ? { cause } : undefined);
    this.name = new.target.name;
    this.statusCode = statusCode;
    this.errorBody = errorBody;
  }
}

export class BadRequestException extends HttpException {
  constructor(message: string, errorBody?: unknown, cause?: unknown) {
    super(message, 400, errorBody, cause);
  }
}

export class NotFoundException extends HttpException {
  constructor(message: string, errorBody?: unknown, cause?: unknown) {
    super(message, 404, errorBody, cause);
  }
}

export class GatewayTimeoutException extends HttpException {
  constructor(message: string, errorBody?: unknown, cause?: unknown) {
    super(message, 504, errorBody, cause);
  }
}
