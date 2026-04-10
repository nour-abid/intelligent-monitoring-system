import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { AuthService } from '../../../core/services/auth.service';

@Component({
  selector: 'app-forgot-password',
  standalone: true,
  imports: [FormsModule, RouterLink],
  templateUrl: './forgot-password.component.html',
  styleUrl: './forgot-password.component.scss',
})
export class ForgotPasswordComponent {
  private readonly auth   = inject(AuthService);
  private readonly router = inject(Router);

  email   = '';
  loading = signal(false);
  error   = signal<string | null>(null);

  async onSubmit(): Promise<void> {
    if (this.loading()) return;

    this.error.set(null);
    this.loading.set(true);

    try {
      await this.auth.forgotPassword(this.email);
      this.router.navigate(['/verify-otp'], { queryParams: { email: this.email } });
    } catch {
      this.error.set('Something went wrong. Please try again.');
    } finally {
      this.loading.set(false);
    }
  }
}
