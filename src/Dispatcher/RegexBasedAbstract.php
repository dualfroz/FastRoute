<?php
declare(strict_types=1);

namespace FastRoute\Dispatcher;

use FastRoute\DataGenerator;
use FastRoute\Dispatcher;
use FastRoute\Dispatcher\Result\Matched;
use FastRoute\Dispatcher\Result\MethodNotAllowed;
use FastRoute\Dispatcher\Result\NotMatched;
use RuntimeException;

use function preg_last_error_msg;
use function preg_match;
use function sprintf;

/**
 * @internal
 *
 * @phpstan-import-type StaticRoutes from DataGenerator
 * @phpstan-import-type DynamicRouteChunk from DataGenerator
 * @phpstan-import-type DynamicRouteChunks from DataGenerator
 * @phpstan-import-type DynamicRoutes from DataGenerator
 * @phpstan-import-type RouteData from DataGenerator
 */
abstract class RegexBasedAbstract implements Dispatcher
{
    /** @var StaticRoutes */
    protected array $staticRouteMap = [];

    /** @var DynamicRoutes */
    protected array $variableRouteData = [];

    /** @param RouteData $data */
    public function __construct(array $data)
    {
        [$this->staticRouteMap, $this->variableRouteData] = $data;
    }

    /** @param DynamicRouteChunks $routeData */
    abstract protected function dispatchVariableRoute(array $routeData, string $uri): ?Matched;

    /**
     * Matches a route regex, distinguishing a genuine "no match" (preg_match()
     * returns 0) from a PCRE engine failure such as PREG_BACKTRACK_LIMIT_ERROR
     * (returns false), which would otherwise fail unrelated routes sharing the
     * same combined regex chunk.
     *
     * @param array<int|string, mixed>|null $matches
     */
    protected function matchRoute(string $regex, string $subject, ?array &$matches = null): int
    {
        $result = preg_match($regex, $subject, $matches);
        if ($result === false) {
            throw new RuntimeException(
                sprintf('Regex matching failed for "%s": %s', $regex, preg_last_error_msg()),
            );
        }

        return $result;
    }

    public function dispatch(string $httpMethod, string $uri): Matched|NotMatched|MethodNotAllowed
    {
        if (isset($this->staticRouteMap[$httpMethod][$uri])) {
            $result = new Matched();
            $result->handler = $this->staticRouteMap[$httpMethod][$uri][0];
            $result->extraParameters = $this->staticRouteMap[$httpMethod][$uri][1];

            return $result;
        }

        if (isset($this->variableRouteData[$httpMethod])) {
            $result = $this->dispatchVariableRoute($this->variableRouteData[$httpMethod], $uri);
            if ($result !== null) {
                return $result;
            }
        }

        // For HEAD requests, attempt fallback to GET
        if ($httpMethod === 'HEAD') {
            if (isset($this->staticRouteMap['GET'][$uri])) {
                $result = new Matched();
                $result->handler = $this->staticRouteMap['GET'][$uri][0];
                $result->extraParameters = $this->staticRouteMap['GET'][$uri][1];

                return $result;
            }

            if (isset($this->variableRouteData['GET'])) {
                $result = $this->dispatchVariableRoute($this->variableRouteData['GET'], $uri);
                if ($result !== null) {
                    return $result;
                }
            }
        }

        // If nothing else matches, try fallback routes
        if (isset($this->staticRouteMap['*'][$uri])) {
            $result = new Matched();
            $result->handler = $this->staticRouteMap['*'][$uri][0];
            $result->extraParameters = $this->staticRouteMap['*'][$uri][1];

            return $result;
        }

        if (isset($this->variableRouteData['*'])) {
            $result = $this->dispatchVariableRoute($this->variableRouteData['*'], $uri);
            if ($result !== null) {
                return $result;
            }
        }

        // Find allowed methods for this URI by matching against all other HTTP methods as well
        $allowedMethods = [];

        foreach ($this->staticRouteMap as $method => $uriMap) {
            if ($method === $httpMethod || ! isset($uriMap[$uri])) {
                continue;
            }

            $allowedMethods[] = $method;
        }

        foreach ($this->variableRouteData as $method => $routeData) {
            if ($method === $httpMethod) {
                continue;
            }

            $result = $this->dispatchVariableRoute($routeData, $uri);
            if ($result === null) {
                continue;
            }

            $allowedMethods[] = $method;
        }

        // If there are no allowed methods the route simply does not exist
        if ($allowedMethods !== []) {
            $result = new MethodNotAllowed();
            $result->allowedMethods = $allowedMethods;

            return $result;
        }

        return new NotMatched();
    }
}
