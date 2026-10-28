..  _known-problems:

==============
Known problems
==============

Input of a rejected new key is lost
    When a new key breaks a rule, FormEngine opens an empty form again. This is
    how FormEngine handles every record that DataHandler refuses to insert.
    An edit of an existing key that breaks a rule is dropped as a whole.

Error messages carry a generic prefix
    DataHandler wraps every save error in "This action could not be completed;
    please contact your administrator". The reason follows after "Technical
    details".

Keys created without a backend session can't be shown
    A key record created by DataHandler outside the backend, for example in a
    console command or an import, gets a hash but its secret is never shown.
    Use ``apikey:create`` on the command line.

Backend users can't manage their own keys
    Key records are admin only, and the module lives in the admin-only
    Integrations module.

No rate limiting
    Failed attempts are logged but not throttled. Limit requests in your web
    server or proxy for now
    (`#8 <https://github.com/maikschneider/api-key/issues/8>`__).

Bearer tokens behind Apache
    See :ref:`security-apache`.
