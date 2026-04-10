import { Component, inject } from '@angular/core';
import { DatePipe } from '@angular/common';
import { AlertToastService } from '../../../core/services/alert-toast.service';

@Component({
  selector: 'app-alert-toast',
  standalone: true,
  imports: [DatePipe],
  templateUrl: './alert-toast.component.html',
  styleUrl: './alert-toast.component.scss',
})
export class AlertToastComponent {
  protected readonly toastService = inject(AlertToastService);

  protected alertLabel(type: string): string {
    switch (type) {
      case 'inactive':     return 'Inactive';
      case 'phone':        return 'Using Phone';
      case 'late_arrival': return 'Late Arrival';
      default:             return type;
    }
  }
}
