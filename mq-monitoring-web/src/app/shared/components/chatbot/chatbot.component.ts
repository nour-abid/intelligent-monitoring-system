import {
  Component,
  computed,
  ElementRef,
  inject,
  signal,
  ViewChild,
  AfterViewChecked,
  HostListener,
  OnInit,
} from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { ChatStateService } from '../../../core/services/chat-state.service';
import { AuthService } from '../../../core/services/auth.service';

@Component({
  selector: 'app-chatbot',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './chatbot.component.html',
  styleUrl: './chatbot.component.scss',
})
export class ChatbotComponent implements AfterViewChecked, OnInit {
  readonly chat = inject(ChatStateService);
  private readonly router = inject(Router);
  private readonly auth   = inject(AuthService);

  /** True when the logged-in user is a viewer — selector is hidden for viewers. */
  readonly isViewer = computed(() => this.auth.user()?.role === 'viewer');

  mode  = signal<'closed' | 'sidebar'>('closed');
  input = signal('');
  selectorOpen = signal(false);

  readonly isSidebar = computed(() => this.mode() === 'sidebar');
  readonly isOpen    = computed(() => this.mode() !== 'closed');

  @ViewChild('messageList') private messageList!: ElementRef<HTMLElement>;
  private shouldScroll = false;

  ngOnInit(): void {
    console.log('[Chatbot] ngOnInit — role:', this.auth.user()?.role, '| isViewer:', this.isViewer());
    this.chat.ensureIdentitiesLoaded();
  }

  open(): void {
    this.mode.set('sidebar');
    console.log('[Chatbot] open — identityNames:', this.chat.identityNames(), '| isViewer:', this.isViewer());
    // Retry load if previous attempt failed or returned empty
    if (!this.chat.contextLoading() && this.chat.identityNames().length === 0) {
      this.chat.ensureIdentitiesLoaded();
    }
  }
  close(): void { this.mode.set('closed'); }

  openFullPage(): void {
    this.close();
    this.router.navigate(['/chat']);
  }

  @HostListener('document:keydown.escape')
  onEscape(): void {
    if (this.isOpen()) this.close();
  }

  send(): void {
    const text = this.input().trim();
    if (!text || this.chat.loading()) return;
    this.input.set('');
    this.chat.send(text);
    this.shouldScroll = true;
  }

  onKey(event: KeyboardEvent): void {
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault();
      this.send();
    }
  }

  toggleSelector(): void {
    this.selectorOpen.update(v => !v);
  }

  pickIdentity(identity: string): void {
    this.selectorOpen.set(false);
    if (identity !== this.chat.selectedIdentity()) {
      this.chat.switchIdentity(identity);
      this.shouldScroll = true;
    }
  }

  retryIdentities(): void {
    console.log('[Chatbot] retryIdentities triggered');
    this.chat.retryIdentities();
  }

  ngAfterViewChecked(): void {
    if (!this.messageList) return;
    const el = this.messageList.nativeElement;
    const msgs = this.chat.messages();
    const lastMsg = msgs[msgs.length - 1];
    if (this.shouldScroll || lastMsg?.loading || lastMsg?.typing) {
      el.scrollTop = el.scrollHeight;
      this.shouldScroll = false;
    }
  }
}
