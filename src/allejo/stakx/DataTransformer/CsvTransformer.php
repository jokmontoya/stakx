<?php

/**
 * @copyright 2017 Vladimir Jimenez
 * @license   https://github.com/allejo/stakx/blob/master/LICENSE.md MIT
 */

namespace allejo\stakx\DataTransformer;

class CsvTransformer implements DataTransformerInterface
{
    /**
     * {@inheritdoc}
     */
    public static function transformData($content)
    {
        $rows = array_map('str_getcsv', explode("\n", trim($content)));
        $columns = array_shift($rows);
        $csv = [];

        foreach ($rows as $row)
        {
            $csv[] = array_combine($columns, $row);
        }

        return $csv;
    }

    /**
     * {@inheritdoc}
     */
    public static function getExtensions()
    {
        return [
            'csv',
        ];
    }
}