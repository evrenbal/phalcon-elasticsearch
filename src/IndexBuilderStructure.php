<?php

namespace Baka\Elasticsearch;

use Exception;
use \Phalcon\Mvc\Model;

class IndexBuilderStructure extends IndexBuilder
{
    /**
     * Run checks to avoid unwanted errors.
     *
     * @param string $model
     *
     * @return string
     */
    protected static function checks(string $model) : string
    {
        // Call the initializer.
        self::initialize();

        // Check that there is a configuration for namespaces.
        if (!$config = self::$di->getConfig()->get('namespace')) {
            throw new Exception('Please add your namespace definitions to the configuration.');
        }

        // Check that there is a namespace definition for modules.
        if (!array_key_exists('models', $config)) {
            throw new Exception('Please add the namespace definition for your models.');
        }

        // Get the namespace.
        $namespace = $config['models'];

        // We have to do some work with the model name before we continue to avoid issues.
        $model = str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $model)));

        // Check that the defined model actually exists.
        if (!class_exists($model = $namespace . '\\' . $model)) {
            throw new Exception('The specified model does not exist.');
        }

        return $model;
    }

    /**
     * Save the object to an elastic index.
     *
     * @param Model $object
     * @param int $maxDepth
     *
     * @return array
     */
    public static function indexDocument(Model $object, int $maxDepth = 3) : array
    {
        // Call the initializer.
        self::initialize();

        // Use reflection to extract neccessary information from the object.
        $modelReflection = (new \ReflectionClass($object));
        $document = $object->document();

        $params = [
            'index' => strtolower($modelReflection->getShortName()),
            'type' => strtolower($modelReflection->getShortName()),
            'id' => $object->getId(),
            'body' => $document,
        ];

        return self::$client->index($params);
    }

    /**
     * Delete a document from Elastic
     *
     * @param Model $object
     * @return array
     */
    public static function deleteDocument(Model $object) : array
    {
        // Call the initializer.
        self::initialize();

        // Use reflection to extract neccessary information from the object.
        $modelReflection = (new \ReflectionClass($object));
        $object->document();

        $params = [
            'index' => strtolower($modelReflection->getShortName()),
            'type' => strtolower($modelReflection->getShortName()),
            'id' => $object->getId(),
        ];

        return self::$client->delete($params);
    }

    /**
     * Create an index for a model
     *
     * @param string $model
     * @param int $maxDepth
     *
     * @return array
     */
    public static function createIndices(string $model, int $maxDepth = 3, int $nestedLimit = 75) : array
    {
        // Run checks to make sure everything is in order.
        $modelPath = self::checks($model);

        // We need to instance the model in order to access some of its properties.
        $modelInstance = new $modelPath();

        // Get the model's table structure.
        $columns = $modelInstance->structure();

        // Set the model variable for use as a key.
        $model = strtolower(str_replace(['_', '-'], '', $model));

        // Define the initial parameters that will be sent to Elasticsearch.
        $params = [
            'index' => $model,
            'body' => [
                'settings' => [
                    'index.mapping.nested_fields.limit' => $nestedLimit,
                    'max_result_window' => 50000,
                    'index.query.bool.max_clause_count' => 1000000,
                    'analysis' => [
                        'analyzer' => [
                            'lowercase' => [
                                'type' => 'custom',
                                'tokenizer' => 'keyword',
                                'filter' => ['lowercase'],
                            ],
                        ],
                    ],
                ],
                'mappings' => [
                    $model => [
                        'properties' => [],
                    ],
                ],
            ],
        ];

        // Iterate each column to set it in the index definition.
        foreach ($columns as $column => $type) {
            if (is_array($type) && isset($type[0])) {
                // Remember we used an array to define the types for dates. This is the only case for now.
                $params['body']['mappings'][$model]['properties'][$column] = [
                    'type' => $type[0],
                    'format' => $type[1],
                ];
            } elseif (!is_array($type)) {
                $params['body']['mappings'][$model]['properties'][$column] = ['type' => $type];

                if ($type == 'string') {
                    $params['body']['mappings'][$model]['properties'][$column]['analyzer'] = 'lowercase';
                }
            } else {
                //nested
                self::mapNestedProperties($params['body']['mappings'][$model]['properties'], $column, $type);
            }
        }

        // Delete the index before creating it again
        // @TODO move this to its own function
        if (self::$client->indices()->exists(['index' => $model])) {
            self::$client->indices()->delete(['index' => $model]);
        }

        return self::$client->indices()->create($params);
    }

    /**
     * Map the neste properties of a index by using recursive calls
     *
     * @todo we are reusing this code on top so we must find a better way to handle it @kaioken
     *
     * @param array $params
     * @param string $column
     * @param array $columns
     * @return void
     */
    protected static function mapNestedProperties(array &$params, string $column, array $columns): void
    {
        $params[$column] = ['type' => 'nested'];

        foreach ($columns as $innerColumn => $type) {
            // For now this is only being used for date/datetime fields
            if (is_array($type) && isset($type[0])) {
                $params[$column]['properties'][$innerColumn] = [
                    'type' => $type[0],
                    'format' => $type[1],
                ];
            } elseif (!is_array($type)) {
                $params[$column]['properties'][$innerColumn] = ['type' => $type];

                if ($type == 'string') {
                    $params[$column]['properties'][$innerColumn]['analyzer'] = 'lowercase';
                }
            } else {
                self::mapNestedProperties($params[$column]['properties'], $innerColumn, $type);
            }
        }
    }
}