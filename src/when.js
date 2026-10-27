/**
 * The moment, in the reader's own locale rather than an ISO string.
 *
 * @param {?string} moment an instant as the API sends it, with its UTC offset
 * @return {string} the local date and time, or nothing for a moment never recorded
 */
export function when(moment) {
	return moment === null ? '' : new Date(moment).toLocaleString()
}
