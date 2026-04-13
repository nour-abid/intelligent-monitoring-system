import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { AuthService } from '../services/auth.service';

/**
 * viewerOnlyGuard
 *
 * Allows only viewers to proceed; redirects admin/superviseur to /surveillance
 */
export const viewerOnlyGuard: CanActivateFn = (route, state) => {
  const auth   = inject(AuthService);
  const router = inject(Router);

  const user = auth.user();
  if (user?.role === 'viewer') {
    return true;
  }

  // Non-viewer authenticated users go to main surveillance dashboard
  if (user) {
    return router.createUrlTree(['/surveillance']);
  }

  // Not authenticated at all
  return router.createUrlTree(['/login'], { queryParams: { returnUrl: state.url } });
};
