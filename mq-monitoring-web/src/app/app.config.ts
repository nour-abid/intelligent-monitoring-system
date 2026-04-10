import {
  APP_INITIALIZER,
  ApplicationConfig,
  inject,
  provideZoneChangeDetection,
} from '@angular/core';
import { provideRouter, withComponentInputBinding } from '@angular/router';
import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { provideAnimationsAsync } from '@angular/platform-browser/animations/async';
import { provideCharts, withDefaultRegisterables } from 'ng2-charts';
import { routes } from './app.routes';
import { apiBaseUrlInterceptor } from './core/interceptors/api-base-url.interceptor';
import { authInterceptor } from './core/interceptors/auth.interceptor';
import { AuthService } from './core/services/auth.service';
import { ThemeService } from './core/services/theme.service';

export const appConfig: ApplicationConfig = {
  providers: [
    provideZoneChangeDetection({ eventCoalescing: true }),
    provideRouter(routes, withComponentInputBinding()),
    provideHttpClient(withInterceptors([apiBaseUrlInterceptor, authInterceptor])),
    provideAnimationsAsync(),
    provideCharts(withDefaultRegisterables()),
    {
      provide: APP_INITIALIZER,
      useFactory: () => {
        const theme = inject(ThemeService);
        return () => theme.init();
      },
      multi: true,
    },
    {
      provide: APP_INITIALIZER,
      useFactory: () => {
        const auth = inject(AuthService);
        return () => auth.bootstrap();
      },
      multi: true,
    },
  ],
};
