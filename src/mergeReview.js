const FIELDS = {
	name: 'Nombre',
	subcategoryId: 'Clasificación',
	geometry: 'Ubicación',
	address: 'Dirección',
	phone: 'Teléfono',
	email: 'Correo',
	contactPerson: 'Persona de contacto',
	contactRole: 'Cargo',
	openingHours: 'Horario',
	notes: 'Notas',
	sourceRef: 'Fuente',
}

/** The user chooses the survivor; missing information is proposed, never discarded. */
export function mergeReview(survivor, other) {
	return Object.entries(FIELDS).map(([key, label]) => ({
		key,
		label,
		sourceId: survivor[key] === null || survivor[key] === undefined || survivor[key] === ''
			? other.id
			: survivor.id,
	}))
}
