..  _configuration:

=============
Configuration
=============

..  _configuration-middleware:

Frontend middleware
===================

The extension registers the PSR-15 middleware
``maikschneider/api-key/authentication`` in the frontend stack, after
``typo3/cms-frontend/site`` and before ``typo3/cms-frontend/authentication``.
It authenticates the key of every frontend request once and attaches the
result as request attribute ``api_key``. Requests without a key only cost a
header lookup.

The middleware never logs in a frontend or backend user, creates no session
and sends no cookie.

..  _configuration-extension:

Extension configuration
=======================

..  confval:: rejectInvalidKeys
    :name: extconf-rejectInvalidKeys
    :type: boolean
    :default: false

    When enabled, the middleware answers requests with an invalid, revoked or
    expired key with ``401`` right away:

    ..  code-block:: http

        HTTP/1.1 401 Unauthorized
        Content-Type: application/json; charset=utf-8
        Cache-Control: no-store
        WWW-Authenticate: Bearer realm="api", error="invalid_token"
        WWW-Authenticate: ApiKey realm="api", header="X-API-Key"

        {"error":"invalid_api_key"}

    Requests without a key and requests with a bearer token of another
    authenticator pass through. When disabled, invalid results are only
    attached to the request and the consuming API decides.
