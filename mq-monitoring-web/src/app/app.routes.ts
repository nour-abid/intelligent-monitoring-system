import { Routes } from '@angular/router';
import { authGuard } from './core/guards/auth.guard';
import { adminGuard } from './core/guards/admin.guard';

export const routes: Routes = [
  {
    path: 'login',
    loadComponent: () =>
      import('./features/auth/login/login.component').then(
        (m) => m.LoginComponent
      ),
    title: 'Sign In — MQ Monitoring',
  },
  {
    path: 'forgot-password',
    loadComponent: () =>
      import('./features/auth/forgot-password/forgot-password.component').then(
        (m) => m.ForgotPasswordComponent
      ),
    title: 'Reset Password — MQ Monitoring',
  },
  {
    path: 'verify-otp',
    loadComponent: () =>
      import('./features/auth/verify-otp/verify-otp.component').then(
        (m) => m.VerifyOtpComponent
      ),
    title: 'Enter Code — MQ Monitoring',
  },
  {
    path: 'reset-password',
    loadComponent: () =>
      import('./features/auth/reset-password/reset-password.component').then(
        (m) => m.ResetPasswordComponent
      ),
    title: 'New Password — MQ Monitoring',
  },
  {
    path: '',
    loadComponent: () =>
      import('./shared/layout/shell/shell.component').then(
        (m) => m.ShellComponent
      ),
    children: [
      {
        path: '',
        redirectTo: 'surveillance',
        pathMatch: 'full',
      },
      {
        path: 'surveillance',
        canActivate: [authGuard],
        loadComponent: () =>
          import(
            './features/surveillance/pages/dashboard/dashboard.component'
          ).then((m) => m.DashboardComponent),
        title: 'Surveillance Dashboard — MQ Monitoring',
      },
      {
        path: 'surveillance/identity/:name',
        canActivate: [authGuard],
        loadComponent: () =>
          import(
            './features/surveillance/pages/identity-detail/identity-detail.component'
          ).then((m) => m.IdentityDetailComponent),
        title: 'Identity Detail — MQ Monitoring',
      },
      {
        path: 'admin/users',
        canActivate: [authGuard, adminGuard],
        loadComponent: () =>
          import('./features/admin/users/list/users.component').then(
            (m) => m.UsersComponent
          ),
        title: 'User Management — MQ Monitoring',
      },
      {
        path: '**',
        redirectTo: 'surveillance',
      },
    ],
  },
];
