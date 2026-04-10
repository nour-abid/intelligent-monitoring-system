import { Component, inject, OnInit, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { AuthService } from '../../../core/services/auth.service';

@Component({
  selector: 'app-reset-password',
  standalone: true,
  imports: [FormsModule, RouterLink],
  templateUrl: './reset-password.component.html',
  styleUrl: './reset-password.component.scss',
})
export class ResetPasswordComponent implements OnInit {
  private readonly auth   = inject(AuthService);
  private readonly router = inject(Router);
  private readonly route  = inject(ActivatedRoute);

  private email = '';
  private otp   = '';

  password             = '';
  passwordConfirmation = '';

  loading = signal(false);
  error   = signal<string | null>(null);

  ngOnInit(): void {
    const email = this.route.snapshot.queryParamMap.get('email') ?? '';
    const otp   = this.route.snapshot.queryParamMap.get('otp')   ?? '';

    if (!email || !otp) {
      this.router.navigate(['/forgot-password']);
      return;
    }

    this.email = email;
    this.otp   = otp;
  }

  async onSubmit(): Promise<void> {
    if (this.loading()) return;

    if (this.password !== this.passwordConfirmation) {
      this.error.set('Passwords do not match.');
      return;
    }

    this.error.set(null);
    this.loading.set(true);

    try {
      await this.auth.resetPassword(
        this.email,
        this.otp,
        this.password,
        this.passwordConfirmation,
      );
      this.router.navigate(['/login'], { queryParams: { reset: 'success' } });
    } catch {
      this.error.set('Invalid or expired code. Please restart the process.');
    } finally {
      this.loading.set(false);
    }
  }
}
