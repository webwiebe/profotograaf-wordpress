<?php
/**
 * The empty state of a gallery.
 *
 * @package Profotograaf
 */

namespace Profotograaf;

defined( 'ABSPATH' ) || exit;

/**
 * Markup and script for a gallery that has no photos the embed can show.
 *
 * Two paths lead here. The renderer knows from the cached gallery index that
 * the gallery is empty and writes the host with `data-pf-empty`. When the
 * index still counts photos the embed cannot show (PNG web variants, videos),
 * a small script watches the hosts instead: embed.js sets `data-pf-ready` when
 * it starts and attaches a shadow root only when it has photos to draw. A host
 * that is ready, whose gallery request finished and that still has no shadow
 * root a moment later is empty. The script then sets `data-pf-empty`, which
 * collapses the reserved space and hides the fallback link (see
 * Reserved_Space::rule()).
 *
 * A newer embed.js reports its outcome as `data-pf-state` (drawn, empty or
 * error) and a bubbling `profotograaf:state` event. The script marks the host
 * from the event or the attribute at once: state `empty` sets `data-pf-empty`,
 * state `error` sets `data-profotograaf-failed`. The detection above only
 * serves hosts of an older embed.js that sets no state.
 *
 * Visitors see nothing. People who can edit the post get a short hint. A host
 * whose script never loaded has no `data-pf-ready`, so it keeps the failed
 * state.
 */
final class Empty_Gallery {

	/**
	 * Milliseconds between two looks at the galleries.
	 */
	private const TICK = 250;

	/**
	 * Most looks: about 40 seconds.
	 */
	private const TICKS = 160;

	/**
	 * The editor hint, or an empty string for visitors.
	 *
	 * @param bool $visible Whether it is shown at once. The script unhides a hidden one.
	 */
	public static function hint( bool $visible ): string {
		if ( ! self::can_see_hint() ) {
			return '';
		}
		return sprintf(
			'<p class="profotograaf-gallery-empty-hint" data-pf-hint%1$s style="margin:0;padding:.5em .75em;border:1px dashed #8c8f94;font-size:.875em">%2$s</p>',
			$visible ? '' : ' hidden',
			esc_html( self::message() . ' ' . self::hint_text() )
		);
	}

	/**
	 * Whether the current user may see the hint: they can edit the post being
	 * shown, or any post when there is no current post.
	 */
	public static function can_see_hint(): bool {
		$post_id = (int) get_the_ID();
		return $post_id > 0 ? current_user_can( 'edit_post', $post_id ) : current_user_can( 'edit_posts' );
	}

	/**
	 * What the hint says is wrong.
	 */
	public static function message(): string {
		return __( 'This gallery has no photos that can be shown on your site.', 'profotograaf' );
	}

	/**
	 * Why a gallery can be empty.
	 */
	public static function hint_text(): string {
		return __( 'Photos may still be processing, or the gallery holds videos or file types the site embed cannot show yet. Visitors do not see this note.', 'profotograaf' );
	}

	/**
	 * The script that marks hosts embed.js left empty. Plain ES5, queued after
	 * embed.js by Embed_Script. It looks every TICK milliseconds until every
	 * host is drawn or marked, or TICKS looks passed.
	 */
	public static function script(): string {
		return '(function(){var S="[data-profotograaf-gallery]",n=0;'
			. 'function done(h){var p="/api/v1/embed/galleries/"+encodeURIComponent(h.getAttribute("data-profotograaf-gallery")),r=performance.getEntriesByType("resource"),i,e;'
			. 'for(i=0;i<r.length;i++){e=r[i];if(e.responseEnd>0&&!(e.responseStatus>=400)&&e.name.split("?")[0].slice(-p.length)===p)return true}return false}'
			. 'function mark(h){h.setAttribute("data-pf-empty","");h.removeAttribute("data-profotograaf-failed");var t=h.querySelector("[data-pf-hint]");if(t)t.hidden=false}'
			. 'function fail(h){if(!h.hasAttribute("data-pf-empty"))h.setAttribute("data-profotograaf-failed","")}'
			. 'function apply(h,s){if(s==="empty")mark(h);else if(s==="error")fail(h)}'
			. 'document.addEventListener("profotograaf:state",function(v){var h=v.target;if(h&&h.matches&&h.matches(S)&&v.detail)apply(h,v.detail.state)});'
			. 'function look(){var l=document.querySelectorAll(S),o=0,i,h,s;n++;'
			. 'for(i=0;i<l.length;i++){h=l[i];s=h.getAttribute("data-pf-state");if(s){apply(h,s);continue}'
			. 'if(h.shadowRoot||h.hasAttribute("data-pf-empty"))continue;o++;'
			. 'if(!h.hasAttribute("data-pf-ready")||!done(h))continue;if(h._pf)mark(h);else h._pf=1}'
			. 'if(o&&n<' . self::TICKS . ')setTimeout(look,' . self::TICK . ')}'
			. 'if(window.performance&&performance.getEntriesByType)look()})()';
	}
}
