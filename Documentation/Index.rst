..  _start:

=======
API Key
=======

:Extension key:
   api_key

:Package name:
   maikschneider/api-key

:Version:
   |release|

:Language:
   en

:Author:
   Maik Schneider & Contributors

:License:
   This document is published under the
   `Open Publication License <https://www.opencontent.org/openpub/>`__.

:Rendered:
   |today|

----

API Key adds stateless API key authentication to TYPO3. Scripts, cron jobs,
CI pipelines and server-to-server integrations send a key, and any API,
PSR-15 middleware or auth service can verify it without creating sessions
or cookies.

----

..  card-grid::
    :columns: 1
    :columns-md: 2
    :gap: 4
    :class: pb-4
    :card-height: 100

    ..  card:: :ref:`Introduction <introduction>`

        What API Key does and when to use it.

    ..  card:: :ref:`Installation <installation>`

        How to install the extension via Composer.

..  Table of Contents

..  toctree::
    :maxdepth: 2
    :titlesonly:
    :hidden:

    Introduction/Index
    Installation/Index
