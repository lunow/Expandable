<?php
	/*
	 *	ExpandableBehavior
	 *	==================
	 *	Version 1.0.3
	 *
	 *	Expands any model with unlimited fields, without changing the database.
	 *  Creates new Keys only if debug > 2.
	 *	Need Containable and the following two tables:
	 
		CREATE TABLE IF NOT EXISTS `keys` (
		  `id` int(3) unsigned NOT NULL AUTO_INCREMENT,
		  `model` varchar(25) COLLATE utf8_bin NOT NULL,
		  `key` varchar(50) COLLATE utf8_bin NOT NULL,
		  PRIMARY KEY (`id`)
		) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COLLATE=utf8_bin;

		CREATE TABLE IF NOT EXISTS `values` (
		  `id` int(6) unsigned NOT NULL AUTO_INCREMENT,
		  `key_id` int(3) NOT NULL,
		  `model_id` int(6) NOT NULL,
		  `value` text COLLATE utf8_bin NOT NULL,
		  PRIMARY KEY (`id`)
		) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COLLATE=utf8_bin;
		
	 *  Add this two your model:
		var $actsAs = array('Containable', 'Expandable');
	 *
	 *  Thats all!! Every field would be saved, input like that:
	    $this->Form->input('what_ever');
	 *	Read all Keys from Controller with
	  	$this->ModelAlias->getKeys();
	 *
	 *	(c) 2011 by Paul Lunow
	 *		paul@apeunit.com
	 *		www.apeunit.com
	 */

	class ExpandableBehavior extends ModelBehavior {
		var $Model;
		var $keys;
		var $keyStore = array();
		
		/*
		 *	Setup up the behavior, build the associations to keys and values on the fly
		 */
		function setup(&$Model, $settings) {
			//CakeLog::write('debug', 'Setup Model '.$Model->alias.' ...');
			//one Model->alias has many values
			$Model->bindModel(array(
				'hasMany' => array(
					'Value' => array(
						'className' => 'Value',
						'foreignKey' => 'model_id',
					),
				),
			));
			//one value has one key
			$Model->Value->bindModel(array(
				'belongsTo' => array(
					'Key' => array(
						'className' => 'Key',
						'conditions' => array('Key.model' => $Model->alias),
					),
				),
			));
			//save the model for later use
			$this->Model = $Model;
			//get all related keys for this Model->alias
			$this->keys = $this->getKeys();
		}
		
		/*
		 * search for fields, thats not present in schema
		 */
		function beforeSave($Model) {
			$schema = array_keys($this->Model->_schema);
			if(isset($this->Model->data[$this->Model->alias])) {
				foreach($this->Model->data[$this->Model->alias] as $key => $value) {
					if(!in_array($key, $schema)) {
						$this->_expand($key, $value);
					}
				}
			}
			return parent::beforeSave($Model);
		}
		
		/*
		 * Save the expanded fields with model relation
		 */
		function afterSave($Model, $created) {
			if(count($this->keyStore) > 0) {
				$this->_saveKeys();
			}
			parent::afterSave($Model, $created);
		}
		
		/*
		 * Every call have to contain the related values
		 * 
		 * TODO: This function didnt work. Value is not contained
		 *
		function beforeFind(&$Model, $query) {
			if(isset($query['contain'])) {
				array_push($query['contain'], 'Value');
			}
			else {
				$query['contain'] = array('Value');
			}
			return $query;
		}
		
		/*
		 * Normalize the array 
		 */
		function afterFind($Model, $results, $primary) {
			if(count($results) > 0) {
				foreach($results as &$result) {
					if(isset($result['Value']) && is_array($result['Value']) && count($result['Value']) > 0) {
						foreach($result['Value'] as $value) {
							//set the value from value table
							if(isset($this->keys[$value['key_id']])) {
								$result[$Model->alias][$this->keys[$value['key_id']]] = $value['value'];
							}
						}
						unset($result['Value']);
					}
					//set empty values for all values, not found in this model
					$result[$Model->alias] = Set::merge(Set::normalize($this->keys), $result[$Model->alias]);
				}
			}
			return $results;
		}
		
		/*
		 * Returns the expanded keys for model as array
		 */
		function getKeys() {
			if(empty($this->keys)) {
				$this->keys = $this->Model->Value->Key->find('list', array(
					'fields' => array('Key.id', 'Key.key'),
					'conditions' => array('Key.model' => $this->Model->alias)
				));
			}
			return $this->keys;
		}
		
		/*
		 * Returns the id for an given key
		 */
		function _getKeyId($needle) {
			foreach($this->keys as $id => $key) {
				if($key == $needle) {
					return $id;
				}
			}
			return false;
		}
		
		/*
		 * Checks if the key is present, otherwise save the key for afterFind
		 * expand only if debug > 0
		 */
		function _expand($key, $value) {
			if(!in_array($key, $this->keys) && Configure::read('debug') > 0) {
				$this->_createKey($key);
			}
			array_push($this->keyStore, array('key' => $key, 'value' => $value));
		}
		
		/*
		 * Creates the given key in db
		 */
		function _createKey($key) {
			$data = array(
				'Key' => array(
					'model' => $this->Model->alias,
					'key' => $key
				),
			);
			$this->Model->Value->Key->create();
			if($this->Model->Value->Key->save($data)) {
				$this->keys[$this->Model->Value->Key->id] = $key;
				return true;
			}
			return false;
		}
		
		/*
		 * Save all values
		 */
		function _saveKeys() {
			$data = array();
			foreach($this->keyStore as $key) {
				$data[] = array(
					'Value' => array(
						'key_id' => $this->_getKeyId($key['key']),
						'value' => $key['value'],
						'model_id' => $this->Model->id
					),
				);
			}
			 return $this->Model->Value->saveAll($data);
		}
	}