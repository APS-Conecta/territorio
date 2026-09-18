/**
 * «¿Dónde estoy?» — reading a browser geolocation fix well enough to put somebody
 * on this map.
 *
 * The part that needs judgement is not the marker, it is the accuracy. This app
 * decides which Sector and which Unidad Vecinal a point falls in (ADR-0008), and
 * those boundaries are streets apart. A fix taken indoors is routinely accurate
 * to hundreds of metres, and it arrives looking exactly as confident as a good
 * one — same marker, same dot. Drawn without its accuracy it is an assertion
 * that somebody is in a Sector they may not be in.
 *
 * So the rule here: a fix says «estás aquí» only while it is precise enough for
 * that to mean a corner rather than a comuna. Otherwise it is drawn as what it
 * is — a circle you are somewhere inside.
 */

/**
 * How precise a fix has to be before it may be read as a position.
 *
 * 100 m because that is the scale this app works at: a block in La Florida is
 * well under it, so a fix inside this radius names the right block, and one
 * outside it can sit on either side of a boundary the map draws.
 */
export const ACCURACY_LIMIT_M = 100

/** Whether a fix is precise enough to tell one Sector from the next. */
export function isFixTrustworthy(accuracyMetres) {
	// Not `typeof === 'number'`: NaN and Infinity are both numbers, and both
	// arrive from a reading that failed halfway. A fix with no accuracy attached
	// is the case where believing it costs the most, so it is refused too.
	return typeof accuracyMetres === 'number'
		&& Number.isFinite(accuracyMetres)
		&& accuracyMetres >= 0
		&& accuracyMetres <= ACCURACY_LIMIT_M
}

/**
 * The accuracy, in the unit a person reads without counting digits.
 *
 * Empty when there is none, because «±0 m» would be a claim the reading never
 * made.
 */
export function accuracyLabel(accuracyMetres) {
	if (typeof accuracyMetres !== 'number' || !Number.isFinite(accuracyMetres) || accuracyMetres < 0) {
		return ''
	}

	if (accuracyMetres < 1000) {
		return `±${Math.round(accuracyMetres)} m`
	}

	// Comma, not point: this is read in Chile (es-CL), where the decimal
	// separator is a comma and a point groups thousands.
	const kilometres = Math.round(accuracyMetres / 100) / 10
	return `±${String(kilometres).replace('.', ',')} km`
}

/**
 * What went wrong, in Spanish and in terms of what the person can do about it.
 *
 * The browser's own `message` is in English and names none of that — for a
 * refused permission it says "User denied Geolocation", which is true and
 * useless: the switch is in the address bar, not in this app.
 *
 * Written in USTED, like every other sentence this app shows a person
 * («Haga clic dentro del sector…»). Chile does not use voseo, and a first draft
 * of these four lines did.
 *
 * Codes are `GeolocationPositionError`: 1 PERMISSION_DENIED, 2
 * POSITION_UNAVAILABLE, 3 TIMEOUT.
 */
export function locateErrorMessage(code) {
	switch (code) {
	case 1:
		return 'No tenemos permiso para usar su ubicación. Puede activarlo en el candado de la barra de direcciones y volver a intentar.'
	case 2:
		return 'Su dispositivo no pudo determinar dónde está. Suele ocurrir bajo techo: pruebe cerca de una ventana o al aire libre.'
	case 3:
		return 'La ubicación tardó demasiado en llegar. Vuelva a intentar.'
	default:
		return 'No pudimos obtener su ubicación.'
	}
}
