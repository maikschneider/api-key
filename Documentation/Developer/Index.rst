..  _developer:

===============
Developer guide
===============

APIs, middlewares and authentication services verify keys through one
service, :php:`\MaikSchneider\ApiKey\Authentication\ApiKeyAuthenticatorInterface`.

..  code-block:: php

    use MaikSchneider\ApiKey\Authentication\ApiKeyAuthenticatorInterface;

    final class MyApiController
    {
        public function __construct(
            private readonly ApiKeyAuthenticatorInterface $apiKeyAuthenticator,
        ) {}

        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            $result = $this->apiKeyAuthenticator->authenticate($request);

            if ($result->isInvalid()) {
                return new JsonResponse(['error' => 'Unauthorized'], 401, [
                    'WWW-Authenticate' => 'Bearer realm="api"',
                ]);
            }

            if ($result->isValid()) {
                $apiKey = $result->getApiKey();
                // $apiKey->scopes, $apiKey->frontendUserUid, ...
            }

            // no key: try other authentication or continue anonymously
        }
    }

..  _developer-outcomes:

Three outcomes
==============

:php:`authenticate()` returns an :php:`AuthenticationResult` in one of three
states:

Not present (:php:`isPresent()` is ``false``)
    The request carries no key of this extension. Try other authentication
    mechanisms or treat the request as anonymous. A bearer token without the
    ``t3key_`` prefix counts as not present, so OAuth2 or JWT tokens reach
    their own authenticator.

Valid (:php:`isValid()`)
    :php:`getApiKey()` returns the authenticated key.

Invalid (:php:`isInvalid()`)
    A key was presented but rejected. Answer ``401`` and **never** fall back to
    anonymous access. :php:`getInvalidReason()` tells why, for logging only;
    send clients the same response for every reason.

..  _developer-reasons:

Invalid reasons
---------------

========================  ======================================================
Reason                    Cause
========================  ======================================================
``Malformed``             The ``X-API-Key`` header or a ``t3key_`` bearer token
                          is not a well-formed key
``Ambiguous``             Two different keys, or a repeated ``X-API-Key`` header
``KeyInQueryString``      A key appears in the query string. Query strings end
                          up in access logs and ``Referer`` headers
``Unknown``               No key with this hash exists
``Revoked``               The key record is hidden or deleted
``Expired``               The expiry date has passed
``IpNotAllowed``          The client IP is outside the key's allowed ranges
``SiteNotAllowed``        The request targets a site the key is not allowed for
========================  ======================================================

..  _developer-api-key:

The key object
==============

:php:`\MaikSchneider\ApiKey\Authentication\ApiKey` never carries the secret
or its hash.

..  list-table::
    :header-rows: 1
    :widths: 25 25 50

    *   - Property
        - Type
        - Description
    *   - ``uid``
        - ``int``
        - Record uid
    *   - ``title``
        - ``string``
        - Label of the key
    *   - ``identifier``
        - ``string``
        - Prefix of the key, safe to log
    *   - ``scopes``
        - ``list<string>``
        - Opaque to this extension; empty means unrestricted
    *   - ``frontendUserUid``
        - ``?int``
        - Bound ``fe_users`` record
    *   - ``backendUserUid``
        - ``?int``
        - Bound ``be_users`` record
    *   - ``expiresAt``
        - ``?DateTimeImmutable``
        - Expiry, ``null`` for none

The extension does not log users in. Map the bound user to your own
authorization model.

..  _developer-restrictions:

Restrictions
============

The authenticator enforces the restrictions stored with a key, and rejects
the key if one fails:

Expiry
    The key is invalid from its expiry date on (``Expired``).

Allowed IPs
    The client address must match one of the key's IP addresses or CIDR
    ranges (``IpNotAllowed``). The address comes from the request's
    ``normalizedParams`` attribute, which resolves the client behind proxies
    listed in :php:`$GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyIP']`.
    Without that attribute ``REMOTE_ADDR`` is used.

Sites
    The request's ``site`` attribute must be one of the key's sites
    (``SiteNotAllowed``). Call :php:`authenticate()` after the site is
    resolved, e.g. in a middleware placed after
    ``typo3/cms-frontend/site``. A request without a site fails a
    site-restricted key.

Scopes are not checked by the authenticator, because only the consumer
knows which scope an endpoint needs:

..  code-block:: php

    if (!$apiKey->hasScope('articles:write')) {
        return new JsonResponse(['error' => 'Forbidden'], 403);
    }

A key without scopes has every scope. Scopes are compared exactly.

..  _developer-side-effects:

Side effects
============

Authenticating a request creates no session, sends no cookie and does not
update the user record. The only write is the key's ``last_used_at``, at most
once every five minutes per key. Rejected keys are logged as warnings with the
reason, the identifier and the client IP, never with the key itself.
