import {
  Component,
  ElementRef,
  inject,
  signal,
  ViewChild,
  AfterViewChecked,
  HostListener,
  OnInit,
  computed,
} from '@angular/core';
import { CommonModule, Location } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { ChatStateService } from '../../../../core/services/chat-state.service';
import { AuthService } from '../../../../core/services/auth.service';

@Component({
  selector: 'app-chat-page',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './chat-page.component.html',
  styleUrl: './chat-page.component.scss',
})
export class ChatPageComponent implements AfterViewChecked, OnInit {
  readonly chat = inject(ChatStateService);
  private readonly location = inject(Location);
  private readonly auth     = inject(AuthService);

  /** True when the logged-in user is a viewer — selector is hidden for viewers. */
  readonly isViewer = computed(() => this.auth.user()?.role === 'viewer');

  input = signal('');
  selectorOpen = signal(false);

  @ViewChild('messageList') private messageList!: ElementRef<HTMLElement>;
  private shouldScroll = true; // scroll on load

  ngOnInit(): void {
    console.log('[ChatPage] ngOnInit — role:', this.auth.user()?.role, '| isViewer:', this.isViewer());
    this.chat.ensureIdentitiesLoaded();
  }

  goBack(): void {
    this.location.back();
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
    console.log('[ChatPage] retryIdentities triggered');
    this.chat.retryIdentities();
  }

  @HostListener('document:click', ['$event'])
  onDocClick(event: MouseEvent): void {
    const target = event.target as HTMLElement;
    if (!target.closest('.cp-selector')) {
      this.selectorOpen.set(false);
    }
  }

  @HostListener('window:beforeunload')
  onBeforeUnload(): void {
    // no-op — state persists in the injectable service
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
