import { inject } from '@angular/core';
import { HttpInterceptorFn, HttpErrorResponse } from '@angular/common/http';
import { tap } from 'rxjs/operators';
import { AuthService } from '../services/auth.service';

export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const auth  = inject(AuthService);
  const token = auth.token();

  const authed = token
    ? req.clone({ setHeaders: { Authorization: `Bearer ${token}` } })
    : req;

  return next(authed).pipe(
    tap({
      error: (err) => {
        // A 401 on any non-login endpoint means the stored token is invalid/expired.
        if (err instanceof HttpErrorResponse && err.status === 401 && !req.url.endsWith('/api/auth/login')) {
          auth.clearState();
        }
      },
    })
  );
};
