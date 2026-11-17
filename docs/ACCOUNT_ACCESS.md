# Accounts and staff access

## My account

Every signed-in user can open **My account** to review their name, email, role and branch assignment and change their password.

A password change requires the current password and a matching confirmation for the new password. Passwords must be at least 12 characters, no more than 72 bytes, and contain no null bytes. The byte limit avoids silent truncation by the current bcrypt password hasher. The new password must differ from the current one.

After a successful change, the current session stays signed in with a regenerated session ID. Other existing sessions become invalid on their next request. Previously issued remember-me tokens are rotated. The application never puts passwords into audit descriptions or success messages.

## Owner controls

Owners can open **Team members** to create staff accounts or use **Manage** to change a staff member's name, email and assigned branch. Changed profiles invalidate that staff member's existing sessions. Saving an unchanged profile does not sign them out.

Branch reassignment changes the staff member's future access. Historical sales, stock movements and audit records retain their original branch and user references. Staff cannot change their own branch or role, or access another user's management endpoints.

Owners can reset a staff password by confirming their own current owner password and entering the new staff password twice. Share the new password through a private channel. Resetting a password ends existing sessions and rotates remember-me tokens, but does not reactivate an inactive account.

The staff management endpoints reject all owner accounts, including the current owner. Role promotion, owner removal and owner account editing are not exposed through these screens.

## Deactivate and reactivate

Deactivation blocks new logins and invalidates existing sessions and remember-me tokens. It preserves the account and its transaction/audit history. Reactivation allows a fresh login; it does not revive old sessions or old remember-me cookies. Repeating an unchanged activation/deactivation request does not unnecessarily increment the access version.

## How session revocation works

The user record has an `auth_version`, copied into the authenticated session at login. Web requests, including Livewire requests, check that the account is active and that the session version matches the database. Password changes, password resets, profile changes and activation changes increment the version. This works independently of the configured session storage driver; it does not rely solely on deleting database session rows.

Existing sessions from before this migration may require a fresh login. A valid remember-me cookie can establish a fresh session only if its account is active and its token still matches the database.

The API uses separate branch source keys. A password change does not rotate these machine credentials; manage them under **Integrations**. The API rejects sources whose creating account is inactive or no longer an owner.

Queued CSV imports are checked against the submitting user's current permissions when processed. An import whose submitting user has been deactivated or moved to another branch may fail rather than process with obsolete access. Restart long-running queue workers after deploying these authorization changes.

## Remaining scope

Email-based password recovery, owner account editing, MFA, and password-reset invitations are not implemented. The current staff recovery path is an owner-assisted reset. Initial passwords in `.local-demo-access.md` are only the seeded credentials; password changes intentionally do not write new plaintext passwords back into that file.
