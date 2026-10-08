import type { PhotoItem } from '../import-screen/types';

/**
 * The small part of the wp.media Backbone API this module touches. wp.media
 * ships no types, so only what the code calls is described here.
 */
export interface AttachmentModel {
	id?: string | number;
	fetch?: () => unknown;
}

export interface RouterView {
	set: ( items: Record< string, { text: string; priority: number } > ) => unknown;
}

export interface RegionLike {
	view?: unknown;
}

interface StateLike {
	get: ( key: string ) => unknown;
}

export interface FrameLike {
	el: HTMLElement;
	on: ( event: string, callback: ( arg: never ) => void ) => unknown;
	state: () => StateLike;
}

export interface SelectionCollection {
	multiple?: boolean | string;
	add: ( models: unknown ) => unknown;
	remove: ( models: unknown ) => unknown;
}

export interface MediaGlobal {
	view: {
		MediaFrame: { Select: { prototype: { bindHandlers: ( ...args: unknown[] ) => void } } };
	};
	model: { Attachment: new ( attributes: Record< string, unknown > ) => AttachmentModel };
	attachment: ( id: number ) => AttachmentModel;
	View: { extend: ( proto: Record< string, unknown > ) => new ( options: Record< string, unknown > ) => unknown };
}

/** wp.media as a screen may have it: the views are missing where they are not loaded. */
export interface MaybeMedia {
	view?: { MediaFrame?: { Select?: { prototype?: MediaGlobal[ 'view' ][ 'MediaFrame' ][ 'Select' ][ 'prototype' ] } } };
}

export interface ReadyPhoto {
	photo: PhotoItem;
	attachmentId: number;
}

/** What the session needs from the frame's selection. */
export interface SelectionPort {
	multiple: () => boolean;
	/** Shows exactly these photos as the pending selection. */
	show: ( photos: PhotoItem[] ) => void;
	/** Removes the pending photos from the selection. */
	hide: () => void;
	/**
	 * Swaps the pending photos for the real attachments, loaded in full.
	 * Resolves the photo ids whose attachment could not be loaded.
	 */
	commit: ( ready: ReadyPhoto[] ) => Promise< string[] >;
}
