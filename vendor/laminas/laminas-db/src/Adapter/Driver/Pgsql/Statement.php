<?php

namespace Laminas\Db\Adapter\Driver\Pgsql;

use Laminas\Db\Adapter\Driver\StatementInterface;
use Laminas\Db\Adapter\Exception;
use Laminas\Db\Adapter\ParameterContainer;
use Laminas\Db\Adapter\Profiler;

use function get_resource_type;
use function is_array;
use function is_resource;
use function pg_execute;
use function pg_last_error;
use function pg_prepare;
use function preg_replace_callback;
use function sprintf;

class Statement implements StatementInterface, Profiler\ProfilerAwareInterface
{
    /** @var int */
    protected static $statementIndex = 0;

    /** @var string */
    protected $statementName = '';

    /** @var Pgsql */
    protected $driver;

    /** @var Profiler\ProfilerInterface */
    protected $profiler;

    /** @var resource */
    protected $pgsql;

    /** @var resource */
    protected $resource;

    /** @var string */
    protected $sql;

    /** @var ParameterContainer */
    protected $parameterContainer;

    /**
     * @return self Provides a fluent interface
     */
    public function setDriver(Pgsql $driver)
    {
        $this->driver = $driver;
        return $this;
    }

    /**
     * @return self Provides a fluent interface
     */
    public function setProfiler(Profiler\ProfilerInterface $profiler)
    {
        $this->profiler = $profiler;
        return $this;
    }

    /**
     * @return null|Profiler\ProfilerInterface
     */
    public function getProfiler()
    {
        return $this->profiler;
    }

    /**
     * Initialize
     *
     * @param  resource $pgsql
     * @return void
     * @throws Exception\RuntimeException For invalid or missing postgresql connection.
     */
    public function initialize($pgsql)
    {
        if (! is_resource($pgsql) || get_resource_type($pgsql) !== 'pgsql link') {
            throw new Exception\RuntimeException(sprintf(
                '%s: Invalid or missing postgresql connection; received "%s"',
                __METHOD__,
                get_resource_type($pgsql)
            ));
        }
        $this->pgsql = $pgsql;
    }

    /**
     * Get resource
     *
     * @todo Implement this method
     * phpcs:ignore Squiz.Commenting.FunctionComment.InvalidNoReturn
     * @return resource
     */
    public function getResource()
    {
    }

    /**
     * Set sql
     *
     * @param string $sql
     * @return self Provides a fluent interface
     */
    public function setSql($sql)
    {
        $this->sql = $sql;
        return $this;
    }

    /**
     * Get sql
     *
     * @return string
     */
    public function getSql()
    {
        return $this->sql;
    }

    /**
     * Set parameter container
     *
     * @return self Provides a fluent interface
     */
    public function setParameterContainer(ParameterContainer $parameterContainer)
    {
        $this->parameterContainer = $parameterContainer;
        return $this;
    }

    /**
     * Get parameter container
     *
     * @return ParameterContainer
     */
    public function getParameterContainer()
    {
        return $this->parameterContainer;
    }

    /**
     * Prepare
     *
     * @param string $sql
     */
    public function prepare($sql = null)
    {
        $sql = $sql ?: $this->sql;

        $pCount = 1;
        $sql    = preg_replace_callback(
            '#\$\##',
            function () use (&$pCount) {
                return '$' . $pCount++;
            },
            $sql
        );

        $this->sql           = $sql;
        $this->statementName = 'statement' . ++static::$statementIndex;
        $this->resource      = pg_prepare($this->pgsql, $this->statementName, $sql);
    }

    /**
     * Is prepared
     *
     * @return bool
     */
    public function isPrepared()
    {
        return isset($this->resource);
    }

    /**
     * Execute
     *
     * @param null|array|ParameterContainer $parameters
     * @throws Exception\InvalidQueryException
     * @return Result
     */
    public function execute($parameters = null)
    {
        if (! $this->isPrepared()) {
            $this->prepare();
        }

        /** START Standard ParameterContainer Merging Block */
        if (! $this->parameterContainer instanceof ParameterContainer) {
            if ($parameters instanceof ParameterContainer) {
                $this->parameterContainer = $parameters;
                $parameters               = null;
            } else {
                $this->parameterContainer = new ParameterContainer();
            }
        }

        if (is_array($parameters)) {
            $this->parameterContainer->setFromArray($parameters);
        }

        if ($this->parameterContainer->count() > 0) {
            $parameters = $this->parameterContainer->getPositionalArray();
        }
        /** END Standard ParameterContainer Merging Block */

        if ($this->profiler) {
            $this->profiler->profilerStart($this);
        }

        $resultResource = pg_execute($this->pgsql, $this->statementName, (array) $parameters);

        if ($this->profiler) {
            $this->profiler->profilerFinish();
        }

        if ($resultResource === false) {
            throw new Exception\InvalidQueryException(pg_last_error());
        }

        return $this->driver->createResult($resultResource);
    }
}
