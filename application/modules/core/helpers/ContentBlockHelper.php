<?php
namespace app\modules\core\helpers;

use app\modules\core\models\ContentBlock;
use devgroup\TagDependencyHelper\ActiveRecordHelper;
use yii\caching\TagDependency;
use yii\helpers\ArrayHelper;
use yii;
use app;

/**
 * Class ContentBlockHelper
 * Main public static method compileContentString() using submethods to extract chunk calls from model content field,
 * fetch chunks from data base table, then compile it and replace chunk calls with compiled chunks data
 * Example chunk call in model content field should be like: [[$chunk param='value'|'default value' param2=42]].
 * Chunk declaration should be like : <p>String: [[+param]]</p> <p>Float: [[+param2:format, param1, param2]]</p>
 * All supported formats you can find at Yii::$app->formatter
 *
 * @package app\modules\core\helpers
 */
class ContentBlockHelper
{
    private static $chunksByKey;
    /**
     * Compiles content string by injecting chunks into content
     * Preloads chunks which have preload = 1
     * Finding chunk calls with regexp
     * Iterate matches
     * Whyle iterate:
     * Extracting single chunk data with sanitizeChunk() method
     * Fetching chunk by key using fetchChunkByKey(), who returns chunk value by key from static array if exists, otherwise from db
     * Compile single chunk using compileChunk() method
     * Replacing single chunk call with compiled chunk data in the model content
     *
     * @param  {string} $content Original content with chunk calls
     * @param  {string} $content_key Key for caching compiled content version
     * @param  {yii\caching\Dependency} $dependency  Cache dependency
     * @return {string} Compiled content with injected chunks
     */
    public static function compileContentString($content, $content_key, $dependency)
    {
        self::preloadChunks();
        $matches = [];
        preg_match_all('%\[\[([^\]\[]+)\]\]%ui', $content, $matches);
        if (!empty($matches)) {
            foreach ($matches[0] as $k => $rawChunk) {
                $chunkData = static::sanitizeChunk($rawChunk);
                $cacheChunkKey = $chunkData['key'] . $content_key;
                $replacement = Yii::$app->cache->get($cacheChunkKey);
                if ($replacement === false) {
                    $chunk = self::fetchChunkByKey($chunkData['key']);
                    $replacement = static::compileChunk($chunk, $chunkData);
                    if (null !== $chunk) {
                        Yii::$app->cache->set(
                            $cacheChunkKey,
                            $replacement,
                            84600,
                            $dependency
                        );
                    }
                }
                $content = str_replace($matches[0][$k], $replacement, $content);
            }
        }
        return $content;
    }

    /**
     * Extracting chunk data from chunk call
     * first preg_match is to extract chunk key
     * next preg_match_all using named groups to find params data in the chunk call :
     * [[$chunk <paramName>='<escapedValue>'|'<escapedDefault>' <paramName>=<unescapedValue>|<unescapedDefault>]]
     * iterate matches.
     * While iterating converts escapedValue and escapedDefault into string, unescapedValue and unescapedDefault - into float
     * Returns chunk data array like:
     *  [
     *      'key' => 'chunkKey',
     *      'firstParam'=> 'string value',
     *      'firstParam-default'=> 'default string value',
     *      'secondParam'=> float value,
     *      'secondParam-default'=> default float value,
     *  ]
     *
     * @param $rawChunk
     * @return array
     */
    private static function sanitizeChunk($rawChunk)
    {
        $chunk = [];
        preg_match('%\$([^\s\]]+)[\s\]]%', $rawChunk, $keyMatches);
        $chunk['key'] = $keyMatches[1];
        $expression = "#\s*(?P<paramName>[\\w\\d]*)=(('(?P<escapedValue>.*[^\\\\])')|(?P<unescapedValue>.*))(\\|(('(?P<escapedDefault>.*[^\\\\])')|(?P<unescapedDefault>.*)))?[\\]\\s]#uUi";
        preg_match_all($expression, $rawChunk, $matches);
        foreach ($matches['paramName'] as $key => $paramName) {
            if (!empty($matches['escapedValue'][$key])) {
                $chunk[$paramName] = strval($matches['escapedValue'][$key]);
            }
            if (!empty($matches['unescapedValue'][$key])) {
                $chunk[$paramName] = floatval($matches['unescapedValue'][$key]);
            }
            if (!empty($matches['escapedDefault'][$key])) {
                $chunk[$paramName . '-default'] = strval($matches['escapedDefault'][$key]);
            }
            if (!empty($matches['unescapedDefault'][$key])) {
                $chunk[$paramName . '-default'] = floatval($matches['unescapedDefault'][$key]);
            }
        }
        return $chunk;
    }

    /**
     * @param  {ContentBlock} $chunk     ContentBlock instance
     * @param  {array} $arguments Arguments for this chunk from original content
     * @return {string} Result string ready for replacing
     *
     * Compiles single chunk
     * using preg_match_all to find placeholders and extract it's data from chunk value field
     * regexp using named groups to find and extract placeholders data like:
     * [[<token><paramName>:<format><params>]]
     * token switch is for future functionality increase
     * now method only recognizes + token and replacing following param with according $arguments array data
     * applying formatter according previously defined param values type if needed
     * if param name from placeholder was not found in arguments array, placeholder in the compiled chunk will be replaced with empty string
     * returns compiled chunk
     */
    public static function compileChunk($chunk, $arguments)
    {
        $matches = [];
        preg_match_all('%\[\[(?P<token>[\+\*\%])(?P<paramName>[^\s\:\]]+)\:?(?P<format>[^\,\]]+)?\,?(?P<params>[^\]]+)?\]\]%ui', $chunk, $matches);
        foreach ($matches[0] as $k => $rawParam) {
            $token = $matches['token'][$k];
            $paramName = $matches['paramName'][$k];
            $format = $matches['format'][$k];
            $params = explode(',', $matches['params'][$k]);
            switch ($token) {
                case '+':
                    if (array_key_exists($paramName, $arguments)) {
                        $replacement = static::applyFormatter($arguments[$paramName], $format, $params);
                        $chunk = str_replace($matches[0][$k], $replacement, $chunk);
                    } else if (array_key_exists($paramName . '-default', $arguments)) {
                        $replacement = static::applyFormatter($arguments[$paramName . '-default'], $format, $params);
                        $chunk = str_replace($matches[0][$k], $replacement, $chunk);
                    } else {
                        $chunk = str_replace($matches[0][$k], '', $chunk);
                    }
                    break;
                default:
                    $chunk = str_replace($matches[0][$k], '', $chunk);
            }
        }
        return $chunk;
    }

    /**
     * Find formatter declarations in chunk placeholders. if find trying to apply
     * yii\i18n\Formatter formats see yii\i18n\Formatter for details
     * @param {string} $rawParam single placeholder declaration from chunk
     * @param $format {string}
     * @param $params {array}
     * @return array
     */
    private static function applyFormatter($value, $format, $params)
    {
        if (empty($format)) {
            return $value;
        }
        $formattedValue = call_user_func_array([Yii::$app->formatter, $format], [$value, $params]);
        return $formattedValue;
    }

    /**
     * Fetches single chunk by key from static var
     * if is no there - get it from db and push to static array
     * @param $key {string} Chunk key field
     * @return {string} Chunk value field
     */
    public static function fetchChunkByKey($key)
    {
        if (!array_key_exists($key, static::$chunksByKey)) {
            $dependency = new TagDependency([
                'tags' => [
                    ActiveRecordHelper::getCommonTag(ContentBlock::className()),
                ]
            ]);
            static::$chunksByKey[$key] = ContentBlock::getDb()->cache(function($db) use ($key) {
                $chunk = ContentBlock::find()
                    ->where(['key' => $key])
                    ->asArray()
                    ->one();
                return static::$chunksByKey[$key] = $chunk['value'];
            }, 86400, $dependency);
        }
        return static::$chunksByKey[$key];
    }

    /**
     * preloading chunks with preload option set to 1
     * and push it to static array
     * @return array|void
     */
    public static function preloadChunks()
    {
        if (is_null(static::$chunksByKey)) {
            $dependency = new TagDependency([
                'tags' => [
                    ActiveRecordHelper::getCommonTag(ContentBlock::className()),
                ]
            ]);
            static::$chunksByKey = ContentBlock::getDb()->cache(function ($db) {
                $chunks = ContentBlock::find()
                    ->where(['preload' => 1])
                    ->asArray()
                    ->all();
                return ArrayHelper::map($chunks, 'key', 'value');
            }, 86400, $dependency);
        }
        return static::$chunksByKey;
    }
}