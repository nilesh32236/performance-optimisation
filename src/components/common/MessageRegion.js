/**
 * One visible place for every notice, wherever the card that produced it sits.
 *
 * See `src/lib/noticeBus.js` for why this exists and why it is
 * `aria-hidden` rather than a live region: the inline `NoticeBanner` already
 * announces, so announcing again here would double every message.
 *
 * @package
 */

import { useEffect, useState } from '@wordpress/element';
import { subscribe } from '../../lib/noticeBus';
import { NOTICE_TYPES } from './NoticeBanner';

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

	useEffect(
		() =>
			subscribe( ( notice ) => {
				// A key rather than the object, so two identical messages in a row
				// both show instead of the second replacing the first silently.
				const key = `${ Date.now() }-${ Math.random() }`;
				setMessages( ( was ) =>
					[ ...was, { ...notice, key } ].slice( -KEEP )
				);
				setTimeout( () => {
					setMessages( ( was ) =>
						was.filter( ( m ) => m.key !== key )
					);
				}, DISMISS_AFTER );
			} ),
		[]
	);

	if ( messages.length === 0 ) {
		return null;
	}

	return (
		// `aria-hidden` on purpose — see the note in noticeBus.js. The inline
		// banner is the accessible announcement; this is the visible one.
		<div className="wppo-message-region" aria-hidden="true">
			{ messages.map( ( m ) => {
				const type = NOTICE_TYPES.includes( m.type ) ? m.type : 'info';
				return (
					<div
						key={ m.key }
						className={ `wppo-message-region__item wppo-message-region__item--${ type }` }
					>
						<span
							className="wppo-message-region__icon"
							aria-hidden="true"
						>
							{ ICONS[ type ] }
						</span>
						<span className="wppo-message-region__text">
							{ m.message }
						</span>
					</div>
				);
			} ) }
		</div>
	);
}
