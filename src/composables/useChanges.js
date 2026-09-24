/**
 * The change-cursor flow's decision core (L1-31) — out of App.vue so the two
 * documented bugs' worth of rules (#585's late-response ordering, the
 * close-open-draft-on-retire case) live where a test can reach them.
 *
 * An Options-API component, so not a `setup()` composable: the module is the
 * org's established shape — pure functions over plain state, like filters.js
 * and limit.js — named `use*` because it is the poll/merge flow's one home.
 * App.vue keeps the axios call, the timer and the visibility listener (three
 * lines each); everything that DECIDES anything is here.
 */

/**
 * Apply one record from the change feed to whichever list it belongs in
 * (ADR-0002) — App.vue's mergeFeature body verbatim, as a pure function.
 *
 * A retirement reaches us as an ordinary change carrying retired:true;
 * moving the record between the two lists here is what takes it off
 * everyone else's map. Both lists are kept, not just the live one —
 * otherwise a colleague's restore leaves a stale row in the retired list
 * still offering to restore what is already back.
 *
 * @param {{features: Array, retiredFeatures: Array, selectedId: ?number,
 *          showRetired: boolean}} state the two lists and the selection
 * @param {object} incoming the record the feed delivered
 * @return {{features: Array, retiredFeatures: Array, closeOpen: boolean}}
 *         NEW arrays always — a caller spreading them keeps reactivity, and a
 *         test comparing them never mutates its fixture
 */
export function mergeInto(state, incoming) {
	const from = incoming.retired ? state.features : state.retiredFeatures
	const into = incoming.retired ? state.retiredFeatures : state.features

	// Idempotent by construction, which is the property the poll depends on:
	// a slow response can land after a later one and re-deliver a record.
	const stale = from.findIndex((feature) => feature.id === incoming.id)
	const nextFrom = stale === -1 ? [...from] : from.filter((_, index) => index !== stale)

	const existing = into.findIndex((feature) => feature.id === incoming.id)
	const nextInto = existing === -1
		? [incoming, ...into]
		: into.map((feature, index) => (index === existing ? incoming : feature))

	// The sidebar may now be showing a record that has left the screen: it
	// was retired, and retired records are not being shown. Not "differs
	// from the toggle" — that also caught a LIVE record open while the
	// retired list was on show, which is an ordinary thing to be looking at
	// since #67, and closing it on the record's own save is what made the
	// save look like it had failed.
	const closeOpen = state.selectedId === incoming.id && incoming.retired && !state.showRetired

	return incoming.retired
		? { features: nextFrom, retiredFeatures: nextInto, closeOpen }
		: { features: nextInto, retiredFeatures: nextFrom, closeOpen }
}
