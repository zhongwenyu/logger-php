<?php
namespace ybrenLib\logger\core;

use ybrenLib\logger\bean\ILoggingEvent;

interface ClassicConverter{

    function convert(ILoggingEvent $ILoggingEvent);
}