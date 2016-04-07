adapter-swift-mailer
====================

Sending emails
--------------
```php
<?php

use Butterfly\Adapter\SwiftMailer\Mailer;
use Butterfly\Component\DI\Container;
use Butterfly\Component\Packages\PackagesConfig;

$baseDir = realpath(__DIR__);

require_once $baseDir . '/vendor/autoload.php';

$config = PackagesConfig::buildForComposer($baseDir);
$container = new Container($config);

/** @var Mailer $mailer */
$mailer = $container->get('bfy_adapter.swift_mailer');

$mailer->sendPrepared('hello.world', array(
    'name' => 'World',
));


$mailer->flush();
```

Base template
-------------
```
#base.xml.twig

<?xml version="1.0" encoding="utf-8" ?>
<message>
    <subject><![CDATA[{% block subject %}{% endblock %}]]></subject>
    <body><![CDATA[{% block body %}{% endblock %}]]></body>
</message>
```

Configuration
-------------

```
bfy_adapter.twig.template_paths:
  - '%app.dir.root%/view/'

bfy_adapter.swift_mailer.prepared_mails:
  hello.world:
    transport:    'bfy_adapter.swift_mailer.transport.mailtrap'
    content_type: 'text/html'
    template:     'hello_world.html.twig'
    from:         {'test@helloworlder.com': 'Robot'}
    to:           'agregad9@gmail.com'

services:

  bfy_adapter.swift_mailer:
    class: 'Butterfly\Adapter\SwiftMailer\Mailer'
    arguments:
      - '%bfy_adapter.swift_mailer.prepared_mails%'
      - '#bfy_adapter.swift_mailer.transports'
      - '#bfy_adapter.swift_mailer.spools'
      - '@bfy_adapter.twig'

  bfy_adapter.swift_mailer.transport.mailtrap:
    factoryStaticMethod: ['Swift_Mailer', 'newInstance']
    arguments: ['@bfy_adapter.swift_mailer.transport_handler.mailtrap']
    tags: 'bfy_adapter.swift_mailer.transports'

  bfy_adapter.swift_mailer.transport_handler.mailtrap:
    class: 'Swift_SmtpTransport'
    calls:
      - ['setHost', ['mailtrap.io']]
      - ['setPort', ['2525']]
      - ['setUsername', ['22233f3bd7436f471'], true]
      - ['setPassword', ['47750b24d980a8'], true]
      - ['setEncryption', ['tls'], true]
      - ['setAuthMode', ['cram-md5'], true]

  bfy_adapter.swift_mailer.transport.mailtrap.spool:
    factoryStaticMethod: ['Swift_Mailer', 'newInstance']
    arguments: ['@bfy_adapter.swift_mailer.transport_handler.mailtrap.spool']
    tags: 'bfy_adapter.swift_mailer.spools'

  bfy_adapter.swift_mailer.transport_handler.mailtrap.spool:
    factoryStaticMethod: ['Swift_SpoolTransport', 'newInstance']
    arguments: ['@bfy_adapter.swift_mailer.spool.mailtrap']

  bfy_adapter.swift_mailer.spool.mailtrap:
    class: 'Swift_FileSpool'
    arguments: ['%app.dir.root%/var']

  bfy_adapter.twig.environment:
    class: 'Twig_Environment'
    arguments: [@bfy_adapter.twig.loader, %bfy_adapter.twig.configuration%]
    calls:
      - ['setExtensions', [#bfy_adapter.twig.extensions/toArray]]
```
