<?php
namespace PSFS\base\types\traits;

use PSFS\base\Logger;
use PSFS\base\types\traits\Helper\OptionTrait;
use PSFS\base\types\traits\Helper\ParameterTrait;

/**
 * Trait CurlTrait
 * @package PSFS\base\types\traits
 */
trait CurlTrait {
    use ParameterTrait;
    use OptionTrait;
    /**
     * Curl resource
     * @var resource $con
     */
    protected $con;
    /**
     * Curl destination
     * @var string
     */
    private $url;
    /**
     * Curl headers
     * @var array
     */
    private $headers;
    /**
     * Curl http verb
     * @var string
     */
    private $type;

    /**
     * @return resource
     */
    public function getCon()
    {
        return $this->con;
    }

    /**
     * @param resource $con
     */
    public function setCon($con)
    {
        $this->con = $con;
    }
    /**
     * @var string $result
     */
    private $result;
    /**
     * @var mixed
     */
    private $info = [];
    /**
     * @var bool
     */
    protected $isJson = true;
    /**
     * @var bool
     */
    protected $isMultipart = false;

    protected function closeConnection() {
        if(null !== $this->con) {
            if(is_resource($this->con)) {
                curl_close($this->con);
            } else {
                $this->setCon(null);
            }
        }
    }

    public function __destruct()
    {
        $this->closeConnection();
    }

    private function clearContext()
    {
        $this->url = NULL;
        $this->params = array();
        $this->headers = array();
        Logger::log('Context service for ' . static::class . ' cleared!');
        $this->closeConnection();
    }

    private function initialize()
    {
        $this->closeConnection();
        $this->params = [];
        $con = curl_init($this->url);
        if(is_resource($con)) {
            $this->setCon($con);
        }
    }

    /**
     * @return String
     */
    public function getUrl()
    {
        return $this->url;
    }

    /**
     * @param String $url
     * @param bool $cleanContext
     */
    public function setUrl($url, $cleanContext = true)
    {
        $this->url = $url;
        if($cleanContext) {
            $this->initialize();
        }
    }

    /**
     * @return string
     */
    public function getResult()
    {
        return $this->result;
    }

    /**
     * @param string $result
     */
    public function setResult($result)
    {
        $this->result = $result;
    }

    /**
     * @return array
     */
    public function getHeaders()
    {
        return $this->headers;
    }

    /**
     * @param array $headers
     */
    public function setHeaders($headers)
    {
        $this->headers = $headers;
    }

    /**
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * @param string $type
     */
    public function setType($type)
    {
        $this->type = $type;
    }

    /**
     * @return mixed
     */
    public function getInfo() {
        return $this->info;
    }

    /**
     * @param bool $isJson
     */
    public function setIsJson($isJson = true) {
        $this->isJson = $isJson;
        if($isJson) {
            $this->setIsMultipart(false);
        }
    }

    /**
     * @return bool
     */
    public function getIsJson() {
        return $this->isJson;
    }

    /**
     * @param bool $isMultipart
     */
    public function setIsMultipart($isMultipart = true) {
        $this->isMultipart = $isMultipart;
        if($isMultipart) {
            $this->setIsJson(false);
        }
    }

    /**
     * @return bool
     */
    public function getIsMultipart() {
        return $this->isMultipart;
    }
}
