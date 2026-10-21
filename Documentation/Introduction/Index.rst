..  _introduction:

============
Introduction
============

API Key lets machines authenticate against TYPO3 with a long-lived secret
instead of a login. Scripts, cron jobs, CI pipelines and server-to-server
integrations send the key in a header:

..  code-block:: bash

    curl -H 'X-API-Key: t3key_KhhNfTRL-5tLoriJVo5I-BQRJWiLD3GqlvEcPDPeHmg' \
        https://example.com/api/articles

The extension resolves the key to its record, with scopes and an optional
frontend or backend user, and hands the result to the API that serves the
request. It does not log anyone in.

..  _introduction-features:

Features
========

*   ``X-API-Key`` and ``Authorization: Bearer`` headers. Bearer tokens
    without the ``t3key_`` prefix are left to other authenticators, so OAuth2
    or JWT keep working next to it
*   Service keys, keys bound to a frontend user and keys bound to a backend
    user
*   Keys are stored as HMAC hashes and shown only once, at creation
*   Optional restrictions per key: scopes, expiry, IP addresses and CIDR
    ranges, sites
*   Stateless: no ``fe_sessions`` rows, no ``Set-Cookie``, no ``lastlogin``
    updates
*   One service with three outcomes that any API, middleware or
    authentication service can use, plus a frontend middleware
*   Command line tools to create, list and revoke keys

..  _introduction-why:

Why not the core authentication service?
========================================

TYPO3 can authenticate a frontend user from a header through an
authentication service (``getUserFE`` / ``authUserFE`` together with
``FE_alwaysFetchUser``). That approach is not stateless: every request
without a session cookie creates a new ``fe_sessions`` row, sends
``Set-Cookie`` and updates ``fe_users.lastlogin``. ``FE_alwaysFetchUser`` is
also global, so the service runs on every frontend request. Backend users
can't authenticate from a header in the frontend at all.

API Key verifies the key without any of these side effects and leaves the
decision what an authenticated key may do to the API that receives it.

..  _introduction-consumers:

Who uses it
===========

`TCA API <https://extensions.typo3.org/extension/tca_api>`__ authenticates
API requests with it. The extension does not depend on any consumer; use it
from your own controllers, middlewares or authentication services, see
:ref:`developer`.
