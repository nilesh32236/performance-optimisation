export const NOTICE_TYPES = Object.freeze( [
	'error',
	'warning',
	'info',
	'success',
] );
export const normalizeNoticeType = ( type ) =>
	NOTICE_TYPES.includes( type ) ? type : 'info';
