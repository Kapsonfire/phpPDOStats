<?php

namespace PHPPDOStats;

use PDO;
use PDOStatement;

class PDOStatementExtended extends PDOStatement
{

    private static $executes = [];

    /**
     * @var PDO
     */
    private $instance;

    /**
     * @var array $boundParams - array of replaced datas
     */
    private $boundParams = array();


    /**
     * @param PDO $pdoInstance
     */
    public static function init(PDO $pdoInstance)
    {
        $pdoInstance->setAttribute(PDO::ATTR_STATEMENT_CLASS, [static::class, [$pdoInstance]]);
    }

    private $createStacktrace;
    protected function __construct(PDO $pdoInstance)
    {
        $this->createStacktrace = debug_backtrace();
        $this->instance = $pdoInstance;
    }

    /**
     * @return array
     */
    public static function getExecutes(): array
    {
        return self::$executes;
    }

    public function execute($input_parameters = null)
    {
        $start = microtime(true);
        parent::execute($input_parameters); // TODO: Change the autogenerated stub
        $elapsed = microtime(true) - $start;
        $this->setSendString();
        $this->addExecuteData($this->_sendString, $elapsed, $this->errorInfo(), $this->rowCount());
    }


    private function addExecuteData(string $query, float $elapsed, array $errors, int $affectedRows)
    {
        $data = [
            'created' => microtime(true),
            'query' => $query,
            'elapsed' => $elapsed,
            'errors' => $errors,
            'affectedRows' => $affectedRows,
            'stacktrace' => debug_backtrace(),
            'createStacktrace' => $this->createStacktrace
        ];
        self::$executes[] = $data;
    }

    public function bindParam($parameter, &$variable, $data_type = PDO::PARAM_STR, $length = null, $driver_options = null)
    {
        $this->boundParams[$parameter] = [
            'value' => &$variable,
            'type' => $data_type
        ];
        return parent::bindParam($parameter, $variable, $data_type, $length, $driver_options);
    }

    public function bindValue($parameter, $value, $data_type = PDO::PARAM_STR)
    {
        $this->boundParams[$parameter] = [
            'value' => $value,
            'type' => $data_type
        ];
        return parent::bindValue($parameter, $value, $data_type);
    }


    /**
     * @var String null
     */
    private $_sendString = '';

    /**
     * @return String
     */
    public function getSendString(): String
    {
        return $this->_sendString;
    }


    private function prepareParam($param)
    {
        if ($param['value'] == null) {
            return 'NULL';
        }
        if ($param['type'] === PDO::PARAM_INT) {
            return (int) $param['value'];
        }

        if ($this->instance instanceof PDO) {
            return $this->instance->quote($param['value'], $param['type']);
        }



        return "'" . addslashes($param['value']) . "'";


    }

    private function replaceMarker(string $queryString, string $replaceKey, string $replaceValue)
    {

        if (is_numeric($replaceKey)) {
            $replaceKey = "\?";
        } else {
            //no need for regex if we just need to check first char
            $replaceKey = $replaceKey[0] === ':' ? $replaceKey : ":" . $replaceKey;
        }

        $testParam = "/({$replaceKey}(?!\w))(?=(?:[^\"']|[\"'][^\"']*[\"'])*$)/";
        // Back references may be replaced in the resultant interpolatedQuery, so we need to sanitize that syntax
        $cleanBackRefCharMap = ['%' => '%%', '$' => '$%', '\\' => '\\%'];
        $backReferenceSafeReplValue = strtr($replaceValue, $cleanBackRefCharMap);
        $interpolatedString = preg_replace($testParam, $backReferenceSafeReplValue, $queryString, 1);
        return strtr($interpolatedString, array_flip($cleanBackRefCharMap));
    }

    public function setSendString()
    {

        $query = $this->queryString;
        $params = $this->boundParams;
        uksort($params, function ($b, $a) {
            return strlen($a) - strlen($b); //sort by length DESC
        });

        foreach ($params as $key => $value) {
            $replaceValue = $this->prepareParam($value);
            $replaceKey = $key;
            $query = $this->replaceMarker($query, $replaceKey, $replaceValue);
        }

        $this->_sendString = $query;
    }


}