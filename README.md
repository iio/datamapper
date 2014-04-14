datamapper
==========

Implementation of the data mapper design pattern.

## The prototype design pattern and cloning

datamapper creates model instances using the prototype design pattern. When
creating your mapper you inject an empty instance of your model, the prototype.
When reading data from storage the prototype is cloned and the retrieved data
is written to the cloned model before returning.

You should note that cloning in PHP by default creates shallow copies.
This means that if your model contaions references to other objects the
referenced object is not cloned. If this is not what you want you can override
the magic method `__clone` to create deep copies.

    public function __clone()
    {
        $this->_deepCloneMe = clone $this->_deepCloneMe;
    }

For more information see the [PHP documentation](http://php.net/manual/en/language.oop5.cloning.php).


## Limitations

* The PDO subpackage can currently only handle db tables with single column
  primary keys. Eg. composite primary keys is not supported.

* The PDO subpackage currently only support naturally joined tables. Support for
  inner joins is planed.

* The PDO subpackage can only read data from joined tables, not write.


## Using the PDO subpackage

    // create PDO instance
    // $pdo = ...
    
    // create table object
    $table = new \iio\datamapper\pdo\table\Table('mytable', $pdo);

    // add a naturally joined table
    $join = new \iio\datamapper\pdo\table\Table('joinedtable', $pdo);
    $table->addNaturalJoin($join);

    // create an instance of your model
    // this instance will be cloned when reading from db
    $prototype = new MyModel();
    
    // create mapper
    $mapper = new \iio\datamapper\pdo\Mapper($table, $prototype);

    // select from db
    $iterator = $mapper->findMany(array('name' => 'foobar'));

    // iterate over results
    foreach ($iterator as $model) {
        // $model is an instance of MyModel
    }

    // alter some values
    $model->name = 'foobar';
    
    // write to database
    $mapper->save($model);


## Testing

To run the phpunit testsuite from the project root dir simply type

    $ phpunit

To test against MySQL add your server credentials to `phpunit.xml.dist`. To only test
the parts that don't require MySQL specify testpath.

    phpunit tests/pdo
