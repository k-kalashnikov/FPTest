<?php

namespace FpDbTest;

use Exception;
use mysqli;

class Database implements DatabaseInterface
{
    private mysqli $mysqli;

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    public function buildQuery(string $query, array $args = []): string
    {
        if(self::isValidQueryBlock($query, $args) === false) {
            return "";
        }

        $result = '';
        $parts = preg_split('/(\?\#|\?\w|\{[^\{\}]+\}|\?)/', $query, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);



        foreach ($parts as $part) {

            if(preg_match('/^\?$/', $part)) {
                $param = array_shift($args);

                $result .= self::formatValue($param);
            }
            elseif (preg_match('/^\?\#$/', $part)) {
                $param = array_shift($args);

                if (is_array($param)) {
                    $result .= implode(', ', array_map([__CLASS__, 'formatIdentifier'], $param));
                } else {
                    $result .= self::formatIdentifier($param);
                }

            } elseif (preg_match('/^\?(\w)$/', $part, $matches)) {
                $param = array_shift($args);
                $specifier = $matches[1];

                switch ($specifier) {
                    case 'd':
                        $result .= $param === null ? 'NULL' : intval($param);
                        break;
                    case 'f':
                        $result .= $param === null ? 'NULL' : floatval($param);
                        break;
                    case 'a':
                        if (!is_array($param)) {
                            throw new \InvalidArgumentException("Expected an array for ?a specifier.");
                        }
                        $assoc = array_keys($param) !== range(0, count($param) - 1);
                        if ($assoc) {
                            $result .= implode(', ', array_map(
                                fn($k, $v) => self::formatIdentifier($k) . ' = ' . self::formatValue($v),
                                array_keys($param),
                                $param
                            ));
                        } else {
                            $result .= implode(', ', array_map([__CLASS__, 'formatValue'], $param));
                        }
                        break;
                    default:
                        $result .= self::formatValue($param);
                        break;
                }

            } elseif (preg_match('/^\{(.+)\}$/', $part, $matches)) {

                $subquery = $matches[1];
                $subresult = self::buildQuery($subquery, $args);

                if (strpos($subresult, self::skip()) === false) {
                    $result .= $subresult;
                }

            } else {
                $result .= $part;
            }
        }

        return $result;
    }

    public function skip()
    {
        return '__SKIP__';
    }

    private function formatValue($value): string {
        if ($value === null) {
            return 'NULL';
        } elseif (is_int($value) || is_float($value)) {
            return (string)$value;
        } elseif (is_bool($value)) {
            return $value ? '1' : '0';
        } elseif (is_string($value)) {
            return "'" . addslashes($value) . "'";
        } else {
            throw new \InvalidArgumentException("Invalid parameter type.");
        }
    }

    private function formatIdentifier($identifier): string {
        if (!is_string($identifier)) {
            throw new \InvalidArgumentException("Identifiers must be strings.");
        }
        return "`" . str_replace("`", "``", $identifier) . "`";
    }

    private function isValidQueryBlock(string $query, array $args = []): bool { 
        
        preg_match('/(\?)/', $query, $checkMatches);
        $checkArgs = array_slice($args, 0, count($checkMatches));

        if(in_array(self::skip(), $checkArgs, true) !== false) {
            return false;
        }

        return true;
    }
}
