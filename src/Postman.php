<?php

namespace AttractCores\PostmanDocumentation;

use AttractCores\PostmanDocumentation\Factory\FormFactory;
use AttractCores\PostmanDocumentation\Macros\RouteCallbacks;
use AttractCores\PostmanDocumentation\Macros\RouteResourceCallbacks;
use Illuminate\Routing\PendingResourceRegistration;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * Class Postman
 *
 * @package AttractCores\PostmanDocumentation
 * Date: 01.12.2021
 * Version: 1.0
 * Author: Yure Nery <yurenery@gmail.com>
 */
class Postman
{

    use RouteCallbacks, RouteResourceCallbacks;

    /**
     * Determine that postman run docs compilation
     *
     * @var bool
     */
    protected static bool $compiling = false;

    /**
     * Postman export command config.
     *
     * @var array
     */
    protected array $config;

    /**
     * Form factories interface class.
     *
     * @var FormFactory
     */
    protected FormFactory $formFactory;

    /**
     * Postman constructor.
     *
     * @param array $config
     *
     * @throws \ReflectionException
     */
    public function __construct(array $config)
    {
        $this->config = $config;

        // Initialize postman interface class.
        $this->formFactory = app(FormFactory::class);
        $this->formFactory->load($this->config[ 'factories_path' ]);
    }

    /**
     * Mark postman as running.
     *
     * @return void
     */
    public static function startCompilation()
    {
        static::$compiling = true;
    }

    /**
     * Determine that postman processing docs compilation
     *
     * @return bool
     */
    public static function isCompiling():bool
    {
        return static::$compiling;
    }

    /**
     * Mark postman as compilation finished.
     *
     * @return void
     */
    public static function finished()
    {
        static::$compiling = false;
    }

    /**
     * Return postman request item.
     *
     * @param PostmanRoute $route
     * @param string       $method
     * @param array        $routeHeaders
     * @param array        $fakedFields
     *
     * @param string|null  $personalBearer
     *
     * @return array
     */
    public function makeRequest(
        PostmanRoute $route, string $method, array $routeHeaders,
        array $fakedFields = [], ?string $personalBearer = NULL
    )
    {
        $method = strtoupper($method);

        $data = [
            'name'    => Route::hasMacro('getAliasedName') && $route->getAliasedName() ?
                $route->getAliasedName() : ( $route->getName() ?? $route->uri() ),
            'request' => [
                'method'      => $method,
                'header'      => $routeHeaders,
                'auth'        => $route->getRouteAuthPostmanStructure($personalBearer),
                'url'         => [
                    'raw'  => '{{base_url}}/' . $route->uri(),
                    'host' => [ "{{base_url}}" ],
                    'path' => explode('/', $route->uri()),
                ],
                'description' => Route::hasMacro('compileDocs') ? $route->compileDocs() : '',
            ],
        ];

        if ( $fakedFields ) {
            if ( $method == 'GET' ) {
                $query = urldecode(http_build_query($fakedFields));
                $data[ 'request' ][ 'url' ][ 'raw' ] .= '?' . $query;

                $data[ 'request' ][ 'url' ][ 'query' ] = collect($fakedFields)
                    ->map(fn($value, $key) => [ 'key' => $key, 'value' => $value ])
                    ->values()->all();
            } else {
                $data[ 'request' ][ 'body' ] = [
                    'mode'    => 'raw',
                    'raw'     => json_encode($fakedFields, JSON_PRETTY_PRINT),
                    'options' => [
                        'raw' => [ 'language' => 'json' ],
                    ],
                ];
            }
        }

        return $data;
    }

    /**
     * Return initialized structure.
     *
     * @return array[]
     */
    public function getInitializedStructure(string $fileName = NULL) : array
    {
        return [
            'variable' => [
                [
                    'key'   => 'base_url',
                    'value' => rtrim($this->config[ 'base_url' ], '/'),
                ],
                [
                    'key'   => 'oauth_full_url',
                    'value' => '{{base_url}}' .
                               ( $this->config[ 'oauth_route' ] ? route($this->config[ 'oauth_route' ], [], false) :
                                   '' ),
                ],
            ],
            'info'     => [
                'name'        => $fileName,
                'schema'      => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
                'description' => sprintf('Generated by AttractGroup %s', now()->format('d D l, Y H:i:s e P')),
            ],
            'item'     => [],
        ];
    }

    /**
     * Return structures steps for tree building.
     *
     * @param PostmanRoute $route
     *
     * @return array
     */
    public function getStructuredSteps(PostmanRoute $route) : array
    {
        $routeNames = $route->getAction('as') ?? NULL;

        if ( ! $routeNames ) {
            $routeNames = explode('/', $route->uri());
        } else {
            $routeNames = explode('.', $routeNames);

            if ( Route::hasMacro('structureDepth') && $depth = $route->getStructureDepth() ) {
                $routeNames = array_slice($routeNames, 0, $depth);
            } else {
                array_pop($routeNames);
            }
        }

        return array_filter($routeNames, function ($value) {
            return ! is_null($value) && $value !== '';
        });
    }

    /**
     * Build structured tree for postman request items.
     *
     * @param array $routes
     * @param array $segments
     * @param array $request
     */
    public function buildTree(array &$routes, array $segments, array $request) : void
    {
        $parent = &$routes;
        $destination = end($segments);

        foreach ( $segments as $segment ) {
            $matched = false;

            foreach ( $parent[ 'item' ] as &$item ) {
                if ( $item[ 'name' ] === $segment ) {
                    $parent = &$item;

                    if ( $segment === $destination ) {
                        $parent[ 'item' ][] = $request;
                    }

                    $matched = true;

                    break;
                }
            }

            unset($item);

            if ( ! $matched ) {
                $item = [
                    'name' => $segment,
                    'item' => $segment === $destination ? [ $request ] : [],
                ];

                $parent[ 'item' ][] = &$item;
                $parent = &$item;
            }

            unset($item);
        }
    }

    /**
     * Return fake data for given reflection method request or return empty array if boyd should be empty.
     *
     * @param string                     $requestMethod
     * @param PostmanRoute               $route
     *
     * @param \Illuminate\Routing\Router $router
     *
     * @return array
     * @throws \ReflectionException
     */
    public function getFakeFormData(Router $router, PostmanRoute $route, string $requestMethod)
    {
        $fakedFields = [];

        if ( $this->config[ 'enable_formdata' ] ) {
            $fakedFields = $this->formFactory->getFormData(
                $route, $requestMethod,
                $route->getRouteFormRequestClass($router)
            );
        }

        return $fakedFields;
    }

    /**
     * Initialize Illuminate\\Routing\\Route with new functionality.
     */
    public static function initialize()
    {
        Route::macro('aliasedName', static::aliasedNameCallback());
        Route::macro('getAliasedName', static::getAliasedNameCallback());
        Route::macro('expands', static::expandsCallback());
        Route::macro('scopes', static::scopesCallback());
        Route::macro('description', static::descriptionCallback());
        Route::macro('docPattern', static::docPatternCallback());
        Route::macro('compileDocs', static::compileDocsCallback());
        Route::macro('structureDepth', static::structureDepthCallback());
        Route::macro('getStructureDepth', static::getStructureDepthCallback());
        PendingResourceRegistration::macro('postman', static::postmanCallback());
    }

    /**
     * @param string $modelClass
     * @param string $hookMethod
     * @param array  $default
     *
     * @return array|mixed
     */
    public static function callModelHook(string $modelClass, string $hookMethod, $default = [])
    {
        $model = app($modelClass);

        return method_exists($model, $hookMethod) ? $model->$hookMethod() : $default;
    }

}
