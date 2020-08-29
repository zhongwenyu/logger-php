<?php
namespace ybrenLib\logger\driver\flume;

use ybrenLib\logger\bean\ILoggingEvent;
use ybrenLib\logger\core\ClassicConverter;
use ybrenLib\logger\driver\flume\bean\FlumeLogBody;
use ybrenLib\logger\driver\flume\bean\FlumeLogSet;
use ybrenLib\logger\driver\flume\bean\LogType;
use ybrenLib\logger\utils\ConfigUtil;
use ybrenLib\logger\utils\MDC;
use ybrenLib\logger\utils\StringUtil;
use ybrenLib\logger\utils\TimeUtil;

class JsonLogConverter implements ClassicConverter {

    private $logFormat = "%s{<n>}%s{<n>}";
    private $indexTypeName = "_doc";
    private $bulkIndexName = "index";
    private $logContentFormat = "%s %s [%s]: %s";
    private $sqlContentFormat = "%s : %s";

    private static $localIp = "";
    
    public function convert(ILoggingEvent $iLoggingEvent) {
        $flumeLogBody = new FlumeLogBody();
        $content = "";
        $logType = MDC::get(FlumeLogConstants::$LogType);
        if($logType == null){
            $logType = FlumeLogConstants::$DefaultLogType;
        }
        $requestTime = MDC::get(FlumeLogConstants::$RequestTime);
        if($logType == LogType::$Request && $requestTime != null){
            $addTime = $requestTime;
        }else{
            $addTime = TimeUtil::getTimestamp();
        }
        if($logType == LogType::$Response && $requestTime != null){
            $flumeLogBody->setDuration($addTime - $requestTime);
        }
        $flumeLogBody->setAddTime($addTime);
        $requestId = MDC::get(FlumeLogConstants::$RequestId);
        if($requestId == null){
            $requestId = StringUtil::getRandomStr();
        }
        $requestUri = MDC::get(FlumeLogConstants::$RequestURI);
        if($requestUri == null){
            $requestUri = strtolower($iLoggingEvent->getLoggerName());
        }
        $traceId = MDC::get(FlumeLogConstants::$TraceId);
        if($traceId != null){
            $flumeLogBody->setTraceId($traceId);
        }
        $appName = MDC::get(FlumeLogConstants::$AppName);
        if($appName != null){
            $flumeLogBody->setAppName($appName);
        }
        $level = MDC::get(FlumeLogConstants::$Level);
        if($level != null){
            $flumeLogBody->setLevel($level);
        }
        $indexName = MDC::get(FlumeLogConstants::$IndexName);
        if($indexName == null){
            $indexName = sprintf(FlumeLogConstants::$IndexNameFormat , ConfigUtil::getAppName
            ($iLoggingEvent->getConfig()) , strtolower($logType) ,
                date("Ym"));
        }else{
            $indexName = sprintf($indexName , strtolower($logType));
        }
        $flumeLogBody->setVar1(MDC::get("var1"));
        $flumeLogBody->setVar2(MDC::get("var2"));
        $flumeLogBody->setVar3(MDC::get("var3"));
        $flumeLogBody->setInstanceId($this->getLocalIp());
        $message = $iLoggingEvent->getFormattedMessage();
        switch($logType){
            case LogType::$Log:
                $flumeLogBody->setVar3($iLoggingEvent->getLevel());
                $content = sprintf($this->logContentFormat , 
                        TimeUtil::formatTimestamp($iLoggingEvent->getTimestamp()) ,
                        $iLoggingEvent->getLevel()  , $iLoggingEvent->getLoggerName() , $message);
                break;
            case LogType::$Sql:
                $content = sprintf($this->sqlContentFormat ,
                    TimeUtil::formatTimestamp($iLoggingEvent->getTimestamp()) , $message);
                break;
            default:
                $content = $message;
        }
        $content = urlencode($content);
        $flumeLogBody->setContent($content);
        $flumeLogBody->setIp(MDC::get(FlumeLogConstants::$ClientIp));
        $flumeLogBody->setLogType($logType);
        $flumeLogBody->setRequestUri($requestUri);
        $flumeLogBody->setRequestId($requestId);

        if(defined("LOCAL_HOST")){
            $instanceId = LOCAL_HOST;
        }else{
            $instanceId = $this->getLocalIp();
        }
        $flumeLogBody->setInstanceId($instanceId);

        $flumeLogSet = new FlumeLogSet();
        $flumeLogSet->set_index($indexName);
        $flumeLogSet->set_type($this->indexTypeName);
        $flumeLogSet->set_id(StringUtil::getRandomStr());
        $flumeLogSet->setRouting($requestUri);
        $indexParam = [
            $this->bulkIndexName => $flumeLogSet
        ];
        $flumeLogBodyArray = $flumeLogBody->toArray();
        $flumeLogBodyArray['@timestamp'] = date("Y-m-d H:i:s");

        return sprintf($this->logFormat , json_encode($indexParam) , json_encode($flumeLogBodyArray));
    }

    /**
     * 获取内网ip
     * @return string
     */
    private function getLocalIp(){
        $yac = null;
        $yacKey = "localIpInfoCache";
        if(class_exists("Yac")){
            $yac = new \Yac();
            $localIpInfo = $yac->get($yacKey);
            if(!empty($localIpInfo)){
                $localIpInfoArr = json_decode($localIpInfo , true);
                if(!empty($localIpInfoArr['ip']) && $localIpInfoArr['timeout'] > time()){
                    return $localIpInfoArr["ip"];
                }
            }
        }
        if(!empty(self::$localIp)){
            return self::$localIp;
        }

        $localIp = "";
        if(strtoupper(substr(PHP_OS,0,3))==='WIN'){
            $localIp = "127.0.0.1";
        }else{
            $netInfo = [];
            exec("/sbin/ifconfig" , $netInfo);
            if(!empty($netInfo)){
                $eth0 = false;
                foreach ($netInfo as $str){
                    if(strpos($str , 'eth0') !== false){
                        $eth0 = true;
                        continue;
                    }
                    if($eth0){
                        $regex= '/.*(inet addr:(.*)Bcast:(.*)Mask:(.*)).*/';
                        $arr = [];
                        $num_matches = preg_match($regex, $str , $arr);
                        $localIp = trim($arr[2] ?? "");
                        break;
                    }
                }
            }
        }

        if(!empty($localIp)){
            if(class_exists("Yac")){
                $localIpInfoArr = [
                    "ip" => $localIp,
                    "timeout" => time() + 3600
                ];
                $yac->set($yacKey , json_encode($localIpInfoArr));
            }else{
                self::$localIp = $localIp;
            }
        }
        return $localIp;
    }
}


