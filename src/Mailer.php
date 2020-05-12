<?php

namespace Butterfly\Adapter\SwiftMailer;

use Butterfly\Component\DI\ServicesCollection;
use Butterfly\Interfaces\TemplateRender\IRender;
use InvalidArgumentException;
use RuntimeException;
use Swift_ConfigurableSpool;
use Swift_Mailer;
use Swift_Message;
use Swift_SpoolTransport;

class Mailer
{
    const PARAMETER_FROM            = 'from';
    const PARAMETER_TO              = 'to';
    const PARAMETER_TRANSPORT       = 'transport';
    const PARAMETER_TEMPLATE        = 'template';
    const PARAMETER_CONTENT_TYPE    = 'content_type';
    const PARAMETER_CHARSET         = 'charset';
    const PARAMETER_ENCRYPTION      = 'encryption';
    const PARAMETER_ATTACHMENTS     = 'attachments';

    const ENCRYPTION_TLS = 'tls';
    const ENCRYPTION_SSL = 'ssl';
    const ENCRYPTION_NO  = null;

    const DEFAULT_CONTENT_TYPE  = 'text/plain';
    const DEFAULT_CHARSET       = 'utf-8';

    /**
     * @var array
     */
    protected $preparedMails;

    /**
     * @var \Swift_Mailer[]|ServicesCollection
     */
    protected $transports;

    /**
     * @var \Swift_Mailer[]|ServicesCollection
     */
    protected $spools;

    /**
     * @var IRender
     */
    protected $render;

    /**
     * @param array $preparedMails
     * @param ServicesCollection|\Swift_Mailer[] $transports
     * @param ServicesCollection|\Swift_Mailer[] $spools
     * @param IRender $render
     */
    public function __construct(array $preparedMails, ServicesCollection $transports, ServicesCollection $spools, IRender $render)
    {
        $this->preparedMails = $preparedMails;
        $this->spools        = $spools;
        $this->transports    = $transports;
        $this->render        = $render;
    }

    /**
     * @param string $templateName
     * @param array $templateParameters
     * @param array $mailParameters
     * @return int
     */
    public function sendPrepared($templateName, array $templateParameters = array(), array $mailParameters = array())
    {
        $mailConfig = $this->getMailConfig($templateName, $mailParameters);

        $xmlTemplateStr = $this->render->render($this->getMailParameter($mailConfig, self::PARAMETER_TEMPLATE), $templateParameters);
        list($subject, $body) = $this->parseXmlTemplate($xmlTemplateStr);

        $message = new Swift_Message();

        $message->setSubject($subject);
        $message->setBody($body);
        $message->setFrom($this->getMailParameter($mailConfig, self::PARAMETER_FROM));
        $message->setTo($this->getMailParameter($mailConfig, self::PARAMETER_TO));
        $message->setContentType($this->getMailParameterOrDefault($mailConfig, self::PARAMETER_CONTENT_TYPE, self::DEFAULT_CONTENT_TYPE));
        $message->setCharset($this->getMailParameterOrDefault($mailConfig, self::PARAMETER_CHARSET, self::DEFAULT_CHARSET));

        $attachments = $this->getMailParameterOrDefault($mailConfig, self::PARAMETER_ATTACHMENTS, []);

        foreach ($attachments as $filePath) {
            $message->attach(\Swift_Attachment::fromPath($filePath));
        }

        return $this->send($this->getMailParameter($mailConfig, self::PARAMETER_TRANSPORT), $message);
    }

    /**
     * @param string $templateName
     * @param array $customParameters
     * @return array
     * @throws InvalidArgumentException if prepared mail is not found
     */
    protected function getMailConfig($templateName, array $customParameters)
    {
        if (!array_key_exists($templateName, $this->preparedMails)) {
            throw new InvalidArgumentException('Prepared mail is not found');
        }

        return array_merge($this->preparedMails[$templateName], $customParameters);
    }

    /**
     * @param string $xmlStr
     * @return array
     * @throws RuntimeException if has libxml parse error
     */
    protected function parseXmlTemplate($xmlStr)
    {
        libxml_use_internal_errors(true);

        $xmlTemplate = simplexml_load_string($xmlStr);

        if (false === $xmlTemplate) {
            throw new RuntimeException(libxml_get_last_error()->message);
        }

        return array(
            (string)$xmlTemplate->subject,
            (string)$xmlTemplate->body
        );
    }

    /**
     * @param array $parameters
     * @param string $name
     * @return mixed
     * @throws RuntimeException if invalid mail configuration
     */
    protected function getMailParameter(array $parameters, $name)
    {
        if (!array_key_exists($name, $parameters)) {
            throw new RuntimeException(sprintf('Invalid prepared mail configuration: %s parameter is not found', $name));
        }

        return $parameters[$name];
    }

    /**
     * @param array $parameters
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    protected function getMailParameterOrDefault(array $parameters, $name, $default)
    {
        return array_key_exists($name, $parameters) ? $parameters[$name] : $default;
    }

    /**
     * @param string $transportName
     * @param Swift_Message $message
     * @return int
     */
    public function send($transportName, Swift_Message $message)
    {
        return $this->resolveTransport($transportName)->send($message);
    }

    /**
     * @param string $transportName
     * @return Swift_Mailer
     * @throws InvalidArgumentException if mail transport is not found
     */
    protected function resolveTransport($transportName)
    {
        if (!$this->hasTransport($transportName)) {
            throw new InvalidArgumentException('Mail transport is not found');
        }

        return $this->hasSpool($transportName) ? $this->getSpool($transportName) : $this->getTransport($transportName);
    }

    /**
     * @param string $transportName
     * @return bool
     */
    protected function hasTransport($transportName)
    {
        return $this->transports->has($transportName);
    }

    /**
     * @param string $transportName
     * @return Swift_Mailer
     */
    protected function getTransport($transportName)
    {
        return $this->transports->get($transportName);
    }

    /**
     * @param string $transportName
     * @return bool
     */
    protected function hasSpool($transportName)
    {
        return $this->spools->has($this->getSpoolNameByTransportName($transportName));
    }

    /**
     * @param string $transportName
     * @return Swift_Mailer
     */
    protected function getSpool($transportName)
    {
        return $this->spools->get($this->getSpoolNameByTransportName($transportName));
    }

    /**
     * @param int $messageLimit
     * @param int $timeLimit
     * @param int $recoverLimit
     */
    public function flush($messageLimit = 0, $timeLimit = 0, $recoverLimit = 0)
    {
        $messageLimit = (int)$messageLimit;
        $timeLimit    = (int)$timeLimit;
        $recoverLimit = (int)$recoverLimit;

        foreach ($this->spools->getServicesIds() as $spoolName) {
            /** @var Swift_Mailer $spoolMailer */
            $spoolMailer = $this->spools->get($spoolName);
            /** @var Swift_SpoolTransport $spoolTransport */
            $spoolTransport = $spoolMailer->getTransport();

            $spool = $spoolTransport->getSpool();

            if ($spool instanceof \Swift_ConfigurableSpool) {
                $spool->setMessageLimit($messageLimit);
                $spool->setTimeLimit($timeLimit);
            }

            if ($spool instanceof \Swift_FileSpool) {
                $spool->recover($recoverLimit);
            }

            $transportName    = $this->getTransportNameBySpoolName($spoolName);
            $transportHandler = $this->getTransport($transportName)->getTransport();

            $spool->flushQueue($transportHandler);
        }
    }

    /**
     * @param string $transportName
     * @return string
     */
    protected function getSpoolNameByTransportName($transportName)
    {
        return $transportName . '.spool';
    }

    /**
     * @param string $spoolName
     * @return string
     */
    protected function getTransportNameBySpoolName($spoolName)
    {
        return str_replace('.spool', '', $spoolName);
    }
}
