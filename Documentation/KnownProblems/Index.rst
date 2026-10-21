..  _known-problems:

==============
Known problems
==============

Keys can't be created in the backend
    FormEngine can't show a secret once, so keys are created on the command
    line. A key record created in the list module has no hash and never
    authenticates. A backend module is planned
    (`#9 <https://github.com/maikschneider/api-key/issues/9>`__).

No rate limiting
    Failed attempts are logged but not throttled. Limit requests in your web
    server or proxy for now
    (`#8 <https://github.com/maikschneider/api-key/issues/8>`__).

Bearer tokens behind Apache
    See :ref:`security-apache`.
