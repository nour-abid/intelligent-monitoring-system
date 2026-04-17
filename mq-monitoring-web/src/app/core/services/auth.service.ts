import { inject, Injectable, signal } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Router } from '@angular/router';
import { firstValueFrom, timeout } from 'rxjs';
import { User } from '../models/auth.model';

const TOKEN_KEY = 'mq_auth_token';

@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly http   = inject(HttpClient);
  private readonly router = inject(Router);

  private readonly _user  = signal<User | null>(null);
  private readonly _token = signal<string | null>(localStorage.getItem(TOKEN_KEY));

  readonly user       = this._user.asReadonly();
  readonly token      = this._token.asReadonly();
  readonly isLoggedIn = () => this._token() !== null;

  // -----------------------------------------------------------------------
  // Called from APP_INITIALIZER — restores session from stored token.
  // Always resolves (never rejects) so app startup is never blocked.
  // -----------------------------------------------------------------------
  bootstrap(): Promise<void> {
    const stored = this._token();
    if (!stored) return Promise.resolve();

    return new Promise<void>((resolve) => {
      this.http.get<User>('/api/auth/me').subscribe({
        next:  (user) => { this._user.set(user); resolve(); },
        error: ()     => { this.clearState();     resolve(); },
      });
    });
  }

  // -----------------------------------------------------------------------
  // Login — POST /api/auth/login
  // Returns the authenticated User on success; throws on failure.
  // -----------------------------------------------------------------------
  async login(email: string, password: string): Promise<User> {
    const res = await firstValueFrom(
      this.http.post<{ token: string; user: User }>('/api/auth/login', { email, password })
    );
    this.setState(res.token, res.user);
    return res.user;
  }

  // -----------------------------------------------------------------------
  // Logout — clears state and redirects to /login.
  // -----------------------------------------------------------------------
  logout(): void {
    this.clearState();
    this.router.navigate(['/login']);
  }

  // -----------------------------------------------------------------------
  // Forgot / reset password (OTP flow)
  // -----------------------------------------------------------------------
  async forgotPassword(email: string): Promise<void> {
    await firstValueFrom(
      this.http.post('/api/auth/forgot-password', { email }).pipe(timeout(15000))
    );
  }

  async verifyOtp(email: string, otp: string): Promise<void> {
    await firstValueFrom(
      this.http.post('/api/auth/verify-otp', { email, otp }).pipe(timeout(15000))
    );
  }

  async resetPassword(
    email: string,
    otp: string,
    password: string,
    password_confirmation: string,
  ): Promise<void> {
    await firstValueFrom(
      this.http.post('/api/auth/reset-password', {
        email,
        otp,
        password,
        password_confirmation,
      })
    );
  }

  // -----------------------------------------------------------------------
  // Internal helpers
  // -----------------------------------------------------------------------
  private setState(token: string, user: User): void {
    localStorage.setItem(TOKEN_KEY, token);
    this._token.set(token);
    this._user.set(user);
  }

  clearState(): void {
    localStorage.removeItem(TOKEN_KEY);
    this._token.set(null);
    this._user.set(null);
  }
}
