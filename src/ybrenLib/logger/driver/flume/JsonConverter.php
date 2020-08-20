<?php
namespace ybrenLib\logger\driver\flume;

use ybrenLib\logger\bean\ILoggingEvent;
use ybrenLib\logger\core\ClassicConverter;

class JsonConverter implements ClassicConverter {

    public function convert(ILoggingEvent $iLoggingEvent) {
        return $iLoggingEvent->getFormattedMessage();
    }
}