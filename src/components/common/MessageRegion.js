/**
 * One visible place for every notice, wherever the card that produced it sits.
 *
 * See `src/lib/noticeBus.js` for why this exists and why it is
 * `aria-hidden` rather than a live region: the inline `NoticeBanner` already
 * announces, so announcing again here would double every message.
 *
 * @package
 */

import { useEffect, useRef, useState } from '@wordpress/element';
import { subscribe } from '../../lib/noticeBus';

const ICONS = {
	success: '✓',
	error: '!',
	warning: '!',
	info: 'i',
};

const DISMISS_AFTER = 6000;
const KEEP = 3;

/**
 * The app-level message region.
 *
 * @return {Element|null} The region, or null when nothing has fired.
 */
export default function MessageRegion() {
	const [ messages, setMessages ] = useState( [] );
	// Audit #1839: track the per-message dismiss timers so the effect cleanup
	// can cancel them. Previously the effect only unsubscribed, so a queued
	// timer still fired setMessages on the unmounted component.
	const timersRef = useRef( [] );

	useEffect( () => {
		const timers = timersRef.current;
		const unsubscribe = subscribe( ( notice ) => {
			// A key rather than the object, so two identical messages in a row
			// both show instead of the second replacing the first silently.
			const key = `${ Date.now() }-${ Math.random() }`;
			setMessages( ( was ) =>
				[ ...was, { ...notice, key } ].slice( -KEEP )
			);
			const timer = setTimeout( () => {
				setMessages( ( was ) => was.filter( ( m ) => m.key !== key ) );
			}, DISMISS_AFTER );
			timers.push( timer );
		} );
		return () => {
			unsubscribe();
			timers.forEach( ( timer ) => clearTimeout( timer ) );
			timers.length = 0;
		};
	}, [] );

	if ( messages.length === 0 ) {
		return null;
	}

	return (
		// `aria-hidden` on purpose — see the note in noticeBus.js. The inline
		// banner is the accessible announcement; this is the visible one.
		<div className="wppo-message-region" aria-hidden="true">
			{ messages.map( ( m ) => (
				<div
					key={ m.key }
					className={ `wppo-message-region__item wppo-message-region__item--${ m.type }` }
				>
					<span
						className="wppo-message-region__icon"
						aria-hidden="true"
					>
						{ ICONS[ m.type ] || ICONS.info }
					</span>
					<span className="wppo-message-region__text">
						{ m.message }
					</span>
				</div>
			) ) }
		</div>
	);
}
