..  _introduction:

============
Introduction
============

API Key lets machines authenticate against TYPO3 with a long-lived secret
instead of a login. A request carries the key in the ``X-API-Key`` header or
as ``Authorization: Bearer``, and the extension resolves it to a key record
with its scopes and an optional frontend or backend user.

Verifying a key has no side effects: no session is created, no cookie is
sent and no user record is updated.
