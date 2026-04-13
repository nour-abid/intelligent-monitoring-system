import { inject } from '@angular/core';
import { ResolveFn } from '@angular/router';
import { AuthService } from '../services/auth.service';

/**
 * Resolves the current viewer's surveillance_identity for use as the `name`
 * input on IdentityDetailComponent at the /surveillance/my-insights route.
 *
 * With withComponentInputBinding() configured in app.config.ts, the resolved
 * value is automatically bound to the component's `name` input signal.
 */
export const viewerIdentityResolver: ResolveFn<string> = () => {
  return inject(AuthService).user()?.surveillance_identity ?? '';
};
