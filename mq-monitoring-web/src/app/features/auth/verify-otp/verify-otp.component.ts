import { Component, inject, OnInit, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { AuthService } from '../../../core/services/auth.service';
import { TimeoutError } from 'rxjs';

@Component({
  selector: 'app-verify-otp',
  standalone: true,
  imports: [FormsModule, RouterLink],
  templateUrl: './verify-otp.component.html',
  styleUrl: './verify-otp.component.scss',
})
export class VerifyOtpComponent implements OnInit {
  private readonly auth   = inject(AuthService);
  private readonly router = inject(Router);
  private readonly route  = inject(ActivatedRoute);

  email   = '';
  otp     = '';
  loading = signal(false);
  error   = signal<string | null>(null);

  ngOnInit(): void {
    const email = this.route.snapshot.queryParamMap.get('email') ?? '';
    if (!email) {
      this.router.navigate(['/forgot-password']);
      return;
    }
    this.email = email;
  }

  async onSubmit(): Promise<void> {
    if (this.loading()) return;

    this.error.set(null);
    this.loading.set(true);

    try {
      await this.auth.verifyOtp(this.email, this.otp);
      this.router.navigate(['/reset-password'], {
        queryParams: { email: this.email, otp: this.otp },
      });
    } catch (err) {
      this.error.set(err instanceof TimeoutError
        ? 'Request timed out. Please try again.'
        : 'Invalid or expired code. Please try again.');
    } finally {
      this.loading.set(false);
    }
  }
}
