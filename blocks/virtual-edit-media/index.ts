import { install } from './note';
import type { MaybeMedia } from './note';

// The server loads this script where the media views load, for users who can
// upload files, while photos can be kept on Profotograaf. It swaps the Edit
// Image button of such a photo for an explanation and a Convert to local link.
const globalScope = window as unknown as { wp?: { media?: MaybeMedia } };
install( globalScope.wp?.media );
