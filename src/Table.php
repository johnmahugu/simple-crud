<?php

namespace SimpleCrud;

use ArrayAccess;

/**
 * Manages a database table.
 */
class Table implements ArrayAccess
{
    private $db;
    private $row;
    private $collection;
    private $cache = [];

    public $name;
    public $fields = [];

    /**
     * Constructor.
     *
     * @param SimpleCrud $db
     * @param string     $name
     */
    final public function __construct(SimpleCrud $db, $name)
    {
        $this->db = $db;
        $this->name = $name;

        $this->setRow(new Row($this));
        $this->setCollection(new RowCollection($this));

        $fieldFactory = $db->getFieldFactory();

        foreach (array_keys($this->getScheme()['fields']) as $name) {
            $this->fields[$name] = $fieldFactory->get($this, $name);
        }

        $this->init();
    }

    /**
     * Callback used to init the table.
     */
    protected function init()
    {
    }

    /**
     * Store a row in the cache.
     * 
     * @param int $id
     * @param Row $Row
     */
    public function cache(Row $row)
    {
        $this->cache[$row->id] = $row;
    }

    /**
     * Clear the current cache.
     */
    public function clearCache()
    {
        $this->cache = [];
    }

    /**
     * Magic method to create queries related with this table.
     *
     * @param string $name
     * @param array  $arguments
     *
     * @throws SimpleCrudException
     *
     * @return Queries\Query|null
     */
    public function __call($name, $arguments)
    {
        return $this->getDatabase()->getQueryFactory()->get($this, $name);
    }

    /**
     * Check if a row with a specific id exists.
     *
     * @see ArrayAccess
     *
     * @return bool
     */
    public function offsetExists($offset)
    {
        if (array_key_exists($offset, $this->cache)) {
            return !empty($this->cache[$offset]);
        }

        $exists = $this->count()
            ->byId($offset)
            ->limit(1)
            ->run() === 1;

        $this->cache[$offset] = $exists ? true : null;

        return $exists;
    }

    /**
     * Returns a row with a specific id.
     *
     * @see ArrayAccess
     *
     * @return Row|null
     */
    public function offsetGet($offset)
    {
        if (array_key_exists($offset, $this->cache)) {
            return $this->cache[$offset];
        }

        return $this->cache[$offset] = $this->select()
            ->one()
            ->byId($offset)
            ->run();
    }

    /**
     * Store a row with a specific id.
     *
     * @see ArrayAccess
     */
    public function offsetSet($offset, $value)
    {
        if ($offset === null) {
            $value['id'] = null;

            $this->insert()
                ->data($value)
                ->run();

            return;
        }

        if (isset($this->cache[$offset]) && is_object($this->cache[$offset])) {
            $row = $this->cache[$offset];

            foreach ($value as $name => $val) {
                $row->$name = $val;
            }

            $row->save();

            return;
        }

        if ($this->offsetExists($offset)) {
            $this->update()
                ->data($value)
                ->byId($offset)
                ->limit(1)
                ->run();

            return;
        }

        $value['id'] = $offset;

        $this->insert()
            ->data($value)
            ->run();

        $this->cache[$offset] = true;
    }

    /**
     * Remove a row with a specific id.
     *
     * @see ArrayAccess
     */
    public function offsetUnset($offset)
    {
        $this->cache[$offset] = null;

        $this->delete()
            ->byId($offset)
            ->limit(1)
            ->run();
    }

    /**
     * Returns the SimpleCrud instance associated with this table.
     *
     * @return SimpleCrud
     */
    public function getDatabase()
    {
        return $this->db;
    }

    /**
     * Returns the table scheme.
     *
     * @return array
     */
    public function getScheme()
    {
        return $this->db->getScheme()[$this->name];
    }

    /**
     * Returns an attribute.
     *
     * @param string $name
     *
     * @return null|mixed
     */
    public function getAttribute($name)
    {
        return $this->db->getAttribute($name);
    }

    /**
     * Defines the Row class used by this table.
     *
     * @param Row $row
     */
    public function setRow(Row $row)
    {
        $this->row = $row;
    }

    /**
     * Defines the RowCollection class used by this table.
     *
     * @param RowCollection $collection
     */
    public function setCollection(RowCollection $collection)
    {
        $this->collection = $collection;
    }

    /**
     * Creates a new row instance.
     *
     * @param array $data The values of the row
     *
     * @return Row
     */
    public function create(array $data = [])
    {
        if (isset($data['id']) && isset($this->cache[$data['id']]) && is_object($this->cache[$data['id']])) {
            return $this->cache[$data['id']];
        }

        $row = clone $this->row;

        foreach ($data as $name => $value) {
            $row->$name = $value;
        }

        return $row;
    }

    /**
     * Creates a new rowCollection instance.
     *
     * @param array $data Rows added to this collection
     *
     * @return RowCollection
     */
    public function createCollection(array $data = [])
    {
        $collection = clone $this->collection;

        foreach ($data as $row) {
            $collection[] = $row;
        }

        return $collection;
    }

    /**
     * Default data converter/validator from database.
     *
     * @param array $data The values before insert to database
     * @param bool  $new  True for inserts, false for updates
     */
    public function dataToDatabase(array $data, $new)
    {
        return $data;
    }

    /**
     * Default data converter from database.
     *
     * @param array $data The database format values
     */
    public function dataFromDatabase(array $data)
    {
        return $data;
    }

    /**
     * Prepares the data from the result of a database selection.
     *
     * @param array $data
     *
     * @return array
     */
    public function createFromDatabase(array $data)
    {
        //Get from cache
        if (isset($this->cache[$data['id']]) && is_object(isset($this->cache[$data['id']]))) {
            return $this->cache[$data['id']];
        }

        if (!is_array($row = $this->dataFromDatabase(array_intersect_key($data, $this->fields)))) {
            throw new SimpleCrudException('Data not valid');
        }

        foreach ($this->fields as $name => $field) {
            $row[$name] = $field->dataFromDatabase($row[$name]);
        }

        $row = $this->create($row);

        $this->cache($row);

        return $row;
    }

    /**
     * Prepares the data before save into database (used by update and insert).
     *
     * @param array $data
     * @param bool  $new
     *
     * @return array
     */
    public function prepareDataToDatabase(array $data, $new)
    {
        if (!is_array($data = $this->dataToDatabase($data, $new))) {
            throw new SimpleCrudException('Data not valid');
        }

        if (array_diff_key($data, $this->fields)) {
            throw new SimpleCrudException('Invalid fields');
        }

        //Transform data before save to database
        foreach ($data as $key => &$value) {
            $value = $this->fields[$key]->dataToDatabase($value);
        }

        return $data;
    }
}