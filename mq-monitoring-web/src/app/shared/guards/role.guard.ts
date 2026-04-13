import { Injectable } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { AuthService } from '@app/shared/auth/auth.service';
import { inject } from '@angular/core';

/**
 * viewerOnlyGuard
 *
 * Route guard that allows only users with the 'viewer' role to access protected routes.
 * Redirects admin and superviseur users to their respective dashboards.
 *
 * Usage in routing module:
 *   {
 *     path: 'surveillance/my-insights',
 *     component: ViewerDashboardComponent,
 *     canActivate: [viewerOnlyGuard],
 *   }
 */
export const viewerOnlyGuard: CanActivateFn = () => {
  const authService = inject(AuthService);
  const router = inject(Router);

  const user = authService.user();

  if (user?.role === 'viewer') {
    return true;
  }

  // Redirect non-viewers to main dashboard
  console.warn('Access denied: User with role', user?.role, 'cannot access viewer dashboard');
  router.navigate(['/surveillance']);

  return false;
};

/**
 * adminOrSuperviseurGuard
 *
 * Route guard that allows only users with 'admin' or 'superviseur' roles.
 * Redirects viewers to the viewer dashboard.
 *
 * Usage in routing module:
 *   {
 *     path: 'surveillance',
 *     component: DashboardComponent,
 *     canActivate: [adminOrSuperviseurGuard],
 *   }
 */
export const adminOrSuperviseurGuard: CanActivateFn = () => {
  const authService = inject(AuthService);
  const router = inject(Router);

  const user = authService.user();

  if (user?.role === 'admin' || user?.role === 'superviseur') {
    return true;
  }

  // Redirect viewers to their dashboard
  if (user?.role === 'viewer') {
    console.info('Redirecting viewer to viewer dashboard');
    router.navigate(['/surveillance/my-insights']);
    return false;
  }

  // Fallback: no valid role
  console.error('No valid user role detected');
  router.navigate(['/login']);
  return false;
};

/**
 * authenticatedGuard (if not already defined elsewhere)
 *
 * Route guard that requires any authenticated user to proceed.
 */
export const authenticatedGuard: CanActivateFn = () => {
  const authService = inject(AuthService);
  const router = inject(Router);

  if (authService.isAuthenticated() && authService.user()) {
    return true;
  }

  console.warn('Access denied: User not authenticated');
  router.navigate(['/login']);
  return false;
};
