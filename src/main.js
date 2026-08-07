import axios from '@nextcloud/axios'
import { addPasswordConfirmationInterceptors } from '@nextcloud/password-confirmation'
import '@nextcloud/password-confirmation/style.css'
import { createApp } from 'vue'
import App from './App.vue'

/**
 * Installed once, on the shared axios instance, and only used by the export that
 * carries contact details (ADR-0006, issue #17).
 *
 * The interceptor is what serves a `#[PasswordConfirmationRequired(strict: true)]`
 * route: strict means the server wants the confirmation to be part of THIS
 * request, not a note in the session saying somebody typed their password twenty
 * minutes ago. Calling `confirmPassword()` by hand satisfies the lax rule and
 * quietly fails the strict one — the request goes out unconfirmed and comes back
 * 403 with no prompt ever shown.
 *
 * Here rather than in the dialog because it mutates a shared instance: doing it
 * where it is used would install it again on every open.
 */
addPasswordConfirmationInterceptors(axios)

createApp(App).mount('#territorio')
