# Exporting contact details requires password confirmation and is recorded

- **Status:** accepted (2026-08-06)

ADR-0005 gives every user the same rights, so this exception needs stating. The Registry holds
the names, mobile numbers and email addresses of identifiable people — presidents and secretaries
of juntas de vecinos — which is personal data under Chilean law and the one place where a
coordination tool turns into a portable contact list. Viewing stays unrestricted, because phoning
a junta is the point. Exports keep the contact fields, because an export exists precisely so
someone can make those calls. What changes is that an export containing contact fields requires
the user to re-enter their own password, using Nextcloud's built-in
`#[PasswordConfirmationRequired(strict: true)]` rather than any credential we invent, and every
such export appends a history entry naming the user, the moment and what was exported. An
administrator can read that list as a report.

## Consequences

- Exports without contact fields need no confirmation; the prompt appears only when it is earned.
- The export log is part of history and is never deleted.
- Nothing here restricts who may export — it makes exporting deliberate and attributable.
