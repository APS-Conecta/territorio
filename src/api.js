/**
 * The app binding for the shared error shape (aps-common's js/api.js via
 * `make aps-sync` → src/aps/). The prefix is the one app-specific byte;
 * every call site keeps importing './api.js' unchanged. territorio's call
 * sites already await; farmacia's three that did not gain the await in the
 * same change (entry 8b) — the shared messageFrom is async on every path.
 */
import { messageFrom, loggers } from './aps/api.js'

const { safeError, warnError } = loggers('territorio')

export { messageFrom, safeError, warnError }
