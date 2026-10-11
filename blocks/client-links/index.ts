import { install } from './links';
import type { LinkClipboard } from './copy';

// Settings > Profotograaf loads this script on the Galleries tab only. It draws
// the QR codes and wires the copy buttons of the client galleries list. The
// Clipboard API is missing on plain http, where the copy button selects the
// link instead.
const clipboard = ( window.navigator as { clipboard?: LinkClipboard } ).clipboard;
install( document, clipboard );
