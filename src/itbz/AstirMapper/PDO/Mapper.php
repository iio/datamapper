<?php
/**
 *
 * This file is part of the AstirMapper package
 *
 * Copyright (c) 2012 Hannes Forsgård
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @author Hannes Forsgård <hannes.forsgard@gmail.com>
 *
 * @package AstirMapper
 *
 * @subpackage PDO
 *
 */
namespace itbz\AstirMapper\PDO;
use itbz\AstirMapper\MapperInterface;
use itbz\AstirMapper\ModelInterface;
use itbz\AstirMapper\SearchInterface;
use itbz\AstirMapper\Exception\NotFoundException;
use itbz\AstirMapper\Exception;
use itbz\AstirMapper\PDO\Table\Table;


/**
 *
 * PDO mapper object
 *
 * @package AstirMapper
 *
 * @subpackage PDO
 *
 */
class Mapper implements MapperInterface
{

    /**
     *
     * Table objet to interact with
     *
     * @var Table $_table
     *
     */
    protected $_table;


    /**
     *
     * Prototype model that will be cloned on object creation
     *
     * @var ModelInterface $_prototype
     *
     */
    private $_prototype;


    /**
     *
     * Construct and inject table instance
     *
     * @param Table $table
     *
     * @param ModelInterface $prototype Prototype model that will be cloned when
     * mapper needs a new return object.
     *
     */
    public function __construct(Table $table, ModelInterface $prototype)
    {
        $this->_table = $table;
        $this->_prototype = $prototype;
    }


    /**
     *
     * Get iterator containing multiple racords based on search
     *
     * @param ModelInterface $model
     *
     * @param SearchInterface $search
     *
     * @return \Iterator
     *
     */
    public function findMany(ModelInterface $model, SearchInterface $search)
    {
        $where = $this->getExprSet($model);
        $stmt = $this->_table->select($search, $where);

        return $this->getIterator($stmt);
    }


    /**
     *
     * Find models that match current model values.
     *
     * @param ModelInterface $model
     *
     * @return ModelInterface
     *
     * @throws NotFoundException if no model was found
     *
     */
    public function find(ModelInterface $model)
    {
        $search = new Search();
        $search->setLimit(1);
        $iterator = $this->findMany($model, $search);

        // Return first object in iterator
        foreach ($iterator as $object) {
            
            return $object;
        }

        // This only happens if iterator is empty
        throw new NotFoundException("No matching records found");        
    }


    /**
     *
     * Find model based on primary key
     *
     * @param scalar $key
     *
     * @return ModelInterface
     *
     */
    public function findByPk($key)
    {
        assert('is_scalar($key)');
        $model = clone $this->_prototype;
        $data = array($this->_table->getPrimaryKey() => $key);
        $model->load($data);

        return $this->find($model);
    }


    /**
     *
     * Delete model from persistent storage
     *
     * @param ModelInterface $model
     *
     * @return int Number of affected rows
     *
     */
    public function delete(ModelInterface $model)
    {
        $pk = $this->_table->getPrimaryKey();
        $where = $this->getExprSet($model, array($pk));
        $stmt = $this->_table->delete($where);

        return $stmt->rowCount();
    }


    /**
     *
     * Persistently store model
     *
     * If model contains a primary key and that key exists in the database
     * model is updated. Else model is inserted.
     *
     * @param ModelInterface $model
     *
     * @return int Number of affected rows
     *
     */
    public function save(ModelInterface $model)
    {
        try {
            $pk = $this->getPk($model);
            if ($pk && $this->findByPk($pk)) {
                // Model has a primary key and that key exists in db

                return $this->update($model);
            }
        } catch (NotFoundException $e) {
            // Do nothing, exception triggers insert, as do models with no PK
        }

        return $this->insert($model);
    }


    /**
     *
     * Get the ID of the last inserted row.
     *
     * The return value will only be meaningful on tables with an auto-increment
     * field and with a PDO driver that supports auto-increment. Must be called
     * directly after an insert.
     *
     * @return int
     */
    public function getLastInsertId()
    {
        return $this->_table->lastInsertId();
    }


    /**
     *
     * Get primary key from model
     *
     * @param ModelInterface $model
     *
     * @return string Empty string if no key was found
     *
     */
    public function getPk(ModelInterface $model)
    {
        $pkName = $this->_table->getPrimaryKey();
        $exprSet = $this->getExprSet($model, array($pkName));
        $pk = '';
        if ($exprSet->isExpression($pkName)) {
            $pk = $exprSet->getExpression($pkName)->getValue();
        }
        
        return $pk;
    }


    /**
     *
     * Insert model into db
     *
     * @param ModelInterface $model
     *
     * @return int Number of affected rows
     *
     */
    protected function insert(ModelInterface $model)
    {
        $data = $this->getExprSet($model);
        $stmt = $this->_table->insert($data);

        return $stmt->rowCount();
    }


    /**
     *
     * Update db using primary key as where clause.
     *
     * @param ModelInterface $model
     *
     * @return int Number of affected rows
     *
     */
    protected function update(ModelInterface $model)
    {
        $data = $this->getExprSet($model);
        $pk = $this->_table->getPrimaryKey();
        $where = $this->getExprSet($model, array($pk));
        $stmt = $this->_table->update($data, $where);

        return $stmt->rowCount();
    }


    /**
     *
     * Get iterator for PDOStatement
     *
     * @param \PDOStatement $stmt
     *
     * @return \Iterator
     *
     */
    protected function getIterator(\PDOStatement $stmt)
    {
        return new Iterator(
            $stmt,
            $this->_table->getPrimaryKey(),
            $this->_prototype
        );
    }


    /**
     *
     * Convert model to ExpressionSet
     *
     * Model property names are converted to camel-case. If the requested param
     * is 'fooBar' first a method called 'getFooBar()' is looked for. If it does
     * not exist the property 'fooBar' is looked for. If property does not exist
     * it is simply skipped.
     *
     * @param ModelInterface $model
     *
     * @param array $props List of properties to read
     *
     * @return ExpressionSet
     *
     */
    protected function getExprSet(ModelInterface $model, array $props = NULL)
    {
        if (!$props) {
            $props = $this->_table->getNativeColumns();
        }

        $exprSet = new ExpressionSet();
        
        foreach ($props as $prop) {
            $method = $this->getCamelCase($prop, 'get');
            $camelParam = $this->getCamelCase($prop);

            if (method_exists($model, $method)) {
                $expr = $model->$method();
            } elseif (property_exists($model, $camelParam)) {
                $expr = $model->$camelParam;
            } else {
                continue;
            }

            if (!$expr instanceof Expression) {
                $expr = new Expression($prop, $expr);
            }
            
            if (!$expr instanceof Ignore) {
                $exprSet->addExpression($expr);
            }
        }
        
        return $exprSet;
    }


    /**
     *
     * Convert string to camel case
     *
     * Only the first letter if each word is converted. Else original casing
     * is preserved. Underscore is treated as a word delimiter.
     *
     * @param string $str
     *
     * @param string $prefix
     *
     * @return string
     *
     */
    private function getCamelCase($str, $prefix = '')
    {
        $words = explode('_', $str);
        if ($prefix) {
            array_unshift($words, $prefix);
        }
        $camel = lcfirst(array_shift($words));
        while ($word = array_shift($words)) {
            $camel .= ucfirst($word);
        }
        
        return $camel;
    }

}
