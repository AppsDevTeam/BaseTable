<?php

namespace ADT;

abstract class BaseTable extends \Nette\Object
{
	/** @var Nette\Database\Context $connection Spojeni na databazi */
	protected $connection;
	protected $storage = NULL;
	protected $cache = NULL;

	/** @var Array */
	protected $columns = array();

	public function __construct(\Nette\Database\Context $connection, \Nette\Caching\IStorage $storage)
	{
		$this->connection = $connection;
		$this->storage = $storage;
		$this->columns = $this->getTableColumns();
	}

	/**
	 * @return \Nette\Database\Table\Selection
	 */
	protected function getTable()
	{
		return $this->connection->table($this->tableName);
	}

	public function truncate()
	{
		$this->connection->query("TRUNCATE ". $this->delimitedTableName);
	}

	public function getTableName()
	{
		$name = $this->getReflection()->getShortName();
		$name = lcfirst($name);
		$name = preg_replace('/[A-Z]/', '_${0}', $name);
		$name = strtolower($name);
		return $name;
	}

	public function getDelimitedTableName() {
		return $this->connection
			->getConnection()
			->getSupplementalDriver()
			->delimite($this->getTableName());
	}

	public function getPrimary()
	{
		return $this->getTable()->primary;
	}

	protected function getTableColumns()
	{
		return $this->getCache()->load(str_replace('\\', '-', $this->reflection->name) . '-' . __FUNCTION__, function() {
			$data = $this->connection->fetchAll("DESCRIBE ". $this->delimitedTableName);
			foreach($data as $column) {
				$columns[$column['Field']] = $column['Field'];
			}
			return $columns;
		});
	}

	/**
	 * @return \Nette\Database\Table\Selection
	 */
	public function findAll()
	{
		return $this->getTable();
	}

	/**
	 * @return \Nette\Database\Table\Selection
	 */
	public function findAllBy($condition, $parameters = array())
	{
		return $this->findAll()->where($condition, $parameters);
	}

	public function find($id)
	{
		return $this->findAllBy($this->getTableName() . '.' . $this->getPrimary(), $id);
	}

	/**
	 * @return FALSE|\Nette\Database\Table\ActiveRow
	 */
	public function findBy($condition, $parameters = array())
	{
		return $this->findAllBy($condition, $parameters)->limit(1)->fetch();
	}

	/**
	 * @param mixed Hodnota primarniho klice
	 * @return FALSE|Nette\Database\Table\ActiveRow
	 */
	public function get($id) {
		return $this->getTable()->get($id);
	}

	/**
	 * @return FALSE|\Nette\Database\Table\ActiveRow
	 */
	public function getBy($column, $value)
	{
		return $this->findAllBy($column, $value)->limit(1)->fetch();
	}

	/**
	 * Pokud je uveden neprázdný primární klíč, je proveden update, jinak insert.
	 * @return FALSE|\Nette\Database\Table\ActiveRow
	 */
	public function save($values) {
		$primary_key = $this->getTable()->primary;

		if (empty($values[$primary_key])) {
			return $this->insert($values);
		} else {
			return $this->update($values);
		}
	}

	/**
	 * @return \Nette\Database\Table\ActiveRow
	 */
	public function insert($data)
	{
		// vyfiltrujeme sloupce, ktere nejsou v tabulce
		$data = $this->filterColumns($data);

		if(empty($data[$this->getPrimary()])) {
			unset($data[$this->getPrimary()]);
		}

		return $this->getTable()->insert($data);
	}

	protected function filterColumns($data)
	{
		// vyberu pouze hodnoty ktere nejsou pole nebo objekt Nette\ArrayHash, protoze o tech vim, ze ty urcite nepotrebuju
		$values = array();
		foreach($data as $key => $value) {
			if(!($value instanceof Nette\ArrayHash) && !is_array($value)) {
				$values[$key] = $value;
			}
		}

		// udelam prunik zbylych hodnot s polem obsahujicim sloupce tabulky... u pole se sloupci musim prohodit klice a hodnoty
		return array_intersect_key($values, array_flip($this->columns));
	}

	/**
	 * @param mixed $data
	 * @return int|FALSE number of affected rows or FALSE
	 */
	public function update($data)
	{
		$primary_key = $this->getTable()->primary;

		// vyfiltrujeme sloupce, ktere nejsou v tabulce
		$data = $this->filterColumns($data);

		// zde nesmi byt getOne, protoze kdyz by bylo ID NULL, tak by se upravovala defaultni polozka
		$item = $this->getTable()->get($data[$primary_key]);
		$item->update($data);
		return $item;
	}

	public function delete($id)
	{
		return $this->getTable()->get($id)->delete();
	}

	public function rowExist($column, $value, $id = NULL)
	{
		return (bool) $this->findAll()->where($column, $value)->where('id != ?', $id ? $id : 0)->fetch();
	}

	public function rowExistExcept($column, $value, $exceptValue)
	{
		return (bool) $this->findAllBy(array(
			$column => $value,
			$column.' != ?' => $exceptValue
		))
			->limit(1)
			->fetch();
	}

	public function getPairs()
	{
		return $this->findAll()->order('name')->fetchPairs('id', 'name');
	}

	protected function getCache()
	{
		return new \Nette\Caching\Cache($this->storage);
	}

	/**
	 * Provede zadaný SQL dotaz. Přijímá nekonečně mnoho parametrů.
	 * @param string $statement SQL dotaz
	 * @param mixed [parameters, ...]
	 * @return Statement
	 */
	public function query($statement){
		$args = func_get_args();
		return $this->getConnection()->queryArgs(array_shift($args), $args);
	}

	/**
	 * Provede zadaný SQL dotaz.
	 * @param string $statement SQL dotaz
	 * @param array $params parametry
	 * @return Statement
	 */
	public function queryArgs($statement, $params){
		return $this->getConnection()->queryArgs($statement, $params);
	}
	
}
