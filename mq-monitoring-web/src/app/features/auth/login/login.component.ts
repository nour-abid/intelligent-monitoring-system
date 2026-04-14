import { Component, inject, OnInit, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { AuthService } from '../../../core/services/auth.service';

@Component({
  selector: 'app-login',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './login.component.html',
  styleUrl: './login.component.scss',
})
export class LoginComponent implements OnInit {
  private readonly auth  = inject(AuthService);
  private readonly router = inject(Router);
  private readonly route  = inject(ActivatedRoute);

  email    = '';
  password = '';

  loading      = signal(false);
  error        = signal<string | null>(null);
  resetSuccess = signal(false);

  ngOnInit(): void {
    if (this.route.snapshot.queryParamMap.get('reset') === 'success') {
      this.resetSuccess.set(true);
    }
  }

  async onSubmit(): Promise<void> {
    if (this.loading()) return;

    this.error.set(null);
    this.loading.set(true);

    try {
      await this.auth.login(this.email, this.password);
      const returnUrl = this.route.snapshot.queryParamMap.get('returnUrl') ?? '/surveillance';
      this.router.navigateByUrl(returnUrl);
    } catch {
      this.error.set('Invalid email or password.');
    } finally {
      this.loading.set(false);
    }
  }
}
