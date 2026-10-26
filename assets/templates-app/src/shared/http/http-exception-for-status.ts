import {
  BadRequestException,
  GatewayTimeoutException,
  HttpException,
  NotFoundException,
} from './exceptions';

/**
 * Maps HTTP status to a typed exception (400, 404 and 504 get their own subclass,
 * everything else is a plain {@link HttpException}) so callers can branch on the error type.
 */
export function httpExceptionForStatus(
  message: string,
  statusCode: number,
  errorBody?: unknown,
): HttpException {
  switch (statusCode) {
    case 400:
      return new BadRequestException(message, errorBody);
    case 404:
      return new NotFoundException(message, errorBody);
    case 504:
      return new GatewayTimeoutException(message, errorBody);
    default:
      return new HttpException(message, statusCode, errorBody);
  }
}
