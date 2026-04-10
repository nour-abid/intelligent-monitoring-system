import { Component } from '@angular/core';
import { RouterOutlet } from '@angular/router';
import { TopbarComponent } from '../../components/topbar/topbar.component';
import { AlertToastComponent } from '../../components/alert-toast/alert-toast.component';

@Component({
  selector: 'app-shell',
  standalone: true,
  imports: [RouterOutlet, TopbarComponent, AlertToastComponent],
  templateUrl: './shell.component.html',
  styleUrl: './shell.component.scss',
})
export class ShellComponent {}
