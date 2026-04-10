import { HttpInterceptorFn } from '@angular/common/http';
import { environment } from '../../../environments/environment';

/**
 * Prepends environment.apiBaseUrl to every request whose URL starts with /api.
 *
 * Development (proxy): apiBaseUrl is '' → relative URL passes through,
 *   Angular dev-server proxy.conf.json forwards it to localhost:8080.
 *
 * Production: apiBaseUrl is the absolute Laravel API origin → the interceptor
 *   rewrites "/api/..." to "https://api.example.com/api/...".
 */
export const apiBaseUrlInterceptor: HttpInterceptorFn = (req, next) => {
  const base = environment.apiBaseUrl;
  if (!base || !req.url) {
    return next(req);
  }
  return next(req.clone({ url: `${base}${req.url}` }));
};
