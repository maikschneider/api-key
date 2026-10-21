..  _usage:

=====
Usage
=====

..  _usage-key-format:

Key format
==========

A key consists of the prefix ``t3key_`` and 32 random bytes in unpadded
base64url, 49 characters in total:

..  code-block:: text

    t3key_KhhNfTRL-5tLoriJVo5I-BQRJWiLD3GqlvEcPDPeHmg

The prefix plus the next eight characters (``t3key_KhhNfTRL``) form the
*identifier*. It is stored in plain text, shown in the backend and in logs,
and identifies a key without revealing it. The full key is only shown once,
when it is created. The database stores an HMAC hash of it.

..  _usage-cli:

Managing keys on the command line
=================================

..  _usage-cli-create:

Create a key
------------

..  code-block:: bash

    vendor/bin/typo3 apikey:create --title="CI deploy"

..  confval-menu::
    :name: apikey-create-options
    :display: table
    :type:

..  confval:: --title
    :name: apikey-create-title
    :type: string
    :required: true

    A label that tells you what the key is used for.

..  confval:: --fe-user
    :name: apikey-create-fe-user
    :type: int

    Binds the key to a frontend user. Without ``--fe-user`` and ``--be-user``
    the key is a service key.

..  confval:: --be-user
    :name: apikey-create-be-user
    :type: int

    Binds the key to a backend user. A key is bound to one user at most.
    A bound user that is deleted, disabled or outside its start and end time
    makes the key invalid.

..  confval:: --created-by
    :name: apikey-create-created-by
    :type: int

    Records a backend user as creator of the key, e.g. the person who
    requested it. The creator is shown in the backend and by
    ``apikey:list``, and does not affect authentication.

..  confval:: --scope
    :name: apikey-create-scope
    :type: string, repeatable

    Restricts the key to a scope. The extension does not interpret scopes; the
    API that consumes the key defines them, e.g. ``articles:read``. A key
    without scopes is unrestricted.

..  confval:: --expires
    :name: apikey-create-expires
    :type: string

    A date (``2027-01-01``) or a relative time (``+90 days``) after which the
    key stops working.

..  confval:: --allowed-ip
    :name: apikey-create-allowed-ip
    :type: string, repeatable

    Restricts the key to an IP address or a CIDR range, e.g. ``10.0.0.0/8``
    or ``2001:db8::/32``.

..  confval:: --site
    :name: apikey-create-site
    :type: string, repeatable

    Restricts the key to the site with this identifier.

The command prints the key once. Store it in your secret manager right away;
it cannot be recovered.

..  _usage-cli-list:

List keys
---------

..  code-block:: bash

    vendor/bin/typo3 apikey:list

Shows identifier, title, bound user, scopes, expiry, last use and status of
every key. Secrets are never shown.

..  _usage-cli-revoke:

Revoke a key
------------

..  code-block:: bash

    vendor/bin/typo3 apikey:revoke t3key_KhhNfTRL
    vendor/bin/typo3 apikey:revoke 12

Takes the identifier or the uid. Revoking hides the record, so it stays
available for auditing.

..  _usage-secret-scanning:

Secret scanning
===============

The fixed prefix lets secret scanners find keys that end up in a repository.

`GitHub custom pattern <https://docs.github.com/en/code-security/secret-scanning/using-advanced-secret-scanning-and-push-protection-features/custom-patterns/defining-custom-patterns-for-secret-scanning>`__:

..  code-block:: text

    t3key_[A-Za-z0-9_-]{43}

`gitleaks <https://github.com/gitleaks/gitleaks>`__ rule:

..  code-block:: toml

    [[rules]]
    id = "typo3-api-key"
    description = "TYPO3 API key (maikschneider/api-key)"
    regex = '''t3key_[A-Za-z0-9_-]{43}'''
    keywords = ["t3key_"]
