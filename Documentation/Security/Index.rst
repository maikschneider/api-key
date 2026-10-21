..  _security:

========
Security
========

..  _security-storage:

How keys are stored
===================

A key carries 256 random bits. The database stores only an HMAC-SHA256 of the
key. The HMAC secret is derived from
:php:`$GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']`, so a database
dump alone does not let an attacker check guessed keys. Comparisons are timing safe.

..  warning::

    Rotating the ``encryptionKey`` invalidates every API key. Create new keys
    and hand them out after a rotation.

The key is printed once by ``apikey:create`` and cannot be recovered. The
plain-text *identifier* (``t3key_`` plus eight characters) identifies a key in
the backend, in ``apikey:list`` and in logs.

..  _security-transport:

Sending keys
============

*   Send keys over HTTPS only.
*   Send them in the ``X-API-Key`` or ``Authorization: Bearer`` header.
*   A key in the query string is always rejected, even when a valid header is
    present, because query strings end up in access logs, browser history and
    ``Referer`` headers. Treat such a key as leaked and revoke it.

..  _security-apache:

Apache and the Authorization header
-----------------------------------

Apache with PHP-FPM or FastCGI does not pass the ``Authorization`` header to
PHP by default, and the ``.htaccess`` that TYPO3 ships does not forward it
either. Bearer tokens then never reach TYPO3. Either use the ``X-API-Key``
header, or forward the header in your virtual host or ``.htaccess``:

..  code-block:: apache

    # Apache 2.4.13 and newer
    CGIPassAuth On

    # older versions
    SetEnvIfNoCase ^Authorization$ "(.+)" HTTP_AUTHORIZATION=$1

nginx with PHP-FPM passes the header without configuration.

..  _security-revocation:

Revoking keys
=============

``apikey:revoke`` hides the key record, and so does hiding it in the
backend. Deleting the record works too. A key also stops working when it
expires or when its bound user is deleted, disabled or outside its start and
end time.

..  _security-logging:

Logging
=======

Rejected keys are logged as warnings by the logger of
:php:`\MaikSchneider\ApiKey\Authentication\ApiKeyAuthenticator`, with the
reason, the identifier, the client address and the URL without query string.
The key itself is never logged. Configure a writer for that class to collect
the entries, e.g. in :file:`config/system/additional.php`:

..  code-block:: php

    use Psr\Log\LogLevel;
    use TYPO3\CMS\Core\Log\Writer\FileWriter;

    $GLOBALS['TYPO3_CONF_VARS']['LOG']['MaikSchneider']['ApiKey']['writerConfiguration'] = [
        LogLevel::WARNING => [
            FileWriter::class => ['logFileInfix' => 'api_key'],
        ],
    ];

Clients get the same response for every reason, so they can't tell an
unknown key from a revoked one.

..  _security-reporting:

Reporting a vulnerability
=========================

Please do not report security issues in public GitHub issues. Report them to
the TYPO3 Security Team at security@typo3.org.
