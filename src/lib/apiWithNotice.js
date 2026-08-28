/**
 * Re-export for D-03: apiWithNotice alias.
 *
 * Some references (audit carry-over) expect `src/lib/apiWithNotice.js`;
 * the canonical implementation lives in `useApiCallWithNotice.js`.
 *
 * @since NEXT
 */
export {
	withApiNotice,
	useApiCallWithNotice,
	default,
} from './useApiCallWithNotice';
