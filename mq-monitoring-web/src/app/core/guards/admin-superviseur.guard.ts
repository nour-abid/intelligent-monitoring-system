import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { AuthService } from '../services/auth.service';

/**
 * adminSuperviseurGuard
 *
 * Allows only admin or superviseur; redirects viewers to /surveillance/my-insights
 */
export const adminSuperviseurGuard: CanActivateFn = (route, state) => {
  const auth   = inject(AuthService);
  const router = inject(Router);

  const user = auth.user();
  if (user?.role === 'admin' || user?.role === 'superviseur') {
    return true;
  }

  // Viewer authenticated users go to viewer dashboard
  if (user?.role === 'viewer') {
    return router.createUrlTree(['/surveillance/my-insights']);
  }

  // Not authenticated at all
  return router.createUrlTree(['/login'], { queryParams: { returnUrl: state.url } });
};
