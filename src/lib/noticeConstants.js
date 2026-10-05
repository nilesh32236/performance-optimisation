/**
 * The notice type vocabulary, shared by the inline banner and the app-level
 * message region so both degrade identically on an unknown type.
 *
 * @package
 */

/**
 * Coerce an arbitrary value to a known notice type.
 *
 * @since NEXT
 * @param {*} type Raw notice type.
 * @return {string} A member of NOTICE_TYPES.
 */
export const NOTICE_TYPES = Object.freeze( [
	'error',
	'warning',
	'info',
	'success',
] );
export const normalizeNoticeType = ( type ) =>
	NOTICE_TYPES.includes( type ) ? type : 'info';
