import { install } from './frame';
import type { MediaGlobal } from './types';
import './style.css';

// The server loads this script only while the media source setting is on and
// the user can upload files, and only where the media views load. Installing
// adds the Profotograaf tab to every media frame created afterwards.
const globalScope = window as unknown as { wp?: { media?: MediaGlobal } };
install( globalScope.wp?.media );
