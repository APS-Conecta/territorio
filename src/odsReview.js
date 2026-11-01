/** Revising one row must not undo the choices already made about other rows. */
export function decisionsForPreview(preview, previousPreview = null, previousDecisions = []) {
	if (!previousPreview) {
		return preview.rows.filter(row => row.status !== 'invalid' && !row.candidates.length)
			.map(row => ({ row: row.row, clear: [], confirmDuplicates: false }))
	}

	const previousRows = new Map(previousPreview.rows.map(row => [row.row, row]))
	const revisedRows = new Map(preview.rows.map(row => [row.row, row]))
	return previousDecisions.filter(decision => {
		const before = previousRows.get(decision.row)
		const after = revisedRows.get(decision.row)
		return before && after && after.status !== 'invalid' && reviewContent(before) === reviewContent(after)
	}).map(decision => ({ ...decision, clear: [...decision.clear] }))
}

function reviewContent(row) {
	return JSON.stringify([row.id, row.status, row.input, row.candidates, row.changes, row.errors])
}
